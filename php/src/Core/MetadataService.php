<?php

declare(strict_types=1);

namespace Blnk\Core;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Notification\Notification;

/**
 * Port of metadata.go: entity metadata updates of the Go `Blnk` struct (plus
 * the package-level `getEntityTypeFromID` / `mergeMetadata`), composed into
 * {@see Blnk}. The `ErrEntityNotFound` sentinel is {@see EntityNotFoundException}.
 */
trait MetadataService
{
    /**
     * getEntityTypeFromID determines the entity type from the ID prefix.
     * It analyzes the prefix of the provided ID and returns the corresponding entity type.
     *
     * Parameters:
     * - $id: A string representing the entity ID to analyze.
     *
     * Returns the determined entity type ("transactions", "ledgers", "balances", or "identities").
     *
     * @throws \RuntimeException if the ID format is invalid ("invalid entity ID format: %s").
     */
    protected static function getEntityTypeFromID(string $id): string
    {
        switch (true) {
            case str_starts_with($id, 'txn_'):
                return 'transactions';
            case str_starts_with($id, 'bulk_'):
                return 'transactions';
            case str_starts_with($id, 'ldg_'):
                return 'ledgers';
            case str_starts_with($id, 'bln_'):
                return 'balances';
            case str_starts_with($id, 'idt_'):
                return 'identities';
            default:
                throw new \RuntimeException(sprintf('invalid entity ID format: %s', $id));
        }
    }

    /**
     * UpdateMetadata updates the metadata for a given entity ID.
     * It first determines the entity type, retrieves current metadata, merges it with new metadata,
     * updates the entity with the merged metadata, and queues the updated entity for re-indexing in Typesense.
     *
     * Parameters:
     * - $entityID: A string representing the ID of the entity to update.
     * - $newMetadata: A map containing the new metadata to merge.
     *
     * Returns the merged metadata after the update.
     *
     * The re-indexing Go performs in a goroutine runs inline here.
     *
     * @param array<string, mixed> $newMetadata
     * @return array<string, mixed>
     * @throws EntityNotFoundException when the target entity does not exist.
     * @throws \RuntimeException|ApiErrorException if the update operation fails.
     */
    public function updateMetadata(string $entityID, array $newMetadata): array
    {
        $entityType = self::getEntityTypeFromID($entityID);

        // Check if entity exists first
        switch ($entityType) {
            case 'ledgers':
                try {
                    $ledger = $this->getLedgerByID($entityID);
                } catch (\Throwable) {
                    throw new EntityNotFoundException();
                }
                $currentMetadata = $ledger->metaData;
                $mergedMetadata = self::mergeMetadata($currentMetadata, $newMetadata);
                try {
                    $this->updateEntityMetadata($entityType, $entityID, $mergedMetadata);
                } catch (\Throwable $err) {
                    throw self::wrapError('failed to update metadata', $err);
                }

                // Queue the updated ledger for re-indexing in Typesense
                if ($this->queue !== null) {
                    $updatedLedger = null;
                    try {
                        $updatedLedger = $this->getLedgerByID($entityID);
                    } catch (\Throwable) {
                        // Go: only indexes when the re-fetch succeeds
                    }
                    if ($updatedLedger !== null) {
                        try {
                            $this->queue->queueIndexData($entityID, 'ledgers', $updatedLedger);
                        } catch (\Throwable $err) {
                            Notification::notifyError($err);
                        }
                    }
                }

                return $mergedMetadata;

            case 'transactions':
                // Check if transaction exists either by direct ID or as parent ID
                $exists = $this->datasource->transactionExistsByIDOrParentID($entityID);
                if (!$exists) {
                    throw new EntityNotFoundException();
                }

                // Apply metadata updates directly without trying to get current metadata
                // This preserves existing metadata in child transactions
                try {
                    $this->updateEntityMetadata($entityType, $entityID, $newMetadata);
                } catch (\Throwable $err) {
                    throw self::wrapError('failed to update metadata', $err);
                }

                // Queue the updated transaction for re-indexing in Typesense
                if ($this->queue !== null) {
                    $updatedTransaction = null;
                    try {
                        $updatedTransaction = $this->getTransaction($entityID);
                    } catch (\Throwable) {
                        // Go: only indexes when the re-fetch succeeds
                    }
                    if ($updatedTransaction !== null) {
                        try {
                            $this->queue->queueIndexData($entityID, 'transactions', $updatedTransaction);
                        } catch (\Throwable $err) {
                            Notification::notifyError($err);
                        }
                    }
                }

                return $newMetadata;

            case 'balances':
                try {
                    $balance = $this->getBalanceByID($entityID, [], false);
                } catch (\Throwable) {
                    throw new EntityNotFoundException();
                }
                $currentMetadata = $balance->metaData;
                $mergedMetadata = self::mergeMetadata($currentMetadata, $newMetadata);
                try {
                    $this->updateEntityMetadata($entityType, $entityID, $mergedMetadata);
                } catch (\Throwable $err) {
                    throw self::wrapError('failed to update metadata', $err);
                }

                if ($this->queue !== null) {
                    // Queue the updated balance for re-indexing in Typesense
                    $updatedBalance = null;
                    try {
                        $updatedBalance = $this->getBalanceByID($entityID, [], false);
                    } catch (\Throwable) {
                        // Go: only indexes when the re-fetch succeeds
                    }
                    if ($updatedBalance !== null) {
                        try {
                            $this->queue->queueIndexData($entityID, 'balances', $updatedBalance);
                        } catch (\Throwable $err) {
                            Notification::notifyError($err);
                        }
                    }
                }

                return $mergedMetadata;

            case 'identities':
                try {
                    $identity = $this->getIdentity($entityID);
                } catch (\Throwable) {
                    throw new EntityNotFoundException();
                }
                $currentMetadata = $identity->metaData;
                $mergedMetadata = self::mergeMetadata($currentMetadata, $newMetadata);
                try {
                    $this->updateEntityMetadata($entityType, $entityID, $mergedMetadata);
                } catch (\Throwable $err) {
                    throw self::wrapError('failed to update metadata', $err);
                }

                // Queue the updated identity for re-indexing in Typesense
                if ($this->queue !== null) {
                    $updatedIdentity = null;
                    try {
                        $updatedIdentity = $this->getIdentity($entityID);
                    } catch (\Throwable) {
                        // Go: only indexes when the re-fetch succeeds
                    }
                    if ($updatedIdentity !== null) {
                        try {
                            $this->queue->queueIndexData($entityID, 'identities', $updatedIdentity);
                        } catch (\Throwable $err) {
                            Notification::notifyError($err);
                        }
                    }
                }

                return $mergedMetadata;

            default:
                throw new \RuntimeException(sprintf('unsupported entity type: %s', $entityType));
        }
    }

    /**
     * mergeMetadata merges new metadata with existing metadata.
     * If the current metadata is nil, it initializes a new map.
     *
     * Parameters:
     * - $current: The existing metadata map.
     * - $new: The new metadata map to merge.
     *
     * Returns the merged metadata map.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    protected static function mergeMetadata(?array $current, array $new): array
    {
        if ($current === null) {
            $current = [];
        }

        foreach ($new as $k => $v) {
            $current[$k] = $v;
        }

        return $current;
    }

    /**
     * updateEntityMetadata updates the metadata for a specific entity.
     * It routes the update operation to the appropriate datasource method based on the entity type.
     *
     * Parameters:
     * - $entityType: The type of entity being updated.
     * - $entityID: The ID of the entity being updated.
     * - $metadata: The new metadata to set.
     *
     * @param array<string, mixed> $metadata
     * @throws \RuntimeException for an unsupported entity type ("unsupported entity type: %s").
     * @throws ApiErrorException if the update operation fails.
     */
    protected function updateEntityMetadata(string $entityType, string $entityID, array $metadata): void
    {
        switch ($entityType) {
            case 'ledgers':
                $this->datasource->updateLedgerMetadata($entityID, $metadata);
                return;

            case 'transactions':
                $this->datasource->updateTransactionMetadata($entityID, $metadata);
                return;

            case 'balances':
                $this->datasource->updateBalanceMetadata($entityID, $metadata);
                return;

            case 'identities':
                $this->datasource->updateIdentityMetadata($entityID, $metadata);
                return;

            default:
                throw new \RuntimeException(sprintf('unsupported entity type: %s', $entityType));
        }
    }
}
