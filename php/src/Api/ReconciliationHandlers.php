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

namespace Blnk\Api;

use Blnk\Api\Model\JsonBinding;
use Blnk\Api\Model\ModelHelpers as ApiModelHelpers;
use Blnk\Config\Configuration;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Log;
use Blnk\Model\ExternalTransaction;
use Blnk\Model\MatchingRule;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Port of api/reconciliation_api.go: the reconciliation handlers of the Go
 * `Api` struct, composed into {@see Api} (which holds the service as
 * `$this->blnk`).
 *
 * Multipart uploads: Gin's `c.PostForm` / `c.Request.FormFile` map to the
 * PSR-7 parsed body and uploaded files; `http.MaxBytesReader` (which bounds
 * the whole multipart body) is approximated by checking the multipart
 * request's Content-Length, the uploaded file's size and PHP's own
 * upload-size errors against Server.MaxUploadSizeMB.
 *
 * URL uploads: Go's `http.Client` with a redirect re-validating
 * `CheckRedirect` becomes a Guzzle client with redirects disabled and a
 * manual redirect loop (a redirect to a non-whitelisted host stops at the
 * 3xx response, as `http.ErrUseLastResponse` does); `io.LimitReader` becomes
 * a bounded copy into a php://temp stream.
 *
 * The anonymous request structs of StartReconciliation and
 * InstantReconciliation are bound inline with {@see JsonBinding}, including
 * their `binding:"required"` tags.
 *
 * `gin.H` maps marshal with sorted keys in Go; the literal payloads below
 * are written in that order.
 */
trait ReconciliationHandlers
{
    /**
     * reconciliationPostForm is `c.PostForm(key)`: the first value of a form
     * field from a multipart or urlencoded body ("" when absent).
     */
    private static function reconciliationPostForm(ServerRequestInterface $request, string $key): string
    {
        $parsed = $request->getParsedBody();
        if (\is_object($parsed)) {
            $parsed = (array) $parsed;
        }
        if (!\is_array($parsed)) {
            $parsed = [];
            $ctype = strtolower($request->getHeaderLine('Content-Type'));
            if (str_starts_with($ctype, 'application/x-www-form-urlencoded')) {
                parse_str(Binding::rawBody($request), $parsed);
            }
        }

        $value = $parsed[$key] ?? '';
        if (\is_array($value)) {
            $value = reset($value);
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * UploadExternalData handles the upload of external transaction data.
     * It receives the file and source details from the request, processes the upload,
     * and returns an upload ID along with the record count and source information.
     *
     * Responses:
     * - 400 Bad Request: If the file upload fails.
     * - 500 Internal Server Error: If there is an error processing the upload.
     * - 200 OK: If the upload is successful.
     */
    public function uploadExternalData(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Bound the request body so an oversized upload can't exhaust disk/memory.
        $maxBytes = 0;
        try {
            $cfg = Configuration::fetch();
            if ($cfg->server->maxUploadSizeMB > 0) {
                $maxBytes = $cfg->server->maxUploadSizeMB * 1024 * 1024;
            }
        } catch (\Throwable) {
            // config.Fetch() failed: Go leaves the body unbounded.
        }

        $source = self::reconciliationPostForm($request, 'source');
        $file = $request->getUploadedFiles()['file'] ?? null;
        $isMultipart = str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'multipart/form-data');

        // http.MaxBytesReader surfaces oversized multipart bodies as a FormFile error.
        if ($maxBytes > 0 && $isMultipart) {
            $contentLength = (int) $request->getHeaderLine('Content-Length');
            $fileTooLarge = $file instanceof UploadedFileInterface && (
                $file->getError() === UPLOAD_ERR_INI_SIZE
                || $file->getError() === UPLOAD_ERR_FORM_SIZE
                || ($file->getSize() ?? 0) > $maxBytes
            );
            if ($contentLength > $maxBytes || $fileTooLarge) {
                return Errors::respondCode($response, ErrorCode::ErrGenPayloadTooLarge, 'upload exceeds the maximum allowed size', null);
            }
        }

        if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
            // Multipart file upload path (existing behaviour). `file` wins when
            // both `file` and `url` are present, preserving backward compatibility.
            $fileName = (string) ($file->getClientFilename() ?? '');

            try {
                [$uploadID, $total] = $this->blnk->uploadExternalData($source, $file->getStream(), $fileName);
            } catch (\Throwable $err) {
                Log::get()->error(Errors::errorString($err));

                return Errors::respondCode($response, ErrorCode::ErrReconUploadProcessingFailed, 'Failed to process upload', null);
            }

            return Json::write($response, 200, ['record_count' => $total, 'source' => $source, 'upload_id' => $uploadID]);
        }

        // No `file` part. Fall back to the URL download path if a `url` was supplied
        // via a form field or a JSON body. If neither is present, return the legacy
        // 400 "File upload failed" so existing clients see unchanged behaviour.
        [$rawURL, $jsonSource] = self::resolveUploadURL($request);
        if ($jsonSource !== '' && $source === '') {
            $source = $jsonSource;
        }
        if ($rawURL === '') {
            return Errors::respondCode($response, ErrorCode::ErrReconUploadFailed, 'File upload failed', null);
        }

        $result = $this->downloadAndUpload($response, $source, $rawURL);
        if ($result instanceof ResponseInterface) {
            return $result; // downloadAndUpload already wrote the error response.
        }
        [$uploadID, $total] = $result;

        return Json::write($response, 200, ['record_count' => $total, 'source' => $source, 'upload_id' => $uploadID]);
    }

    /**
     * resolveUploadURL extracts the `url` for a URL-based upload. For JSON bodies
     * ({"url": "...", "source": "..."}) it returns both fields; for multipart form
     * data it reads the `url` form field (the source is read separately by the
     * caller via PostForm).
     *
     * @return array{0: string, 1: string} [rawURL, source]
     */
    private static function resolveUploadURL(ServerRequestInterface $request): array
    {
        $ctype = $request->getHeaderLine('Content-Type');
        if (str_starts_with(strtolower($ctype), 'application/json')) {
            try {
                $body = Binding::shouldBindJSON($request, 'struct { URL string; Source string }');

                return [JsonBinding::string($body, 'url', ''), JsonBinding::string($body, 'source', '')];
            } catch (\RuntimeException) {
                return ['', ''];
            }
        }

        return [self::reconciliationPostForm($request, 'url'), ''];
    }

    /**
     * urlHostname is Go's `url.URL.Hostname()`: the host without port, and
     * without the brackets of an IPv6 literal.
     *
     * @param array<string, mixed> $u the parse_url() result
     */
    private static function urlHostname(array $u): string
    {
        $host = (string) ($u['host'] ?? '');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * goPathBase is Go's `path.Base`: the last element of path, after
     * trailing slashes are removed; "." for an empty path and "/" for a path
     * consisting entirely of slashes.
     */
    private static function goPathBase(string $path): string
    {
        if ($path === '') {
            return '.';
        }
        // Strip trailing slashes.
        $path = rtrim($path, '/');
        // Find the last element
        $i = strrpos($path, '/');
        if ($i !== false) {
            $path = substr($path, $i + 1);
        }
        // If empty now, it had only slashes.
        if ($path === '') {
            return '/';
        }

        return $path;
    }

    /**
     * limitReader is `io.LimitReader(body, limit)`: at most $limit bytes of
     * the response body, copied into a seekable php://temp stream for the
     * service layer.
     *
     * @return resource
     */
    private static function reconciliationLimitReader(StreamInterface $body, int $limit)
    {
        $tmp = fopen('php://temp', 'w+b');
        if ($tmp === false) {
            throw new \RuntimeException('failed to open temporary stream');
        }
        $remaining = $limit;
        while ($remaining > 0 && !$body->eof()) {
            $chunk = $body->read(min(65536, $remaining));
            if ($chunk === '') {
                break;
            }
            fwrite($tmp, $chunk);
            $remaining -= \strlen($chunk);
        }
        rewind($tmp);

        return $tmp;
    }

    /**
     * downloadAndUpload fetches the remote body at rawURL and pipes it through the
     * unchanged UploadExternalData service. It enforces SSRF protection
     * (http(s)-only, deny-by-default host whitelist, redirect re-validation), a
     * configurable GET timeout, and the existing MaxUploadSizeMB body cap via
     * io.LimitReader. On failure it writes the error response and returns ok=false
     * (PHP: the written error response is returned instead of `[uploadID, total]`).
     *
     * @return array{0: string, 1: int}|ResponseInterface
     */
    private function downloadAndUpload(ResponseInterface $response, string $source, string $rawURL): array|ResponseInterface
    {
        try {
            $cfg = Configuration::fetch();
        } catch (\Throwable) {
            $cfg = null; // Go: cfg, _ := config.Fetch()
        }

        $u = parse_url($rawURL);
        if ($u === false) {
            return Errors::respondCode($response, ErrorCode::ErrReconUploadURLInvalid, 'invalid upload URL', null);
        }

        // Scheme guard: http/https only (rejects ftp://, file://, gopher://, ...).
        $scheme = strtolower((string) ($u['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return Errors::respondCode($response, ErrorCode::ErrReconUploadURLInvalid, 'upload URL must use http or https', null);
        }

        $host = strtolower(trim(self::urlHostname($u)));
        if ($host === '') {
            return Errors::respondCode($response, ErrorCode::ErrReconUploadURLInvalid, 'invalid upload URL', null);
        }

        // Whitelist guard: exact-host match, deny-by-default when empty.
        $allowed = $cfg !== null ? $cfg->uploadDomainWhitelistHosts() : [];
        if (!self::hostAllowed($host, $allowed)) {
            return Errors::respondCode($response, ErrorCode::ErrReconUploadHostNotAllowed, 'upload host not allowed', null);
        }

        // Filename from the URL path; the service layer relies on the extension for
        // type detection. Fall back to "download" when the path has no basename.
        $name = self::goPathBase(rawurldecode((string) ($u['path'] ?? '')));
        if ($name === '.' || $name === '/' || $name === '') {
            $name = 'download';
        }

        $timeout = Configuration::DEFAULT_UPLOAD_URL_TIMEOUT_SEC;
        if ($cfg !== null && $cfg->server->uploadURLTimeoutSec > 0) {
            $timeout = $cfg->server->uploadURLTimeoutSec;
        }

        // Custom client that re-validates every redirect's host against the
        // whitelist so a whitelisted host can't 302 to an internal IP/loopback
        // (Go: http.Client.CheckRedirect returning http.ErrUseLastResponse).
        $client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'allow_redirects' => false,
            'http_errors' => false,
            'stream' => true,
        ]);
        $headers = ['User-Agent' => 'blnk-reconciliation/1.0'];

        try {
            $current = new Uri($rawURL);
            $redirects = 0;
            while (true) {
                $resp = $client->request('GET', $current, ['headers' => $headers]);
                $status = $resp->getStatusCode();
                if (\in_array($status, [301, 302, 303, 307, 308], true) && $resp->hasHeader('Location')) {
                    $next = UriResolver::resolve($current, new Uri($resp->getHeaderLine('Location')));
                    $nextHost = strtolower(trim(trim($next->getHost(), '[]')));
                    if ($nextHost === '' || !self::hostAllowed($nextHost, $allowed)) {
                        break; // http.ErrUseLastResponse: the 3xx response is the final one.
                    }
                    if (++$redirects > 10) {
                        throw new \RuntimeException('stopped after 10 redirects');
                    }
                    $resp->getBody()->close();
                    $current = $next;
                    continue;
                }
                break;
            }
        } catch (\Throwable $err) {
            Log::get()->error('failed to fetch upload URL', ['error' => $err->getMessage()]);

            return Errors::respondCode($response, ErrorCode::ErrReconUploadProcessingFailed, 'Failed to process upload', null);
        }

        if ($status < 200 || $status >= 300) {
            Log::get()->error('upload URL returned non-2xx', ['status' => $status]);

            return Errors::respondCode($response, ErrorCode::ErrReconUploadProcessingFailed, 'Failed to process upload', null);
        }

        // Cap the downloaded body at MaxUploadSizeMB. An oversize response is
        // truncated, which surfaces downstream as a parse/processing failure (500).
        $limit = Configuration::DEFAULT_MAX_UPLOAD_SIZE_MB * 1024 * 1024;
        if ($cfg !== null && $cfg->server->maxUploadSizeMB > 0) {
            $limit = $cfg->server->maxUploadSizeMB * 1024 * 1024;
        }

        try {
            $limited = self::reconciliationLimitReader($resp->getBody(), $limit + 1);
            [$id, $total] = $this->blnk->uploadExternalData($source, $limited, $name);
        } catch (\Throwable $err) {
            Log::get()->error('failed to process URL upload', ['error' => Errors::errorString($err)]);

            return Errors::respondCode($response, ErrorCode::ErrReconUploadProcessingFailed, 'Failed to process upload', null);
        }

        return [$id, $total];
    }

    /**
     * hostAllowed reports whether host is present in the (lowercased) whitelist.
     *
     * @param string[] $allowed
     */
    private static function hostAllowed(string $host, array $allowed): bool
    {
        foreach ($allowed as $a) {
            if ($a === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * StartReconciliation initiates a new reconciliation process based on the provided parameters.
     * It starts the reconciliation process and returns the reconciliation ID.
     *
     * Responses:
     * - 400 Bad Request: If the request body is invalid or required fields are missing.
     * - 500 Internal Server Error: If there is an error starting the reconciliation process.
     * - 200 OK: If the reconciliation process is successfully started.
     */
    public function startReconciliation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Go: an anonymous request struct with `binding:"required"` on
        // upload_id, strategy and matching_rule_ids (validator names the
        // fields without a struct prefix for anonymous structs).
        try {
            $body = Binding::shouldBindJSON($request, 'struct { UploadID string; Strategy string; GroupingCriteria string; DryRun bool; MatchingRuleIDs []string }');
            $uploadID = JsonBinding::string($body, 'upload_id', '');
            $strategy = JsonBinding::string($body, 'strategy', '');
            $groupingCriteria = JsonBinding::string($body, 'grouping_criteria', '');
            $dryRun = JsonBinding::bool($body, 'dry_run', '');
            $matchingRuleIDs = JsonBinding::stringList($body, 'matching_rule_ids', '');

            $missing = [];
            if ($uploadID === '') {
                $missing[] = 'UploadID';
            }
            if ($strategy === '') {
                $missing[] = 'Strategy';
            }
            if ($matchingRuleIDs === null) {
                $missing[] = 'MatchingRuleIDs';
            }
            if (\count($missing) > 0) {
                throw JsonBinding::requiredErrors('', $missing);
            }
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }
        if (\count($matchingRuleIDs) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrReconMatchingRulesRequired, 'matching_rule_ids is required', null);
        }

        try {
            $reconciliationID = $this->blnk->startReconciliation(
                $uploadID,
                $strategy,
                $groupingCriteria,
                $matchingRuleIDs,
                $dryRun
            );
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));

            return Errors::respondError(
                $response,
                $err,
                Errors::withDefault(ErrorCode::ErrReconStartFailed),
                Errors::withFallbackMessage('Failed to start reconciliation')
            );
        }

        return Json::write($response, 200, ['reconciliation_id' => $reconciliationID]);
    }

    /**
     * InstantReconciliation initiates a reconciliation process with externally provided transactions
     * without requiring a prior file upload. It processes the transactions directly and returns
     * the reconciliation ID.
     *
     * Responses:
     * - 400 Bad Request: If the request body is invalid or required fields are missing.
     * - 500 Internal Server Error: If there is an error starting the reconciliation process.
     * - 200 OK: If the reconciliation process is successfully started.
     */
    public function instantReconciliation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Go: an anonymous request struct with `binding:"required"` on
        // external_transactions, strategy and matching_rule_ids.
        try {
            $body = Binding::shouldBindJSON($request, 'struct { ExternalTransactions []model.ExternalTransaction; Strategy string; GroupingCriteria string; DryRun bool; MatchingRuleIDs []string }');
            $rawTransactions = JsonBinding::objectList($body, 'external_transactions', '', 'model.ExternalTransaction');
            $strategy = JsonBinding::string($body, 'strategy', '');
            $groupingCriteria = JsonBinding::string($body, 'grouping_criteria', '');
            $dryRun = JsonBinding::bool($body, 'dry_run', '');
            $matchingRuleIDs = JsonBinding::stringList($body, 'matching_rule_ids', '');

            $externalTransactions = null;
            if ($rawTransactions !== null) {
                $externalTransactions = [];
                foreach ($rawTransactions as $item) {
                    $externalTransactions[] = ExternalTransaction::fromArray($item ?? []);
                }
            }

            $missing = [];
            if ($externalTransactions === null) {
                $missing[] = 'ExternalTransactions';
            }
            if ($strategy === '') {
                $missing[] = 'Strategy';
            }
            if ($matchingRuleIDs === null) {
                $missing[] = 'MatchingRuleIDs';
            }
            if (\count($missing) > 0) {
                throw JsonBinding::requiredErrors('', $missing);
            }
        } catch (\Throwable $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }
        if (\count($externalTransactions) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrReconExternalTxnsRequired, 'external_transactions is required', null);
        }
        if (\count($externalTransactions) > ApiModelHelpers::MaxInstantReconciliationItems) {
            return Errors::respondCode(
                $response,
                ErrorCode::ErrGenValidation,
                'too many external_transactions; max is ' . ApiModelHelpers::MaxInstantReconciliationItems,
                null
            );
        }
        if (\count($matchingRuleIDs) === 0) {
            return Errors::respondCode($response, ErrorCode::ErrReconMatchingRulesRequired, 'matching_rule_ids is required', null);
        }

        try {
            $reconciliationID = $this->blnk->startInstantReconciliation(
                $externalTransactions,
                $strategy,
                $groupingCriteria,
                $matchingRuleIDs,
                $dryRun
            );
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));

            return Errors::respondError(
                $response,
                $err,
                Errors::withDefault(ErrorCode::ErrReconStartFailed),
                Errors::withFallbackMessage('Failed to start instant reconciliation')
            );
        }

        return Json::write($response, 200, ['reconciliation_id' => $reconciliationID]);
    }

    /**
     * GetReconciliation retrieves details about a specific reconciliation by its ID.
     *
     * Responses:
     * - 400 Bad Request: If the reconciliation ID is missing.
     * - 404 Not Found: If the reconciliation cannot be found.
     * - 500 Internal Server Error: If there is an error retrieving the reconciliation.
     * - 200 OK: If the reconciliation is successfully retrieved.
     */
    public function getReconciliation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reconciliationID = Query::param($args, 'id');
        if ($reconciliationID === '') {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'Reconciliation ID is required', null);
        }

        try {
            $reconciliation = $this->blnk->getReconciliation($reconciliationID);
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));
            $code = Errors::classifyMessage(Errors::errorString($err));
            if ($code !== null && ErrorCode::statusForCode($code) === 404) {
                // Historical fixed message preserved for the legacy field.
                return Errors::respondCode($response, ErrorCode::ErrReconNotFound, 'Reconciliation not found', null);
            }

            return Errors::respondCode($response, ErrorCode::ErrGenInternal, 'Failed to retrieve reconciliation', null);
        }

        return Json::write($response, 200, $reconciliation);
    }

    /**
     * CreateMatchingRule creates a new matching rule based on the provided rule details.
     * It returns the created matching rule.
     *
     * Responses:
     * - 400 Bad Request: If the request body is invalid.
     * - 500 Internal Server Error: If there is an error creating the matching rule.
     * - 201 Created: If the matching rule is successfully created.
     */
    public function createMatchingRule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $rule = MatchingRule::fromArray(Binding::shouldBindJSON($request, 'model.MatchingRule'));
        } catch (\Throwable $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        try {
            $createdRule = $this->blnk->createMatchingRule($rule);
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));

            return Errors::respondError(
                $response,
                $err,
                Errors::withDefault(ErrorCode::ErrGenInternal),
                Errors::withFallbackMessage('Failed to create matching rule')
            );
        }

        return Json::write($response, 201, $createdRule);
    }

    /**
     * UpdateMatchingRule updates an existing matching rule identified by its ID.
     * It returns the updated matching rule.
     *
     * Responses:
     * - 400 Bad Request: If the Matching Rule ID is missing or the request body is invalid.
     * - 500 Internal Server Error: If there is an error updating the matching rule.
     * - 200 OK: If the matching rule is successfully updated.
     */
    public function updateMatchingRule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ruleID = Query::param($args, 'id');
        if ($ruleID === '') {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'Matching Rule ID is required', null);
        }

        try {
            $rule = MatchingRule::fromArray(Binding::shouldBindJSON($request, 'model.MatchingRule'));
        } catch (\Throwable $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        $rule->ruleID = $ruleID;
        try {
            $updatedRule = $this->blnk->updateMatchingRule($rule);
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));

            return Errors::respondError(
                $response,
                $err,
                Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrReconRuleNotFound),
                Errors::withDefault(ErrorCode::ErrGenInternal),
                Errors::withFallbackMessage('Failed to update matching rule')
            );
        }

        return Json::write($response, 200, $updatedRule);
    }

    /**
     * DeleteMatchingRule deletes a matching rule identified by its ID.
     * It confirms the deletion with a success message.
     *
     * Responses:
     * - 400 Bad Request: If the Matching Rule ID is missing.
     * - 500 Internal Server Error: If there is an error deleting the matching rule.
     * - 200 OK: If the matching rule is successfully deleted.
     */
    public function deleteMatchingRule(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ruleID = Query::param($args, 'id');
        if ($ruleID === '') {
            return Errors::respondCode($response, ErrorCode::ErrGenMissingParameter, 'Matching Rule ID is required', null);
        }

        try {
            $this->blnk->deleteMatchingRule($ruleID);
        } catch (\Throwable $err) {
            Log::get()->error(Errors::errorString($err));

            return Errors::respondError(
                $response,
                $err,
                Errors::withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrReconRuleNotFound),
                Errors::withDefault(ErrorCode::ErrGenInternal),
                Errors::withFallbackMessage('Failed to delete matching rule')
            );
        }

        return Json::write($response, 200, ['message' => 'Matching rule deleted successfully']);
    }
}
