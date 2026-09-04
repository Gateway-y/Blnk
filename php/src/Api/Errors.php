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

use Blnk\Api\Model\PrecisionMustBeIntegerException;
use Blnk\Core\EntityNotFoundException;
use Blnk\Database\ApiKeyNotFoundException;
use Blnk\Database\InvalidApiKeyException;
use Blnk\Database\NotFoundException;
use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\Lock\LockHeldException;
use Blnk\Internal\Lock\LockWaitTimeoutException;
use Blnk\Internal\Log;
use Blnk\Internal\Tokenization\TokenizationDisabledException;
use Psr\Http\Message\ResponseInterface;

/**
 * Port of api/errors.go: the package-level response helpers shared by every
 * handler file.
 *
 * Every error response carries two payloads during the transition period:
 * the legacy field clients depend on today (flat "error"/"errors" string,
 * preserved verbatim) and the structured "error_detail" object with the
 * canonical error code. The legacy field is removed — and error_detail
 * renamed to error — at the next major release. See docs/errors.md.
 *
 * Go's `respondOpt` functional options are closures taking a
 * {@see RespondOptions}; the Go `(gin.Context)` receiver becomes the PSR-7
 * response the helpers write into and return.
 */
final class Errors
{
    public const errorDetailKey = 'error_detail';

    /**
     * sanitizedInternalMessage replaces raw internal error text on unclassified
     * 5xx responses so database/driver details never leak to clients.
     */
    public const sanitizedInternalMessage = 'internal server error';

    /**
     * messagePatterns entries are evaluated in order; more specific patterns must
     * precede broader ones (e.g. "field ... not found" before bare "not found").
     * This table bridges the core packages' unstructured fmt.Errorf messages to
     * catalog codes without editing ~280 core call sites. Any core error later
     * converted to a typed APIError bypasses this table entirely (it resolves in
     * respondError's errors.As step), so entries here can be retired
     * incrementally as core adopts typed errors.
     *
     * Each entry: [all substrings that must match, code].
     *
     * @var list<array{0: string[], 1: string}>
     */
    public const messagePatterns = [
        // Transactions
        [['transaction', 'not found'], ErrorCode::ErrTxnNotFound],
        [['not in inflight status'], ErrorCode::ErrTxnNotInflight],
        [['Transaction already committed'], ErrorCode::ErrTxnAlreadyCommitted],
        [['has already been voided'], ErrorCode::ErrTxnAlreadyVoided],
        [['has already been refunded'], ErrorCode::ErrGenConflict],
        [['has already been used'], ErrorCode::ErrTxnDuplicateReference],
        [['cannot commit more than'], ErrorCode::ErrTxnCommitAmountExceeded],
        [['insufficient funds'], ErrorCode::ErrTxnInsufficientFunds],
        [['transaction amount must be positive'], ErrorCode::ErrTxnInvalidAmount],
        [['transaction validation failed'], ErrorCode::ErrTxnValidation],
        [['reference is required'], ErrorCode::ErrTxnValidation],
        // Balances
        [['no balance data found for time'], ErrorCode::ErrBalHistoryNotFound],
        [['invalid timestamp format'], ErrorCode::ErrBalInvalidTimestamp],
        [['balance validation failed'], ErrorCode::ErrBalValidation],
        [['identity validation failed'], ErrorCode::ErrBalValidation],
        // Identity tokenization
        [['is not tokenizable'], ErrorCode::ErrIdtFieldNotTokenizable],
        [['is already tokenized'], ErrorCode::ErrIdtFieldAlreadyTokenized],
        [['is not tokenized'], ErrorCode::ErrIdtFieldNotTokenized],
        [['field', 'not found'], ErrorCode::ErrIdtFieldNotFound],
        // Reconciliation rules
        [['rule name is required'], ErrorCode::ErrReconRuleInvalid],
        [['matching criteria is required'], ErrorCode::ErrReconRuleInvalid],
        [['field and operator are required'], ErrorCode::ErrReconRuleInvalid],
        [['invalid operator'], ErrorCode::ErrReconRuleInvalid],
        [['invalid field'], ErrorCode::ErrReconRuleInvalid],
        [['drift for'], ErrorCode::ErrReconRuleInvalid],
        // Metadata
        [['entity not found'], ErrorCode::ErrMetaEntityNotFound],
        [['unsupported entity type'], ErrorCode::ErrMetaUnsupportedEntity],
        [['invalid entity ID'], ErrorCode::ErrMetaInvalidEntityID],
        // Lineage
        [['does not have fund lineage tracking enabled'], ErrorCode::ErrGenBadRequest],
        // Infrastructure
        [['failed to acquire lock'], ErrorCode::ErrGenResourceLocked],
        [['no rows in result set'], ErrorCode::ErrGenNotFound],
        // Broad catch-all; must stay last.
        [['not found'], ErrorCode::ErrGenNotFound],
    ];

    private function __construct()
    {
    }

    /**
     * withDefault sets the fallback code used when respondError cannot classify
     * the error, instead of GEN_INTERNAL.
     *
     * @return \Closure(RespondOptions): void
     */
    public static function withDefault(string $code): \Closure
    {
        return static function (RespondOptions $o) use ($code): void {
            $o->defaultCode = $code;
        };
    }

    /**
     * withFallbackMessage overrides the client-facing message when respondError
     * falls back to the default code, for handlers that historically returned a
     * fixed generic message instead of the raw error text.
     *
     * @return \Closure(RespondOptions): void
     */
    public static function withFallbackMessage(string $msg): \Closure
    {
        return static function (RespondOptions $o) use ($msg): void {
            $o->fallbackMessage = $msg;
        };
    }

    /**
     * withUpgrade replaces a resolved generic code with a domain-specific one,
     * e.g. withUpgrade(ErrorCode::ErrGenNotFound, ErrorCode::ErrLgrNotFound) in the
     * ledger handlers.
     *
     * @return \Closure(RespondOptions): void
     */
    public static function withUpgrade(string $from, string $to): \Closure
    {
        return static function (RespondOptions $o) use ($from, $to): void {
            $o->upgrades[$from] = $to;
        };
    }

    /**
     * withLegacyKey overrides the legacy field name; endpoints that historically
     * responded with a plural "errors" key use this to stay byte-compatible.
     *
     * @return \Closure(RespondOptions): void
     */
    public static function withLegacyKey(string $key): \Closure
    {
        return static function (RespondOptions $o) use ($key): void {
            $o->legacyKey = $key;
        };
    }

    /**
     * withLegacyValue overrides the legacy field value when it was not a plain
     * message string historically (e.g. filter parse-error slices).
     *
     * @return \Closure(RespondOptions): void
     */
    public static function withLegacyValue(mixed $v): \Closure
    {
        return static function (RespondOptions $o) use ($v): void {
            $o->legacyValue = $v;
        };
    }

    /**
     * @param iterable<\Closure(RespondOptions): void> $opts
     */
    public static function buildOptions(iterable $opts): RespondOptions
    {
        $o = new RespondOptions();
        foreach ($opts as $opt) {
            $opt($o);
        }

        return $o;
    }

    /**
     * respondCode writes the dual error payload for a known error condition.
     *
     * @param \Closure(RespondOptions): void ...$opts
     */
    public static function respondCode(ResponseInterface $response, string $code, string $message, mixed $details, \Closure ...$opts): ResponseInterface
    {
        return self::writeError($response, $code, $message, $details, self::buildOptions($opts));
    }

    /**
     * respondError resolves err to a catalog code and writes the dual payload.
     * Resolution order: typed APIError → known sentinels → message patterns →
     * fallback (withDefault or GEN_INTERNAL with a sanitized message).
     *
     * @param \Closure(RespondOptions): void ...$opts
     */
    public static function respondError(ResponseInterface $response, ?\Throwable $err, \Closure ...$opts): ResponseInterface
    {
        $o = self::buildOptions($opts);
        if ($err === null) {
            return self::writeError($response, ErrorCode::ErrGenInternal, self::sanitizedInternalMessage, null, $o);
        }

        $apiErr = self::asApiError($err);
        if ($apiErr !== null) {
            return self::writeError($response, $apiErr->errorCode, $apiErr->getMessage(), $apiErr->details, $o);
        }

        $code = self::classifySentinel($err);
        if ($code !== null) {
            return self::writeError($response, $code, self::errorString($err), null, $o);
        }

        $code = self::classifyMessage(self::errorString($err));
        if ($code !== null) {
            return self::writeError($response, $code, self::errorString($err), null, $o);
        }

        if ($o->defaultCode !== '') {
            $msg = self::errorString($err);
            if ($o->fallbackMessage !== '') {
                $msg = $o->fallbackMessage;
                Log::get()->error('API error masked by fallback message', ['error' => self::errorString($err)]);
            }

            return self::writeError($response, $o->defaultCode, $msg, null, $o);
        }

        Log::get()->error('unclassified error in API response', ['error' => self::errorString($err)]);

        return self::writeError($response, ErrorCode::ErrGenInternal, self::sanitizedInternalMessage, null, $o);
    }

    public static function writeError(ResponseInterface $response, string $code, string $message, mixed $details, RespondOptions $o): ResponseInterface
    {
        $code = ErrorCode::normalize($code);
        if (isset($o->upgrades[$code])) {
            $code = $o->upgrades[$code];
        }
        $legacyValue = $o->legacyValue;
        if ($legacyValue === null) {
            $legacyValue = $message;
        }

        return Json::write($response, ErrorCode::statusForCode($code), [
            $o->legacyKey => $legacyValue,
            self::errorDetailKey => self::apiErrorPayload($code, $message, $details),
        ]);
    }

    /**
     * apiErrorPayload serializes an `apierror.APIError{Code, Message, Details}`
     * value: {"code", "message", "details"(omitempty)}.
     *
     * @return array<string, mixed>
     */
    public static function apiErrorPayload(string $code, string $message, mixed $details): array
    {
        $payload = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $payload['details'] = $details;
        }

        return $payload;
    }

    /**
     * errorString renders an exception the way Go's `err.Error()` prints it:
     * a typed APIError as "CODE: message", anything else as its message.
     */
    public static function errorString(\Throwable $err): string
    {
        if ($err instanceof ApiErrorException && !self::isPlainError($err)) {
            return $err->error();
        }

        return $err->getMessage();
    }

    /**
     * isPlainError reports whether an exception stands for a plain Go error
     * (`errors.New` / `fmt.Errorf`) rather than a typed APIError: anything
     * that is not an ApiErrorException, a sentinel port (see isSentinel), or
     * a wrapper that merely relays the code of a sentinel it wraps — the
     * shape {@see \Blnk\Core\Blnk::wrapError()} produces for
     * `fmt.Errorf("...: %w", sentinel)`.
     */
    private static function isPlainError(\Throwable $err): bool
    {
        if (!($err instanceof ApiErrorException)) {
            return true;
        }
        if (self::isSentinel($err)) {
            return true;
        }
        for ($inner = $err->getPrevious(); $inner !== null; $inner = $inner->getPrevious()) {
            if (self::isSentinel($inner)) {
                return $inner instanceof ApiErrorException
                    && ErrorCode::normalize($inner->errorCode) === ErrorCode::normalize($err->errorCode);
            }
        }

        return false;
    }

    /**
     * isSentinel reports whether an exception ports one of the Go sentinel
     * errors handled by classifySentinel. In Go those sentinels are plain
     * `errors.New` values — never APIErrors — so the `errors.As(APIError)` step
     * of respondError must not resolve on them even though several of their
     * PHP ports extend ApiErrorException.
     */
    private static function isSentinel(\Throwable $err): bool
    {
        return $err instanceof ApiKeyNotFoundException
            || $err instanceof InvalidApiKeyException
            || $err instanceof TokenizationDisabledException
            || $err instanceof LockHeldException
            || $err instanceof LockWaitTimeoutException
            || $err instanceof PrecisionMustBeIntegerException
            || $err instanceof EntityNotFoundException
            || $err instanceof NotFoundException;
    }

    /**
     * asApiError is the `errors.As(err, &apiErr)` step: the first typed
     * APIError in the wrap chain, ignoring sentinel ports (see isSentinel).
     *
     * A wrapper produced by Blnk::wrapError() relays the code of the sentinel
     * it wraps (Go: a plain `fmt.Errorf("...: %w", sentinel)`); such a wrapper
     * is treated as plain too, so the sentinel classification — with the full
     * wrapped message — applies exactly as in Go.
     */
    private static function asApiError(\Throwable $err): ?ApiErrorException
    {
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ApiErrorException && !self::isPlainError($e)) {
                return $e;
            }
        }

        return null;
    }

    /**
     * classifySentinel maps known typed sentinel errors to catalog codes
     * (`errors.Is` semantics: the whole wrap chain is inspected).
     * Returns null when no sentinel matches (Go: `"", false`).
     */
    public static function classifySentinel(\Throwable $err): ?string
    {
        for ($e = $err; $e !== null; $e = $e->getPrevious()) {
            switch (true) {
                case $e instanceof ApiKeyNotFoundException:
                    return ErrorCode::ErrAPIKeyNotFound;
                case $e instanceof InvalidApiKeyException:
                    return ErrorCode::ErrAuthInvalidAPIKey;
                case $e instanceof TokenizationDisabledException:
                    return ErrorCode::ErrIdtTokenizationDisabled;
                case $e instanceof LockHeldException:
                case $e instanceof LockWaitTimeoutException:
                    return ErrorCode::ErrGenResourceLocked;
                case $e instanceof PrecisionMustBeIntegerException:
                    return ErrorCode::ErrTxnPrecisionNotInteger;
                case $e instanceof EntityNotFoundException:
                    return ErrorCode::ErrMetaEntityNotFound;
                case $e instanceof NotFoundException: // sql.ErrNoRows
                    return ErrorCode::ErrGenNotFound;
            }
        }

        return null;
    }

    /**
     * classifyMessage resolves a message against messagePatterns; null when no
     * pattern matches (Go: `"", false`).
     */
    public static function classifyMessage(string $msg): ?string
    {
        foreach (self::messagePatterns as [$contains, $code]) {
            $matched = true;
            foreach ($contains as $sub) {
                if (!str_contains($msg, $sub)) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $code;
            }
        }

        return null;
    }

    /**
     * respondBareAPIError preserves the hooks endpoints' historical body shape —
     * a top-level {code, message, details} object — while adding error_detail.
     */
    public static function respondBareAPIError(ResponseInterface $response, ApiErrorException $legacy, string $code): ResponseInterface
    {
        $code = ErrorCode::normalize($code);

        return Json::write($response, ErrorCode::statusForCode($code), [
            'code' => $legacy->errorCode,
            'message' => $legacy->getMessage(),
            'details' => $legacy->details,
            self::errorDetailKey => self::apiErrorPayload($code, $legacy->getMessage(), $legacy->details),
        ]);
    }

    /**
     * respondNestedAPIError preserves the admin endpoints' historical shape —
     * the APIError object under "error" — while adding error_detail.
     */
    public static function respondNestedAPIError(ResponseInterface $response, ApiErrorException $legacy, string $code): ResponseInterface
    {
        $code = ErrorCode::normalize($code);

        return Json::write($response, ErrorCode::statusForCode($code), [
            'error' => $legacy,
            self::errorDetailKey => self::apiErrorPayload($code, $legacy->getMessage(), $legacy->details),
        ]);
    }
}
