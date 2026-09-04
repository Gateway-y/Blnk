<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * LineageOutbox represents a pending lineage processing task.
 * It is inserted atomically with the main transaction to ensure no lineage work is lost.
 *
 * (Go: model.LineageOutbox, lineage.go — the file's outbox-status and
 * lineage-type constants live here as class consts, keeping their Go names.)
 */
final class LineageOutbox implements \JsonSerializable
{
    // Outbox status constants
    public const OutboxStatusPending = 'pending';
    public const OutboxStatusProcessing = 'processing';
    public const OutboxStatusCompleted = 'completed';
    public const OutboxStatusFailed = 'failed';

    // Lineage type constants
    public const LineageTypeCredit = 'credit';
    public const LineageTypeDebit = 'debit';
    public const LineageTypeBoth = 'both';
    public const LineageTypeShadowCommit = 'shadow_commit';
    public const LineageTypeShadowVoid = 'shadow_void';

    public int $id = 0;

    public string $transactionID = '';

    public string $sourceBalanceID = '';

    public string $destinationBalanceID = '';

    public string $provider = '';

    /** "credit", "debit", "both" */
    public string $lineageType = '';

    /**
     * Go: json.RawMessage — raw JSON bytes. Stored here as the raw JSON string
     * (null stands for a nil RawMessage, which marshals as null).
     */
    public ?string $payload = null;

    public string $status = '';

    public int $attempts = 0;

    public int $maxAttempts = 0;

    public string $lastError = '';

    public ?\DateTimeImmutable $createdAt = null;

    /** Go: *time.Time `json:"processed_at,omitempty"`. */
    public ?\DateTimeImmutable $processedAt = null;

    /** Go: *time.Time `json:"locked_until,omitempty"`. */
    public ?\DateTimeImmutable $lockedUntil = null;

    public bool $inflight = false;

    public static function fromArray(array $data): self
    {
        $o = new self();
        $o->id = (int) ($data['id'] ?? 0);
        $o->transactionID = (string) ($data['transaction_id'] ?? '');
        $o->sourceBalanceID = (string) ($data['source_balance_id'] ?? '');
        $o->destinationBalanceID = (string) ($data['destination_balance_id'] ?? '');
        $o->provider = (string) ($data['provider'] ?? '');
        $o->lineageType = (string) ($data['lineage_type'] ?? '');
        if (\array_key_exists('payload', $data) && $data['payload'] !== null) {
            // json.RawMessage captures the raw JSON of the value; re-encode the
            // decoded value to keep it as a JSON string.
            $o->payload = \is_string($data['payload'])
                ? $data['payload']
                : json_encode($data['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $o->status = (string) ($data['status'] ?? '');
        $o->attempts = (int) ($data['attempts'] ?? 0);
        $o->maxAttempts = (int) ($data['max_attempts'] ?? 0);
        $o->lastError = (string) ($data['last_error'] ?? '');
        $o->createdAt = ModelHelpers::parseTime($data['created_at'] ?? null);
        $o->processedAt = ModelHelpers::parseTime($data['processed_at'] ?? null);
        $o->lockedUntil = ModelHelpers::parseTime($data['locked_until'] ?? null);
        $o->inflight = (bool) ($data['inflight'] ?? false);
        return $o;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['id'] = $this->id;
        $out['transaction_id'] = $this->transactionID;
        if ($this->sourceBalanceID !== '') { // omitempty
            $out['source_balance_id'] = $this->sourceBalanceID;
        }
        if ($this->destinationBalanceID !== '') { // omitempty
            $out['destination_balance_id'] = $this->destinationBalanceID;
        }
        if ($this->provider !== '') { // omitempty
            $out['provider'] = $this->provider;
        }
        $out['lineage_type'] = $this->lineageType;
        // json.RawMessage embeds the raw JSON; decoding here lets json_encode
        // embed the equivalent value ({} vs [] preserved via stdClass).
        $out['payload'] = $this->payload === null ? null : json_decode($this->payload, false);
        $out['status'] = $this->status;
        $out['attempts'] = $this->attempts;
        $out['max_attempts'] = $this->maxAttempts;
        if ($this->lastError !== '') { // omitempty
            $out['last_error'] = $this->lastError;
        }
        $out['created_at'] = ModelHelpers::goTimeString($this->createdAt);
        if ($this->processedAt !== null) { // omitempty (*time.Time)
            $out['processed_at'] = ModelHelpers::goTimeString($this->processedAt);
        }
        if ($this->lockedUntil !== null) { // omitempty (*time.Time)
            $out['locked_until'] = ModelHelpers::goTimeString($this->lockedUntil);
        }
        $out['inflight'] = $this->inflight;
        return $out;
    }
}
