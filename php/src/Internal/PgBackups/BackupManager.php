<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

	http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Internal\PgBackups;

use Blnk\Config\Configuration;
use Blnk\Internal\Log;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\StreamInterface;

/**
 * BackupManager manages database backups, supporting both local disk and S3 storage.
 *
 * Port of internal/pg-backups/pg-backup-drive.go. The local-disk path shells
 * out to `pg_dump` exactly like Go (same directory layout
 * `<backup_dir>/<YYYY-MM-DD>/<db>-<HHMMSS>.sql`, same PGHOST/PGPORT/PGUSER/
 * PGPASSWORD environment, same error surface).
 *
 * S3 divergence (documented, per PORTING.md): Go uses the full AWS SDK
 * (aws-sdk-go session + s3.PutObjectWithContext). The PHP port instead issues
 * a single AWS Signature V4 signed `PUT /<bucket>/<key>` over Guzzle using the
 * same configuration fields (aws_access_key_id, aws_secret_access_key,
 * s3_endpoint, s3_region, s3_bucket_name) with path-style addressing forced —
 * matching the Go S3ForcePathStyle(true) — and plaintext HTTP only when the
 * endpoint explicitly starts with "http://" (matching the Go DisableSSL
 * derivation). Multipart uploads, retries and the other SDK niceties are NOT
 * implemented; a backup larger than the single-PUT S3 limit (5 GiB) would need
 * the SDK's multipart flow.
 */
final class BackupManager
{
    /** Configuration settings for backup operations (Blnk\Config\Configuration). */
    public object $config;

    private ClientInterface $httpClient;

    /**
     * newBackupManager initializes a BackupManager with configurations for
     * local and S3 storage.
     *
     * @throws BackupException if configuration fetching fails
     */
    public function __construct(?ClientInterface $httpClient = null)
    {
        // Fetch configuration from the environment or configuration files.
        try {
            $this->config = Configuration::fetch();
        } catch (\Throwable $e) {
            throw new BackupException('failed to fetch config: ' . $e->getMessage(), 0, $e);
        }

        $this->httpClient = $httpClient ?? new GuzzleClient();
    }

    /**
     * backupToDisk creates a backup of the PostgreSQL database and stores it locally on disk.
     * It creates a directory based on the current date and stores the backup as a SQL file.
     *
     * Returns the path to the backup file.
     *
     * @throws BackupException if the backup process fails
     */
    public function backupToDisk(): string
    {
        Log::get()->info('Starting database backup to disk');

        $dns = (string) $this->config->dataSource->dns;

        // Parse the DSN to retrieve individual database components like host, port, user, etc.
        $parts = self::parseDatabaseUrl($dns);

        // Ensure the database connection is working (Go: sql.Open + PingContext).
        try {
            $pdoDsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'], $parts['port'], $parts['dbname']);
            if ($parts['sslmode'] !== '') {
                $pdoDsn .= ';sslmode=' . $parts['sslmode'];
            }
            $pdo = new \PDO($pdoDsn, $parts['user'], $parts['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            unset($pdo);
        } catch (\Throwable $e) {
            throw new BackupException('failed to ping database: ' . $e->getMessage(), 0, $e);
        }

        // Define the backup directory using the current date to create a unique directory.
        $backupDir = rtrim((string) $this->config->backupDir, '/') . '/' . date('Y-m-d');
        Log::get()->info(sprintf('Backup directory: %s', $backupDir));

        // Create the backup directory if it doesn't exist.
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0o777, true) && !is_dir($backupDir)) {
            throw new BackupException('failed to create backup directory');
        }

        // Define the backup file name using the database name and current time.
        $backupFile = $backupDir . '/' . sprintf('%s-%s.sql', $parts['dbname'], date('His'));
        Log::get()->info(sprintf('Backup file path: %s', $backupFile));

        // Execute the pg_dump command to create a database backup.
        $cmd = ['pg_dump', '-U', $parts['user'], '-d', $parts['dbname'], '-f', $backupFile];
        $env = array_merge(self::currentEnv(), [
            'PGHOST' => $parts['host'],
            'PGPORT' => $parts['port'],
            'PGUSER' => $parts['user'],
            'PGPASSWORD' => $parts['password'],
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new BackupException('pg_dump failed: could not start pg_dump');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        // Capture any error output from the pg_dump command.
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new BackupException(sprintf('pg_dump failed: %s: exit status %d', $stderr, $exitCode));
        }

        Log::get()->info(sprintf('Database backup completed: %s', $backupFile));

        // Ensure that the backup file was created successfully.
        if (!file_exists($backupFile)) {
            throw new BackupException('backup file does not exist after pg_dump');
        }

        return $backupFile;
    }

    /**
     * backupToS3 performs a database backup and uploads the resulting file to an S3 bucket.
     * It first calls backupToDisk to create a local backup, then uploads the file to S3
     * via an AWS SigV4 signed PUT (see the class doc for the SDK divergence).
     *
     * @throws BackupException if the backup process or upload to S3 fails
     */
    public function backupToS3(): void
    {
        Log::get()->info('Starting backup to S3');

        // Perform the backup to disk and get the file path.
        try {
            $filePath = $this->backupToDisk();
        } catch (\Throwable $e) {
            throw new BackupException('failed to backup to disk: ' . $e->getMessage(), 0, $e);
        }

        // Open the backup file for reading.
        $file = @fopen($filePath, 'rb');
        if ($file === false) {
            throw new BackupException('failed to open file for S3 upload');
        }

        try {
            $filename = basename($filePath); // Get the filename from the full path

            $this->putObject((string) $this->config->s3BucketName, $filename, $filePath, $file);
        } catch (BackupException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BackupException('failed to upload to S3: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }

        Log::get()->info(sprintf('Backup uploaded to S3: %s', $filename));
    }

    /**
     * putObject uploads a file to S3 with an AWS Signature Version 4 signed
     * PUT request (path-style addressing), streaming the file body.
     *
     * @param resource $file open handle on the file, positioned at the start
     *
     * @throws BackupException
     */
    private function putObject(string $bucket, string $key, string $filePath, $file): void
    {
        $endpoint = (string) $this->config->s3Endpoint;
        $region = (string) $this->config->s3Region;
        if ($region === '') {
            $region = 'us-east-1';
        }
        $accessKey = (string) $this->config->awsAccessKeyId;
        $secretKey = (string) $this->config->awsSecretAccessKey;

        // Use plaintext HTTP only when the endpoint explicitly asks for it (e.g. a
        // local MinIO in development); default to TLS for real S3 and https endpoints.
        $baseUrl = self::s3BaseUrl($endpoint, $region);

        $parsed = parse_url($baseUrl);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new BackupException('failed to upload to S3: invalid S3 endpoint ' . $endpoint);
        }
        $host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');

        // Path-style addressing (Go: S3ForcePathStyle(true)).
        $canonicalUri = '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($key));
        $url = $baseUrl . $canonicalUri;

        $payloadHash = hash_file('sha256', $filePath);
        if ($payloadHash === false) {
            throw new BackupException('failed to upload to S3: could not hash backup file');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $dateStamp = $now->format('Ymd');

        // --- AWS Signature Version 4 ---
        $canonicalHeaders = 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            'PUT',
            $canonicalUri,
            '', // canonical query string
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $accessKey,
            $credentialScope,
            $signedHeaders,
            $signature
        );

        try {
            $resp = $this->httpClient->request('PUT', $url, [
                'headers' => [
                    'Host' => $host,
                    'Authorization' => $authorization,
                    'x-amz-content-sha256' => $payloadHash,
                    'x-amz-date' => $amzDate,
                    'Content-Type' => 'application/octet-stream',
                ],
                // Stream the file straight to S3 rather than buffering the whole
                // backup in memory (Go passes the *os.File to the SDK).
                'body' => $file,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            throw new BackupException('failed to upload to S3: ' . $e->getMessage(), 0, $e);
        }

        $status = $resp->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $body = $resp->getBody() instanceof StreamInterface ? (string) $resp->getBody() : '';
            throw new BackupException(sprintf('failed to upload to S3: status %d: %s', $status, $body));
        }
    }

    /**
     * Resolves the S3 base URL from the configured endpoint, defaulting to the
     * regional AWS endpoint when none is configured (as the AWS SDK does).
     */
    private static function s3BaseUrl(string $endpoint, string $region): string
    {
        if ($endpoint === '') {
            return sprintf('https://s3.%s.amazonaws.com', $region);
        }
        if (str_starts_with(strtolower($endpoint), 'http://') || str_starts_with(strtolower($endpoint), 'https://')) {
            return rtrim($endpoint, '/');
        }

        return 'https://' . rtrim($endpoint, '/');
    }

    /**
     * Parses a postgres:// DSN into its components, mirroring the Go
     * url.Parse + net.SplitHostPort extraction (a missing port is an error).
     *
     * @return array{user: string, password: string, host: string, port: string, dbname: string, sslmode: string}
     *
     * @throws BackupException
     */
    private static function parseDatabaseUrl(string $dns): array
    {
        $parsed = parse_url($dns);
        if ($parsed === false) {
            throw new BackupException('failed to parse database URL');
        }

        $user = rawurldecode((string) ($parsed['user'] ?? ''));       // Extract the database user
        $password = rawurldecode((string) ($parsed['pass'] ?? ''));   // Extract the database password (if provided)
        $host = (string) ($parsed['host'] ?? '');
        if ($host === '' || !isset($parsed['port'])) {
            // Go: net.SplitHostPort errors when no port is present.
            throw new BackupException('failed to split host and port: missing port in address');
        }
        $port = (string) $parsed['port'];
        $path = (string) ($parsed['path'] ?? '');
        $dbname = rawurldecode(ltrim($path, '/')); // Remove leading '/' from the database name

        $sslmode = '';
        if (isset($parsed['query'])) {
            parse_str((string) $parsed['query'], $query);
            if (isset($query['sslmode']) && is_string($query['sslmode'])) {
                $sslmode = $query['sslmode'];
            }
        }

        return [
            'user' => $user,
            'password' => $password,
            'host' => $host,
            'port' => $port,
            'dbname' => $dbname,
            'sslmode' => $sslmode,
        ];
    }

    /**
     * Snapshot of the current process environment (Go: os.Environ()).
     *
     * @return array<string, string>
     */
    private static function currentEnv(): array
    {
        $env = getenv();

        return is_array($env) ? $env : [];
    }
}
