<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * BulkTransactionRequest encapsulates the data needed for a bulk transaction request.
 * (Go: model.BulkTransactionRequest, transaction.go.)
 */
final class BulkTransactionRequest implements \JsonSerializable
{
    /**
     * Go: `[]*Transaction` — a nil slice marshals as null, so the property is
     * nullable.
     *
     * @var Transaction[]|null
     */
    public ?array $transactions = null;

    public bool $inflight = false;

    public bool $atomic = false;

    public bool $runAsync = false;

    public bool $skipQueue = false;

    public static function fromArray(array $data): self
    {
        $r = new self();
        if (isset($data['transactions']) && \is_array($data['transactions'])) {
            $r->transactions = [];
            foreach ($data['transactions'] as $transaction) {
                if (\is_array($transaction)) {
                    $r->transactions[] = Transaction::fromArray($transaction);
                }
            }
        }
        $r->inflight = (bool) ($data['inflight'] ?? false);
        $r->atomic = (bool) ($data['atomic'] ?? false);
        $r->runAsync = (bool) ($data['run_async'] ?? false);
        $r->skipQueue = (bool) ($data['skip_queue'] ?? false);
        return $r;
    }

    public function jsonSerialize(): array
    {
        return [
            'transactions' => $this->transactions === null ? null : array_values($this->transactions),
            'inflight' => $this->inflight,
            'atomic' => $this->atomic,
            'run_async' => $this->runAsync,
            'skip_queue' => $this->skipQueue,
        ];
    }
}
