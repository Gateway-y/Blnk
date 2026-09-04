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

namespace Blnk\Internal\ApiError;

/**
 * ErrorCode defines the string constants representing specific error codes used in the API.
 *
 * Port of Go `internal/apierror` (`apierror.go` + `codes.go`): the Go `ErrorCode`
 * string type becomes plain PHP strings; the code catalog lives here as class
 * constants keeping their Go names, together with the `Normalize` and
 * `StatusForCode` package-level functions.
 *
 * Domain-prefixed error codes. These are the canonical, client-facing codes
 * returned in the `error_detail.code` field of every error response.
 * Each code maps to exactly one default HTTP status (see STATUS_BY_CODE).
 *
 * The six legacy codes (NOT_FOUND, CONFLICT, ...) remain valid for internal
 * construction — they are normalized to their GEN_* equivalents at the
 * response boundary via normalize().
 */
final class ErrorCode
{
    // Predefined (legacy) error codes to represent different error conditions.
    /** Used when a requested resource is not found. */
    public const ErrNotFound = 'NOT_FOUND';
    /** Used when a request conflicts with the current state of the resource. */
    public const ErrConflict = 'CONFLICT';
    /** Used when a request contains invalid data or parameters. */
    public const ErrBadRequest = 'BAD_REQUEST';
    /** Used when the provided input does not meet the expected format or constraints. */
    public const ErrInvalidInput = 'INVALID_INPUT';
    /** Used for general server errors that are not client-related. */
    public const ErrInternalServer = 'INTERNAL_SERVER_ERROR';
    /** Used when a request is rate limited. */
    public const ErrRateLimited = 'RATE_LIMITED';

    // GEN — generic / cross-cutting
    public const ErrGenMalformedRequest = 'GEN_MALFORMED_REQUEST';
    public const ErrGenValidation = 'GEN_VALIDATION_ERROR';
    public const ErrGenMissingParameter = 'GEN_MISSING_PARAMETER';
    public const ErrGenBadRequest = 'GEN_BAD_REQUEST';
    public const ErrGenNotFound = 'GEN_NOT_FOUND';
    public const ErrGenConflict = 'GEN_CONFLICT';
    public const ErrGenResourceLocked = 'GEN_RESOURCE_LOCKED';
    public const ErrGenPayloadTooLarge = 'GEN_PAYLOAD_TOO_LARGE';
    public const ErrGenRateLimited = 'GEN_RATE_LIMITED';
    public const ErrGenInternal = 'GEN_INTERNAL';

    // AUTH — authentication / authorization
    public const ErrAuthMissingAPIKey = 'AUTH_MISSING_API_KEY';
    public const ErrAuthInvalidAPIKey = 'AUTH_INVALID_API_KEY';
    public const ErrAuthExpiredAPIKey = 'AUTH_EXPIRED_API_KEY';
    public const ErrAuthMissingPrincipal = 'AUTH_MISSING_PRINCIPAL';
    public const ErrAuthInsufficientPermissions = 'AUTH_INSUFFICIENT_PERMISSIONS';
    public const ErrAuthUnknownResource = 'AUTH_UNKNOWN_RESOURCE';
    public const ErrAuthMasterKeyRequired = 'AUTH_MASTER_KEY_REQUIRED';
    public const ErrAuthCrossOwnerAccess = 'AUTH_CROSS_OWNER_ACCESS';
    public const ErrAuthScopeEscalation = 'AUTH_SCOPE_ESCALATION';
    public const ErrAuthMetricsTokenRequired = 'AUTH_METRICS_TOKEN_REQUIRED';
    public const ErrAuthInvalidBearerToken = 'AUTH_INVALID_BEARER_TOKEN';
    public const ErrAuthMetricsDisabled = 'AUTH_METRICS_DISABLED';

    // APIKEY — API-key resource management
    public const ErrAPIKeyNotFound = 'APIKEY_NOT_FOUND';
    public const ErrAPIKeyOwnerRequired = 'APIKEY_OWNER_REQUIRED';
    public const ErrAPIKeyInvalid = 'APIKEY_INVALID';

    // TXN — transactions
    public const ErrTxnNotFound = 'TXN_NOT_FOUND';
    public const ErrTxnInsufficientFunds = 'TXN_INSUFFICIENT_FUNDS';
    public const ErrTxnInvalidAmount = 'TXN_INVALID_AMOUNT';
    public const ErrTxnPrecisionNotInteger = 'TXN_PRECISION_NOT_INTEGER';
    public const ErrTxnInvalidDistribution = 'TXN_INVALID_DISTRIBUTION';
    public const ErrTxnDuplicateReference = 'TXN_DUPLICATE_REFERENCE';
    public const ErrTxnNotInflight = 'TXN_NOT_INFLIGHT';
    public const ErrTxnAlreadyCommitted = 'TXN_ALREADY_COMMITTED';
    public const ErrTxnAlreadyVoided = 'TXN_ALREADY_VOIDED';
    public const ErrTxnCommitAmountExceeded = 'TXN_COMMIT_AMOUNT_EXCEEDED';
    public const ErrTxnInvalidStatusAction = 'TXN_INVALID_STATUS_ACTION';
    public const ErrTxnBulkEmpty = 'TXN_BULK_EMPTY';
    public const ErrTxnBulkLimitExceeded = 'TXN_BULK_LIMIT_EXCEEDED';
    public const ErrTxnValidation = 'TXN_VALIDATION_ERROR';

    // BAL — balances & monitors
    public const ErrBalNotFound = 'BAL_NOT_FOUND';
    public const ErrBalHistoryNotFound = 'BAL_HISTORY_NOT_FOUND';
    public const ErrBalInvalidTimestamp = 'BAL_INVALID_TIMESTAMP';
    public const ErrBalValidation = 'BAL_VALIDATION_ERROR';
    public const ErrBalMonitorNotFound = 'BAL_MONITOR_NOT_FOUND';

    // LGR — ledgers
    public const ErrLgrNotFound = 'LGR_NOT_FOUND';
    public const ErrLgrDuplicate = 'LGR_DUPLICATE';

    // ACC — accounts
    public const ErrAccNotFound = 'ACC_NOT_FOUND';
    public const ErrAccDuplicate = 'ACC_DUPLICATE';
    public const ErrAccGenerationFailed = 'ACC_GENERATION_FAILED';

    // IDT — identities & tokenization
    public const ErrIdtNotFound = 'IDT_NOT_FOUND';
    public const ErrIdtValidation = 'IDT_VALIDATION_ERROR';
    public const ErrIdtFieldNotTokenizable = 'IDT_FIELD_NOT_TOKENIZABLE';
    public const ErrIdtFieldAlreadyTokenized = 'IDT_FIELD_ALREADY_TOKENIZED';
    public const ErrIdtFieldNotTokenized = 'IDT_FIELD_NOT_TOKENIZED';
    public const ErrIdtFieldNotFound = 'IDT_FIELD_NOT_FOUND';
    public const ErrIdtTokenizationDisabled = 'IDT_TOKENIZATION_DISABLED';

    // RECON — reconciliation
    public const ErrReconNotFound = 'RECON_NOT_FOUND';
    public const ErrReconRuleNotFound = 'RECON_RULE_NOT_FOUND';
    public const ErrReconUploadFailed = 'RECON_UPLOAD_FAILED';
    public const ErrReconUploadProcessingFailed = 'RECON_UPLOAD_PROCESSING_FAILED';
    public const ErrReconUploadURLInvalid = 'RECON_UPLOAD_URL_INVALID';
    public const ErrReconUploadHostNotAllowed = 'RECON_UPLOAD_HOST_NOT_ALLOWED';
    public const ErrReconRuleInvalid = 'RECON_RULE_INVALID';
    public const ErrReconMatchingRulesRequired = 'RECON_MATCHING_RULES_REQUIRED';
    public const ErrReconExternalTxnsRequired = 'RECON_EXTERNAL_TXNS_REQUIRED';
    public const ErrReconStartFailed = 'RECON_START_FAILED';

    // META — entity metadata
    public const ErrMetaEntityNotFound = 'META_ENTITY_NOT_FOUND';
    public const ErrMetaUnsupportedEntity = 'META_UNSUPPORTED_ENTITY';
    public const ErrMetaInvalidEntityID = 'META_INVALID_ENTITY_ID';

    // HOOK — webhook management
    public const ErrHookNotFound = 'HOOK_NOT_FOUND';
    public const ErrHookInvalid = 'HOOK_INVALID';
    public const ErrHookOperationFailed = 'HOOK_OPERATION_FAILED';

    // SRCH — search & reindex
    public const ErrSrchQueryInvalid = 'SRCH_QUERY_INVALID';
    public const ErrSrchFailed = 'SRCH_FAILED';
    public const ErrSrchReindexInProgress = 'SRCH_REINDEX_IN_PROGRESS';
    public const ErrSrchReindexNotStarted = 'SRCH_REINDEX_NOT_STARTED';

    // ADMIN — administrative operations
    public const ErrAdminBackupFailed = 'ADMIN_BACKUP_FAILED';

    /**
     * STATUS_BY_CODE is the single source of truth for the default HTTP status
     * of every error code, including the six legacy codes.
     *
     * @var array<string, int>
     */
    private const STATUS_BY_CODE = [
        self::ErrGenMalformedRequest => 400,
        self::ErrGenValidation => 400,
        self::ErrGenMissingParameter => 400,
        self::ErrGenBadRequest => 400,
        self::ErrGenNotFound => 404,
        self::ErrGenConflict => 409,
        self::ErrGenResourceLocked => 423,
        self::ErrGenPayloadTooLarge => 413,
        self::ErrGenRateLimited => 429,
        self::ErrGenInternal => 500,

        self::ErrAuthMissingAPIKey => 401,
        self::ErrAuthInvalidAPIKey => 401,
        self::ErrAuthExpiredAPIKey => 401,
        self::ErrAuthMissingPrincipal => 401,
        self::ErrAuthInsufficientPermissions => 403,
        self::ErrAuthUnknownResource => 403,
        self::ErrAuthMasterKeyRequired => 403,
        self::ErrAuthCrossOwnerAccess => 403,
        self::ErrAuthScopeEscalation => 403,
        self::ErrAuthMetricsTokenRequired => 401,
        self::ErrAuthInvalidBearerToken => 401,
        self::ErrAuthMetricsDisabled => 403,

        self::ErrAPIKeyNotFound => 404,
        self::ErrAPIKeyOwnerRequired => 400,
        self::ErrAPIKeyInvalid => 400,

        self::ErrTxnNotFound => 404,
        self::ErrTxnInsufficientFunds => 400,
        self::ErrTxnInvalidAmount => 400,
        self::ErrTxnPrecisionNotInteger => 400,
        self::ErrTxnInvalidDistribution => 400,
        self::ErrTxnDuplicateReference => 409,
        self::ErrTxnNotInflight => 400,
        self::ErrTxnAlreadyCommitted => 409,
        self::ErrTxnAlreadyVoided => 409,
        self::ErrTxnCommitAmountExceeded => 400,
        self::ErrTxnInvalidStatusAction => 400,
        self::ErrTxnBulkEmpty => 400,
        self::ErrTxnBulkLimitExceeded => 400,
        self::ErrTxnValidation => 400,

        self::ErrBalNotFound => 404,
        self::ErrBalHistoryNotFound => 404,
        self::ErrBalInvalidTimestamp => 400,
        self::ErrBalValidation => 400,
        self::ErrBalMonitorNotFound => 404,

        self::ErrLgrNotFound => 404,
        self::ErrLgrDuplicate => 409,

        self::ErrAccNotFound => 404,
        self::ErrAccDuplicate => 409,
        self::ErrAccGenerationFailed => 500,

        self::ErrIdtNotFound => 404,
        self::ErrIdtValidation => 400,
        self::ErrIdtFieldNotTokenizable => 400,
        self::ErrIdtFieldAlreadyTokenized => 409,
        self::ErrIdtFieldNotTokenized => 400,
        self::ErrIdtFieldNotFound => 400,
        self::ErrIdtTokenizationDisabled => 403,

        self::ErrReconNotFound => 404,
        self::ErrReconRuleNotFound => 404,
        self::ErrReconUploadFailed => 400,
        self::ErrReconUploadProcessingFailed => 500,
        self::ErrReconUploadURLInvalid => 400,
        self::ErrReconUploadHostNotAllowed => 400,
        self::ErrReconRuleInvalid => 400,
        self::ErrReconMatchingRulesRequired => 400,
        self::ErrReconExternalTxnsRequired => 400,
        self::ErrReconStartFailed => 500,

        self::ErrMetaEntityNotFound => 404,
        self::ErrMetaUnsupportedEntity => 400,
        self::ErrMetaInvalidEntityID => 400,

        self::ErrHookNotFound => 404,
        self::ErrHookInvalid => 400,
        self::ErrHookOperationFailed => 500,

        self::ErrSrchQueryInvalid => 400,
        self::ErrSrchFailed => 500,
        self::ErrSrchReindexInProgress => 409,
        self::ErrSrchReindexNotStarted => 404,

        self::ErrAdminBackupFailed => 500,

        // Legacy codes — same statuses MapErrorToHTTPStatus implied, with the
        // BAD_REQUEST omission fixed (it previously fell through to 500).
        self::ErrNotFound => 404,
        self::ErrConflict => 409,
        self::ErrBadRequest => 400,
        self::ErrInvalidInput => 400,
        self::ErrInternalServer => 500,
        self::ErrRateLimited => 429,
    ];

    /**
     * LEGACY_TO_CANONICAL maps the pre-catalog generic codes to their canonical
     * GEN_* replacements. Internal layers (notably database/) keep constructing
     * errors with the legacy codes; responses always surface canonical ones.
     *
     * @var array<string, string>
     */
    private const LEGACY_TO_CANONICAL = [
        self::ErrNotFound => self::ErrGenNotFound,
        self::ErrConflict => self::ErrGenConflict,
        self::ErrBadRequest => self::ErrGenBadRequest,
        self::ErrInvalidInput => self::ErrGenValidation,
        self::ErrInternalServer => self::ErrGenInternal,
        self::ErrRateLimited => self::ErrGenRateLimited,
    ];

    private function __construct()
    {
    }

    /**
     * Normalize converts a legacy error code to its canonical equivalent.
     * Canonical codes pass through unchanged.
     */
    public static function normalize(string $code): string
    {
        return self::LEGACY_TO_CANONICAL[$code] ?? $code;
    }

    /**
     * StatusForCode returns the default HTTP status for an error code.
     * Unknown codes default to 500.
     */
    public static function statusForCode(string $code): int
    {
        return self::STATUS_BY_CODE[$code] ?? 500;
    }
}
