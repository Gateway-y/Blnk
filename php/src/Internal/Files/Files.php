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

namespace Blnk\Internal\Files;

use Blnk\Internal\Log;
use Blnk\Model\ExternalTransaction;
use Psr\Http\Message\StreamInterface;

/**
 * Port of internal/files/files.go: upload handling for external (reconciliation)
 * data — file type detection, CSV/JSON parsing, and batched storage through a
 * caller-provided store callback.
 *
 * The StoreFunc Go type `func(ctx, uploadID string, txn model.ExternalTransaction) error`
 * becomes `callable(string $uploadID, ExternalTransaction $txn): void`, throwing
 * on error.
 *
 * Concurrency divergence (documented, per PORTING.md "Concurrency"): Go
 * processes CSV rows with a worker pool (up to DefaultWorkerCount goroutines)
 * feeding a channel; the PHP port processes rows sequentially while keeping the
 * same batch size, the same per-row error collection (parse and store errors
 * are collected, not fatal) and the same combined error message. Go's context
 * cancellation checks have no PHP equivalent and are dropped.
 *
 * File-type detection divergence (documented): Go uses mime.TypeByExtension and
 * http.DetectContentType; PHP has neither, so detectByExtension uses a small
 * built-in extension map covering the types Blnk accepts and detectByContent
 * uses ext-fileinfo (when available) with the same normalization switch. The
 * AnalyzeTextContent CSV/JSON sniffing fallback is ported verbatim, so CSV and
 * JSON uploads resolve to the same MIME results.
 */
final class Files
{
    // Configuration constants for performance tuning
    public const DefaultBatchSize = 500;
    public const DefaultWorkerCount = 4;
    public const DefaultBufferSize = 64 * 1024; // 64KB buffer
    public const DefaultChannelSize = 1000;
    public const ContextCheckInterval = 100;

    /**
     * uploadExternalData handles the process of uploading external data by
     * detecting file type, parsing, and storing it.
     *
     * Parameters:
     * - $source: The source of the external data.
     * - $reader: The uploaded data — a PHP stream resource, a PSR-7 stream, or
     *   a raw string of bytes (Go: io.Reader).
     * - $filename: The name of the file being uploaded.
     * - $store: The callback function to store processed transactions
     *   (callable(string $uploadID, ExternalTransaction $txn): void).
     *
     * Returns [uploadID, total records processed] (Go returns (string, int, error)).
     *
     * @param resource|StreamInterface|string $reader
     * @param callable(string, ExternalTransaction): void $store
     *
     * @return array{0: string, 1: int}
     *
     * @throws FilesException if any step of the process fails
     */
    public static function uploadExternalData(string $source, mixed $reader, string $filename, callable $store): array
    {
        $uploadID = \Blnk\Model\ModelHelpers::generateUUIDWithSuffix('upload');

        $tempFile = self::createAndPopulateTempFile($filename, $reader);
        try {
            $fileType = self::detectFileTypeFromTempFile($tempFile, $filename);

            $total = self::parseAndStoreData($uploadID, $source, $tempFile, $fileType, $store);

            return [$uploadID, $total];
        } finally {
            self::cleanupTempFile($tempFile);
        }
    }

    /**
     * Copies the upload into a seekable temp file (Go: createAndPopulateTempFile).
     *
     * @param resource|StreamInterface|string $reader
     *
     * @return resource an open, rewound handle on the temp file
     *
     * @throws FilesException
     */
    private static function createAndPopulateTempFile(string $filename, mixed $reader)
    {
        $tempFile = self::createTempFile($filename);

        try {
            if ($reader instanceof StreamInterface) {
                while (!$reader->eof()) {
                    $chunk = $reader->read(self::DefaultBufferSize);
                    if ($chunk === '') {
                        break;
                    }
                    if (fwrite($tempFile, $chunk) === false) {
                        throw new FilesException('error copying upload data');
                    }
                }
            } elseif (is_resource($reader)) {
                if (stream_copy_to_stream($reader, $tempFile) === false) {
                    throw new FilesException('error copying upload data');
                }
            } elseif (is_string($reader)) {
                if (fwrite($tempFile, $reader) === false) {
                    throw new FilesException('error copying upload data');
                }
            } else {
                throw new FilesException('error copying upload data: unsupported reader type ' . get_debug_type($reader));
            }
        } catch (\Throwable $e) {
            self::cleanupTempFile($tempFile);
            throw $e instanceof FilesException ? $e : new FilesException('error copying upload data: ' . $e->getMessage(), 0, $e);
        }

        if (fseek($tempFile, 0) !== 0) {
            self::cleanupTempFile($tempFile);
            throw new FilesException('error seeking temporary file');
        }

        return $tempFile;
    }

    /**
     * @param resource $tempFile
     *
     * @throws FilesException
     */
    private static function detectFileTypeFromTempFile($tempFile, string $filename): string
    {
        $header = fread($tempFile, 512);
        if ($header === false) {
            throw new FilesException('error reading file header');
        }

        try {
            $fileType = self::detectFileType($header, $filename);
        } catch (\Throwable $e) {
            throw new FilesException('error detecting file type: ' . $e->getMessage(), 0, $e);
        }

        if (fseek($tempFile, 0) !== 0) {
            throw new FilesException('error seeking temporary file');
        }

        return $fileType;
    }

    /**
     * @param resource $reader
     * @param callable(string, ExternalTransaction): void $store
     *
     * @throws FilesException
     */
    private static function parseAndStoreData(string $uploadID, string $source, $reader, string $fileType, callable $store): int
    {
        switch ($fileType) {
            case 'text/csv':
            case 'text/csv; charset=utf-8':
                return self::processCSV($uploadID, $source, $reader, $store);
            case 'application/json':
                return self::processJSON($uploadID, $source, $reader, $store);
            default:
                throw new FilesException(sprintf('unsupported file type: %s', $fileType));
        }
    }

    /**
     * @return resource
     *
     * @throws FilesException
     */
    private static function createTempFile(string $originalFilename)
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blnk_uploads';
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0o755, true) && !is_dir($tempDir)) {
            throw new FilesException('error creating temporary directory');
        }

        $prefix = sprintf('%s_', basename($originalFilename));
        $path = @tempnam($tempDir, $prefix);
        if ($path === false) {
            throw new FilesException('error creating temporary file');
        }
        $handle = @fopen($path, 'w+b');
        if ($handle === false) {
            @unlink($path);
            throw new FilesException('error creating temporary file');
        }

        return $handle;
    }

    /**
     * @param resource|null $file
     */
    private static function cleanupTempFile($file): void
    {
        if (is_resource($file)) {
            $meta = stream_get_meta_data($file);
            $filename = $meta['uri'] ?? '';
            if (@fclose($file) === false) {
                Log::get()->error(sprintf('Error closing temporary file %s', $filename));
            }
            if ($filename !== '' && @unlink($filename) === false) {
                Log::get()->error(sprintf('Error removing temporary file %s', $filename));
            }
        }
    }

    /**
     * @throws FilesException
     */
    public static function detectFileType(string $data, string $filename): string
    {
        $mimeType = self::detectByExtension($filename);
        if ($mimeType !== '') {
            return $mimeType;
        }

        return self::detectByContent($data);
    }

    /**
     * detectByExtension resolves a MIME type from the filename extension
     * (Go: mime.TypeByExtension). PHP has no built-in equivalent, so a small
     * map covers the extensions relevant to uploads; unknown extensions return
     * '' and fall through to content detection, as in Go.
     */
    public static function detectByExtension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'csv':
                return 'text/csv; charset=utf-8';
            case 'json':
                return 'application/json';
            case 'txt':
                return 'text/plain; charset=utf-8';
            case 'xml':
                return 'text/xml; charset=utf-8';
            case 'pdf':
                return 'application/pdf';
            case 'html':
            case 'htm':
                return 'text/html; charset=utf-8';
            default:
                return '';
        }
    }

    /**
     * detectByContent sniffs the MIME type from the leading bytes
     * (Go: http.DetectContentType, approximated with ext-fileinfo).
     *
     * @throws FilesException
     */
    public static function detectByContent(string $data): string
    {
        $mimeType = 'application/octet-stream';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($data);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        }

        switch ($mimeType) {
            case 'application/octet-stream':
            case 'text/plain':
                return self::analyzeTextContent($data);
            case 'text/csv; charset=utf-8':
            case 'text/csv':
                return 'text/csv';
            default:
                return $mimeType;
        }
    }

    public static function analyzeTextContent(string $data): string
    {
        if (self::looksLikeCSV($data)) {
            return 'text/csv';
        }
        json_decode($data);
        if (json_last_error() === JSON_ERROR_NONE) {
            return 'application/json';
        }

        return 'text/plain';
    }

    public static function looksLikeCSV(string $data): bool
    {
        $lines = explode("\n", $data);
        if (count($lines) < 2) {
            return false;
        }

        $fields = substr_count($lines[0], ',') + 1;
        // Only check first few lines for performance
        $maxLinesToCheck = 5;
        if (count($lines) < $maxLinesToCheck) {
            $maxLinesToCheck = count($lines);
        }

        for ($i = 1; $i < $maxLinesToCheck; $i++) {
            $line = $lines[$i];
            if ($line === '') {
                continue;
            }
            if (substr_count($line, ',') + 1 !== $fields) {
                return false;
            }
        }

        return $fields > 1;
    }

    /**
     * processCSV parses CSV data and stores the resulting external transactions.
     * (Go's parallel worker pool is replaced by a sequential loop — see the
     * class doc.)
     *
     * @param resource $reader
     * @param callable(string, ExternalTransaction): void $store
     *
     * @throws FilesException
     */
    public static function processCSV(string $uploadID, string $source, $reader, callable $store): int
    {
        $headers = fgetcsv($reader, 0, ',', '"', '\\');
        if ($headers === false || $headers === [null]) {
            throw new FilesException('error reading CSV headers');
        }

        $columnMap = self::createColumnMap(array_map(static fn ($h): string => (string) $h, $headers));

        return self::processCSVRows($uploadID, $source, $reader, $columnMap, $store);
    }

    /**
     * Sequential replacement for Go's processCSVRowsParallel: same batching,
     * same error collection semantics, same combined error message.
     *
     * @param resource $reader
     * @param array<string, int> $columnMap
     * @param callable(string, ExternalTransaction): void $store
     *
     * @throws FilesException
     */
    private static function processCSVRows(string $uploadID, string $source, $reader, array $columnMap, callable $store): int
    {
        $errs = [];
        $batch = [];
        $totalCount = 0;

        $flushBatch = static function (array &$batch) use ($uploadID, $store, &$totalCount, &$errs): void {
            if (count($batch) === 0) {
                return;
            }
            try {
                self::storeBatch($uploadID, $batch, $store);
                $totalCount += count($batch);
            } catch (\Throwable $e) {
                $errs[] = $e->getMessage();
            }
            $batch = [];
        };

        while (($record = fgetcsv($reader, 0, ',', '"', '\\')) !== false) {
            // fgetcsv yields [null] for blank lines; Go's csv.Reader skips them.
            if ($record === [null]) {
                continue;
            }

            try {
                $externalTxn = self::parseExternalTransaction(
                    array_map(static fn ($v): string => (string) $v, $record),
                    $columnMap,
                    $source
                );
            } catch (\Throwable $e) {
                $errs[] = $e->getMessage();
                continue;
            }

            $batch[] = $externalTxn;

            // Process batch when full
            if (count($batch) >= self::DefaultBatchSize) {
                $flushBatch($batch);
            }
        }

        // Process remaining items in batch
        $flushBatch($batch);

        if (count($errs) > 0) {
            throw new FilesException(sprintf(
                'encountered %d errors while processing CSV: [%s]',
                count($errs),
                implode(' ', $errs)
            ));
        }

        return $totalCount;
    }

    /**
     * @param ExternalTransaction[] $batch
     * @param callable(string, ExternalTransaction): void $store
     */
    private static function storeBatch(string $uploadID, array $batch, callable $store): void
    {
        foreach ($batch as $txn) {
            $store($uploadID, $txn);
        }
    }

    /**
     * @param string[] $headers
     *
     * @return array<string, int>
     *
     * @throws FilesException
     */
    private static function createColumnMap(array $headers): array
    {
        $requiredColumns = ['ID', 'Amount', 'Date'];
        $columnMap = [];

        foreach ($headers as $i => $header) {
            $normalized = strtolower(trim($header));
            $columnMap[$normalized] = $i;
        }

        foreach ($requiredColumns as $col) {
            if (!array_key_exists(strtolower($col), $columnMap)) {
                throw new FilesException(sprintf("required column '%s' not found in CSV", $col));
            }
        }

        return $columnMap;
    }

    /**
     * @param string[] $record
     * @param array<string, int> $columnMap
     *
     * @throws FilesException
     */
    private static function parseExternalTransaction(array $record, array $columnMap, string $source): ExternalTransaction
    {
        if (count($record) !== count($columnMap)) {
            throw new FilesException('incorrect number of fields in record');
        }

        $id = self::getRequiredField($record, $columnMap, 'id');
        $amountStr = self::getRequiredField($record, $columnMap, 'amount');
        $currency = self::getRequiredField($record, $columnMap, 'currency');

        $amount = self::parseFloat($amountStr);

        $reference = self::getRequiredField($record, $columnMap, 'reference');
        $description = self::getRequiredField($record, $columnMap, 'description');

        $dateStr = self::getRequiredField($record, $columnMap, 'date');
        $date = self::parseTime($dateStr);

        return ExternalTransaction::fromArray([
            'id' => $id,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
            'description' => $description,
            'date' => $date,
            'source' => $source,
        ]);
    }

    /**
     * @param string[] $record
     * @param array<string, int> $columnMap
     *
     * @throws FilesException
     */
    private static function getRequiredField(array $record, array $columnMap, string $field): string
    {
        if (array_key_exists($field, $columnMap) && $columnMap[$field] < count($record)) {
            $value = trim($record[$columnMap[$field]]);
            if ($value === '') {
                throw new FilesException(sprintf("required field '%s' is empty", $field));
            }

            return $value;
        }
        throw new FilesException(sprintf("required field '%s' not found in record", $field));
    }

    /**
     * processJSON parses a JSON array of external transactions, decoding and
     * storing one element at a time.
     *
     * Divergence (documented): the Go implementation streams tokens with
     * json.Decoder so memory stays constant; PHP has no streaming JSON decoder
     * in the standard library, so the payload is read fully and decoded once —
     * elements are still validated and stored one at a time in order, with the
     * same error semantics (the first store/decode failure aborts, returning
     * the count stored so far via the exception).
     *
     * @param resource $reader
     * @param callable(string, ExternalTransaction): void $store
     *
     * @throws FilesException
     */
    public static function processJSON(string $uploadID, string $source, $reader, callable $store): int
    {
        $contents = stream_get_contents($reader);
        if ($contents === false) {
            throw new FilesException('error reading JSON upload');
        }

        $decoded = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new FilesException(json_last_error_msg());
        }
        // Expect the payload to be a JSON array.
        if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
            throw new FilesException('expected a JSON array of transactions');
        }

        $total = 0;
        foreach ($decoded as $element) {
            if (!is_array($element)) {
                throw new FilesException('expected a JSON array of transactions');
            }
            $txn = ExternalTransaction::fromArray($element);
            $txn->source = $source;
            $store($uploadID, $txn);
            $total++;
        }

        return $total;
    }

    /**
     * Go: strconv.ParseFloat, returning 0 on failure.
     */
    private static function parseFloat(string $s): float
    {
        if (trim($s) === $s && is_numeric($s)) {
            return (float) $s;
        }

        return 0.0;
    }

    /**
     * Go: time.Parse(time.RFC3339, s), returning the zero time on failure.
     * The zero value is carried as Go's zero time in RFC3339 form so
     * ExternalTransaction::fromArray materializes the same timestamp.
     */
    private static function parseTime(string $s): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $s, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        $ok = $parsed !== false
            && !($errors !== false && ((($errors['warning_count'] ?? 0) > 0) || (($errors['error_count'] ?? 0) > 0)));
        if (!$ok) {
            return '0001-01-01T00:00:00Z';
        }

        return $parsed->format(\DateTimeInterface::RFC3339);
    }
}
