<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * BulkTransactionResult represents the outcome of a bulk transaction operation.
 * (Go: model.BulkTransactionResult, transaction.go.)
 */
final class BulkTransactionResult implements \JsonSerializable
{
    public string $batchID = '';

    /** e.g., "processing", "applied", "inflight", "failed" */
    public string $status = '';

    public int $transactionCount = 0;

    public string $error = '';

    public static function fromArray(array $data): self
    {
        $r = new self();
        $r->batchID = (string) ($data['batch_id'] ?? '');
        $r->status = (string) ($data['status'] ?? '');
        $r->transactionCount = (int) ($data['transaction_count'] ?? 0);
        $r->error = (string) ($data['error'] ?? '');
        return $r;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['batch_id'] = $this->batchID;
        $out['status'] = $this->status;
        if ($this->transactionCount !== 0) { // omitempty
            $out['transaction_count'] = $this->transactionCount;
        }
        if ($this->error !== '') { // omitempty
            $out['error'] = $this->error;
        }
        return $out;
    }
}
