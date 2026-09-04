<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Distribution describes one leg of a multi-source / multi-destination
 * transaction split (Go: model.Distribution, transaction.go).
 */
final class Distribution implements \JsonSerializable
{
    public string $identifier = '';

    /** Can be a percentage (e.g., "10%"), a fixed amount (e.g., "100"), or "left" */
    public string $distribution = '';

    /** Fixed amount in minor units (e.g., "1006" for 1006 cents) */
    public string $preciseDistribution = '';

    public string $transactionID = '';

    /**
     * isLeftDistribution checks if a distribution is a "left" type.
     *
     * @internal unexported in Go; public so ModelHelpers can reach it.
     */
    public function isLeftDistribution(): bool
    {
        return $this->distribution === 'left' || $this->preciseDistribution === 'left';
    }

    /**
     * isPercentageDistribution checks if a distribution is a percentage type.
     *
     * @internal unexported in Go; public so ModelHelpers can reach it.
     */
    public function isPercentageDistribution(): bool
    {
        return str_ends_with($this->distribution, '%');
    }

    public static function fromArray(array $data): self
    {
        $d = new self();
        $d->identifier = (string) ($data['identifier'] ?? '');
        $d->distribution = (string) ($data['distribution'] ?? '');
        $d->preciseDistribution = (string) ($data['precise_distribution'] ?? '');
        $d->transactionID = (string) ($data['transaction_id'] ?? '');
        return $d;
    }

    public function jsonSerialize(): array
    {
        $out = [];
        $out['identifier'] = $this->identifier;
        $out['distribution'] = $this->distribution;
        if ($this->preciseDistribution !== '') { // omitempty
            $out['precise_distribution'] = $this->preciseDistribution;
        }
        $out['transaction_id'] = $this->transactionID;
        return $out;
    }
}
