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

namespace Blnk\Database;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\Filter\QueryFilterSet;
use Blnk\Internal\Filter\QueryOptions;
use Blnk\Model\Account;
use Blnk\Model\APIKey;
use Blnk\Model\Balance;
use Blnk\Model\BalanceMonitor;
use Blnk\Model\ChainedTransaction;
use Blnk\Model\ChainState;
use Blnk\Model\ExternalTransaction;
use Blnk\Model\Identity;
use Blnk\Model\Ledger;
use Blnk\Model\LineageMapping;
use Blnk\Model\LineageOutbox;
use Blnk\Model\MatchingRule;
use Blnk\Model\Reconciliation;
use Blnk\Model\ReconciliationMatch;
use Blnk\Model\ReconciliationProgress;
use Blnk\Model\Transaction;
use Brick\Math\BigInteger;

/**
 * IDataSource defines the interface for data source operations, grouping related functionalities.
 *
 * Port of Go `database.IDataSource` (database/repository.go). The Go composite
 * interface embeds the unexported interfaces `transaction`, `ledger`,
 * `balance`, `identity`, `balanceMonitor`, `account`, `reconciliation`,
 * `apikey`, `lineage` and `chain`; PHP has no unexported interfaces, so every
 * embedded interface's methods are inlined here, grouped under the Go
 * interface's name, in the order `IDataSource` embeds them; within a group the
 * Go method order is kept.
 *
 * Signature conventions (see PORTING.md):
 * - `context.Context` parameters are dropped.
 * - `(T, error)` becomes `T` plus `@throws`: `sql.ErrNoRows` surfaces as
 *   {@see NotFoundException}, other PDO failures as {@see DatabaseException},
 *   both subclasses of {@see ApiErrorException} (the port of `apierror`).
 *   Every method may therefore throw {@see ApiErrorException}.
 * - `(T, *int64, error)` (the `...WithFilterAndOptions` methods) becomes the
 *   pair `[T[], ?int]` — `[$items, $totalCount]`, where `$totalCount` is null
 *   unless `$opts->includeCount` was requested (Go: `*int64` nil).
 * - Go pointer results that are legitimately returned as `nil, nil` (found
 *   nothing, no error) are nullable; every other object result is non-null.
 * - `*model.X` / `model.X` → `X`; `[]*model.X` / `[]model.X` → `X[]` (list);
 *   `map[string]V` → `array<string, V>`; `map[string]struct{}` → `array<string, true>`.
 * - `*big.Int` → {@see BigInteger}; `time.Time` → `\DateTimeImmutable`;
 *   `time.Duration` → seconds (`int|float`); `int64` → `int`.
 * - `model.Match` is {@see ReconciliationMatch} (`match` is reserved in PHP).
 * - `*sql.Tx` → the `\PDO` connection on which `beginTransaction()` is open
 *   (PDO carries the transaction on the connection itself).
 */
interface DataSourceInterface
{
    // ------------------------------------------------------------------
    // transaction defines methods for handling transactions.
    // ------------------------------------------------------------------

    /**
     * Records a new transaction
     *
     * @throws ApiErrorException
     */
    public function recordTransaction(Transaction $txn): Transaction;

    /**
     * Records a transaction with balance updates atomically
     *
     * @throws ApiErrorException
     */
    public function recordTransactionWithBalances(Transaction $txn, Balance $sourceBalance, Balance $destinationBalance): Transaction;

    /**
     * Records a transaction with balance updates and optional lineage outbox atomically
     *
     * @param LineageOutbox|null $outbox Optional lineage outbox entry to insert atomically (can be null if no lineage processing needed).
     * @throws ApiErrorException
     */
    public function recordTransactionWithBalancesAndOutbox(Transaction $txn, Balance $sourceBalance, Balance $destinationBalance, ?LineageOutbox $outbox): Transaction;

    /**
     * RecordTransactionsWithBalancesAndOutboxes atomically records multiple transactions, updates
     * the source and destination balances once, and inserts any lineage outbox entries in the same
     * database transaction.
     *
     * @param Transaction[] $txns
     * @param (LineageOutbox|null)[] $outboxes nil entries are skipped, as in Go
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function recordTransactionsWithBalancesAndOutboxes(array $txns, ?Balance $sourceBalance, ?Balance $destinationBalance, array $outboxes): array;

    /**
     * RecordTransactionsWithBalanceSetAndOutboxes atomically records multiple transactions, updates
     * all changed balances, and inserts any lineage outbox entries in the same database transaction.
     *
     * @param Transaction[] $txns
     * @param (Balance|null)[] $balances nil / empty-ID entries are skipped, as in Go
     * @param (LineageOutbox|null)[] $outboxes nil entries are skipped, as in Go
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function recordTransactionsWithBalanceSetAndOutboxes(array $txns, array $balances, array $outboxes): array;

    /**
     * Retrieves a transaction by ID
     *
     * @throws NotFoundException when no transaction has the given ID
     * @throws ApiErrorException
     */
    public function getTransaction(string $id): Transaction;

    /**
     * Checks if a parent transaction is void
     *
     * @throws ApiErrorException
     */
    public function isParentTransactionVoid(string $parentID): bool;

    /**
     * Retrieves a transaction by reference
     *
     * @throws NotFoundException when no transaction has the given reference
     * @throws ApiErrorException
     */
    public function getTransactionByRef(string $reference): Transaction;

    /**
     * Checks if a transaction exists by reference
     *
     * @throws ApiErrorException
     */
    public function transactionExistsByRef(string $reference): bool;

    /**
     * Gets existing transaction references in bulk
     *
     * @param string[] $references
     * @return array<string, true> The set of references that already exist (Go: map[string]struct{}).
     * @throws ApiErrorException
     */
    public function getExistingTransactionReferences(array $references): array;

    /**
     * Retrieves all transactions
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getAllTransactions(int $limit, int $offset): array;

    /**
     * Gets the total count of committed transactions for a parent
     *
     * @throws ApiErrorException
     */
    public function getTotalCommittedTransactions(string $parentID): BigInteger;

    /**
     * Retrieves transactions in a paginated manner
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getTransactionsPaginated(string $id, int $batchSize, int $offset): array;

    /**
     * Retrieves inflight transactions by parent ID
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getInflightTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array;

    /**
     * Retrieves refundable transactions by parent ID
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getRefundableTransactionsByParentID(string $parentTransactionID, int $batchSize, int $offset): array;

    /**
     * Groups transactions based on specified criteria
     *
     * @return array<string, Transaction[]> Transactions keyed by the value of the grouping criterion.
     * @throws ApiErrorException
     */
    public function groupTransactions(string $groupCriteria, int $batchSize, int $offset): array;

    /**
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     */
    public function updateLedgerMetadata(string $id, array $metadata): void;

    /**
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     */
    public function updateTransactionMetadata(string $id, array $metadata): void;

    /**
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     */
    public function updateBalanceMetadata(string $id, array $metadata): void;

    /**
     * @param array<string, mixed> $metadata
     * @throws ApiErrorException
     */
    public function updateIdentityMetadata(string $id, array $metadata): void;

    /**
     * @throws ApiErrorException
     */
    public function transactionExistsByIDOrParentID(string $id): bool;

    /**
     * Retrieves transactions by parent ID with pagination
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getTransactionsByParent(string $parentID, int $limit, int $offset): array;

    /**
     * Checks if a transaction has already been refunded
     *
     * @throws ApiErrorException
     */
    public function isTransactionRefunded(Transaction $transaction): bool;

    /**
     * Go: `GetTransactionsByCriteria(ctx, minAmount, maxAmount *float64, currency *string, minDate, maxDate *time.Time, limit int, offset int64)`.
     * Each nullable criterion is only applied when non-null (Go: non-nil pointer).
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getTransactionsByCriteria(?float $minAmount, ?float $maxAmount, ?string $currency, ?\DateTimeImmutable $minDate, ?\DateTimeImmutable $maxDate, int $limit, int $offset): array;

    /**
     * Retrieves shadow transactions by parent transaction ID
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getTransactionsByShadowFor(string $parentTransactionID): array;

    /**
     * Retrieves stuck QUEUED transactions with no child
     *
     * @param int|float $threshold Go `time.Duration`, expressed in seconds (the cutoff is now - threshold).
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getStuckQueuedTransactions(int|float $threshold, int $batchSize): array;

    /**
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getQueuedTransactionsForCoalescing(string $source, string $destination, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array;

    /**
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getQueuedTransactionsForSourceCoalescing(string $source, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array;

    /**
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getQueuedTransactionsForDestinationCoalescing(string $destination, string $currency, string $excludeTransactionID, \DateTimeImmutable $createdAtOrAfter, int $limit): array;

    /**
     * @throws ApiErrorException
     */
    public function countQueuedTransactionsForPairLane(string $source, string $destination, string $currency, string $lane): int;

    // Advanced filtering methods

    /**
     * Retrieves transactions with advanced filtering
     *
     * @return Transaction[]
     * @throws ApiErrorException
     */
    public function getAllTransactionsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array;

    /**
     * Retrieves transactions with filtering, sorting, and count
     *
     * Go: `([]model.Transaction, *int64, error)`.
     *
     * @return array{0: Transaction[], 1: int|null} `[$transactions, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws ApiErrorException
     */
    public function getAllTransactionsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array;

    // ------------------------------------------------------------------
    // ledger defines methods for handling ledgers.
    // ------------------------------------------------------------------

    /**
     * Creates a new ledger
     *
     * @throws ApiErrorException
     */
    public function createLedger(Ledger $ledger): Ledger;

    /**
     * Retrieves all ledgers (legacy)
     *
     * @return Ledger[]
     * @throws ApiErrorException
     */
    public function getAllLedgers(int $limit, int $offset): array;

    /**
     * Retrieves a ledger by ID
     *
     * @throws NotFoundException when no ledger has the given ID
     * @throws ApiErrorException
     */
    public function getLedgerByID(string $id): Ledger;

    /**
     * Updates a ledger's name
     *
     * @throws NotFoundException when no ledger has the given ID
     * @throws ApiErrorException
     */
    public function updateLedger(string $id, string $name): Ledger;

    // Advanced filtering methods

    /**
     * Retrieves ledgers with advanced filtering
     *
     * @return Ledger[]
     * @throws ApiErrorException
     */
    public function getAllLedgersWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array;

    /**
     * Retrieves ledgers with filtering, sorting, and count
     *
     * Go: `([]model.Ledger, *int64, error)`.
     *
     * @return array{0: Ledger[], 1: int|null} `[$ledgers, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws ApiErrorException
     */
    public function getAllLedgersWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array;

    // ------------------------------------------------------------------
    // balance defines methods for handling balances.
    // ------------------------------------------------------------------

    /**
     * Creates a new balance
     *
     * @throws ApiErrorException
     */
    public function createBalance(Balance $balance): Balance;

    /**
     * Retrieves a balance by ID with additional data and queued status
     *
     * @param string[] $include
     * @throws NotFoundException when no balance has the given ID
     * @throws ApiErrorException
     */
    public function getBalanceByID(string $id, array $include, bool $withQueued): Balance;

    /**
     * Retrieves a balance by ID with minimal data
     *
     * @throws NotFoundException when no balance has the given ID
     * @throws ApiErrorException
     */
    public function getBalanceByIDLite(string $id): Balance;

    /**
     * Retrieves multiple balances by IDs with minimal data (batch query)
     *
     * @param string[] $ids
     * @return array<string, Balance> Balances keyed by balance ID (Go: map[string]*model.Balance).
     * @throws ApiErrorException
     */
    public function getBalancesByIDsLite(array $ids): array;

    /**
     * Retrieves all balances (legacy)
     *
     * @return Balance[]
     * @throws ApiErrorException
     */
    public function getAllBalances(int $limit, int $offset): array;

    /**
     * Updates a balance
     *
     * @throws ApiErrorException
     */
    public function updateBalance(Balance $balance): void;

    /**
     * Retrieves a balance by indicator and currency
     *
     * @throws NotFoundException when no balance matches
     * @throws ApiErrorException
     */
    public function getBalanceByIndicator(string $indicator, string $currency): Balance;

    /**
     * Updates multiple balances
     *
     * @throws ApiErrorException
     */
    public function updateBalances(Balance $sourceBalance, Balance $destinationBalance): void;

    /**
     * Retrieves balances between source and destination
     *
     * @return Balance[]
     * @throws ApiErrorException
     */
    public function getSourceDestination(string $sourceId, string $destinationId): array;

    /**
     * Takes balance snapshots
     *
     * @return int The number of snapshots taken.
     * @throws ApiErrorException
     */
    public function takeBalanceSnapshots(int $batchSize): int;

    /**
     * Retrieves a balance at a specific time
     *
     * @throws ApiErrorException
     */
    public function getBalanceAtTime(string $balanceID, \DateTimeImmutable $targetTime, bool $fromSource): Balance;

    /**
     * Updates only the identity_id of a balance
     *
     * @throws ApiErrorException
     */
    public function updateBalanceIdentity(string $balanceID, string $identityID): void;

    // Advanced filtering methods

    /**
     * Retrieves balances with advanced filtering
     *
     * @return Balance[]
     * @throws ApiErrorException
     */
    public function getAllBalancesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array;

    /**
     * Retrieves balances with filtering, sorting, and count
     *
     * Go: `([]model.Balance, *int64, error)`.
     *
     * @return array{0: Balance[], 1: int|null} `[$balances, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws ApiErrorException
     */
    public function getAllBalancesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array;

    // ------------------------------------------------------------------
    // identity defines methods for handling identities.
    // ------------------------------------------------------------------

    /**
     * Creates a new identity
     *
     * @throws ApiErrorException
     */
    public function createIdentity(Identity $identity): Identity;

    /**
     * Retrieves an identity by ID
     *
     * @throws NotFoundException when no identity has the given ID
     * @throws ApiErrorException
     */
    public function getIdentityByID(string $id): Identity;

    /**
     * Retrieves all identities (legacy)
     *
     * @return Identity[]
     * @throws ApiErrorException
     */
    public function getAllIdentities(): array;

    /**
     * Retrieves identities with pagination (legacy)
     *
     * @return Identity[]
     * @throws ApiErrorException
     */
    public function getAllIdentitiesPaginated(int $limit, int $offset): array;

    /**
     * Updates an identity
     *
     * @throws ApiErrorException
     */
    public function updateIdentity(Identity $identity): void;

    /**
     * Deletes an identity
     *
     * @throws ApiErrorException
     */
    public function deleteIdentity(string $id): void;

    // Advanced filtering methods

    /**
     * Retrieves identities with advanced filtering
     *
     * @return Identity[]
     * @throws ApiErrorException
     */
    public function getAllIdentitiesWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array;

    /**
     * Retrieves identities with filtering, sorting, and count
     *
     * Go: `([]model.Identity, *int64, error)`.
     *
     * @return array{0: Identity[], 1: int|null} `[$identities, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws ApiErrorException
     */
    public function getAllIdentitiesWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array;

    // ------------------------------------------------------------------
    // balanceMonitor defines methods for monitoring balances.
    // ------------------------------------------------------------------

    /**
     * Creates a new balance monitor
     *
     * @throws ApiErrorException
     */
    public function createMonitor(BalanceMonitor $monitor): BalanceMonitor;

    /**
     * Retrieves a balance monitor by ID
     *
     * @throws NotFoundException when no monitor has the given ID
     * @throws ApiErrorException
     */
    public function getMonitorByID(string $id): BalanceMonitor;

    /**
     * Retrieves all balance monitors
     *
     * @return BalanceMonitor[]
     * @throws ApiErrorException
     */
    public function getAllMonitors(): array;

    /**
     * Retrieves monitors for a specific balance
     *
     * @return BalanceMonitor[]
     * @throws ApiErrorException
     */
    public function getBalanceMonitors(string $balanceID): array;

    /**
     * Updates a balance monitor
     *
     * @throws ApiErrorException
     */
    public function updateMonitor(BalanceMonitor $monitor): void;

    /**
     * Deletes a balance monitor
     *
     * @throws ApiErrorException
     */
    public function deleteMonitor(string $id): void;

    // ------------------------------------------------------------------
    // account defines methods for handling accounts.
    // ------------------------------------------------------------------

    /**
     * Creates a new account
     *
     * @throws ApiErrorException
     */
    public function createAccount(Account $account): Account;

    /**
     * Retrieves an account by ID with additional data
     *
     * @param string[] $include
     * @throws NotFoundException when no account has the given ID
     * @throws ApiErrorException
     */
    public function getAccountByID(string $id, array $include): Account;

    /**
     * Retrieves all accounts (legacy)
     *
     * @return Account[]
     * @throws ApiErrorException
     */
    public function getAllAccounts(): array;

    /**
     * Retrieves an account by its number
     *
     * @throws NotFoundException when no account has the given number
     * @throws ApiErrorException
     */
    public function getAccountByNumber(string $number): Account;

    /**
     * Updates an account
     *
     * @throws ApiErrorException
     */
    public function updateAccount(Account $account): void;

    /**
     * Deletes an account
     *
     * @throws ApiErrorException
     */
    public function deleteAccount(string $id): void;

    // Advanced filtering methods

    /**
     * Retrieves accounts with advanced filtering
     *
     * @return Account[]
     * @throws ApiErrorException
     */
    public function getAllAccountsWithFilter(?QueryFilterSet $filters, int $limit, int $offset): array;

    /**
     * Retrieves accounts with filtering, sorting, and count
     *
     * Go: `([]model.Account, *int64, error)`.
     *
     * @return array{0: Account[], 1: int|null} `[$accounts, $totalCount]`; `$totalCount` is null unless `$opts->includeCount`.
     * @throws ApiErrorException
     */
    public function getAllAccountsWithFilterAndOptions(?QueryFilterSet $filters, ?QueryOptions $opts, int $limit, int $offset): array;

    // ------------------------------------------------------------------
    // reconciliation defines methods for handling reconciliation processes.
    // ------------------------------------------------------------------

    /**
     * Records a new reconciliation
     *
     * @throws ApiErrorException
     */
    public function recordReconciliation(Reconciliation $rec): void;

    /**
     * Retrieves a reconciliation by ID
     *
     * @throws NotFoundException when no reconciliation has the given ID
     * @throws ApiErrorException
     */
    public function getReconciliation(string $id): Reconciliation;

    /**
     * Updates the status of a reconciliation
     *
     * @throws ApiErrorException
     */
    public function updateReconciliationStatus(string $id, string $status, int $matchedCount, int $unmatchedCount): void;

    /**
     * Retrieves reconciliations by upload ID
     *
     * @return Reconciliation[]
     * @throws ApiErrorException
     */
    public function getReconciliationsByUploadID(string $uploadID): array;

    /**
     * Records a match in reconciliation
     *
     * @throws ApiErrorException
     */
    public function recordMatch(ReconciliationMatch $match): void;

    /**
     * Retrieves matches by reconciliation ID
     *
     * @return ReconciliationMatch[]
     * @throws ApiErrorException
     */
    public function getMatchesByReconciliationID(string $reconciliationID): array;

    /**
     * Retrieves external transactions in a paginated manner
     *
     * @return ExternalTransaction[]
     * @throws ApiErrorException
     */
    public function getExternalTransactionsPaginated(string $uploadID, int $batchSize, int $offset): array;

    /**
     * Records an external transaction
     *
     * @throws ApiErrorException
     */
    public function recordExternalTransaction(ExternalTransaction $tx, string $reconciliationID): void;

    /**
     * Records a matching rule
     *
     * @throws ApiErrorException
     */
    public function recordMatchingRule(MatchingRule $rule): void;

    /**
     * Retrieves all matching rules
     *
     * @return MatchingRule[]
     * @throws ApiErrorException
     */
    public function getMatchingRules(): array;

    /**
     * Retrieves a matching rule by ID
     *
     * @throws NotFoundException when no matching rule has the given ID
     * @throws ApiErrorException
     */
    public function getMatchingRule(string $id): MatchingRule;

    /**
     * Updates a matching rule
     *
     * @throws ApiErrorException
     */
    public function updateMatchingRule(MatchingRule $rule): void;

    /**
     * Deletes a matching rule
     *
     * @throws ApiErrorException
     */
    public function deleteMatchingRule(string $id): void;

    /**
     * Saves reconciliation progress
     *
     * @throws ApiErrorException
     */
    public function saveReconciliationProgress(string $reconciliationID, ReconciliationProgress $progress): void;

    /**
     * Loads reconciliation progress
     *
     * Go returns an empty `model.ReconciliationProgress{}` (no error) when no
     * progress row exists; the PHP port returns a fresh, zero-valued object.
     *
     * @throws ApiErrorException
     */
    public function loadReconciliationProgress(string $reconciliationID): ReconciliationProgress;

    /**
     * Records matches for a reconciliation
     *
     * @param ReconciliationMatch[] $matches
     * @throws ApiErrorException
     */
    public function recordMatches(string $reconciliationID, array $matches): void;

    /**
     * Records unmatched results for a reconciliation
     *
     * @param string[] $results
     * @throws ApiErrorException
     */
    public function recordUnmatched(string $reconciliationID, array $results): void;

    /**
     * Fetches and groups external transactions based on criteria
     *
     * @return array<string, Transaction[]> Transactions keyed by the value of the grouping criterion.
     * @throws ApiErrorException
     */
    public function fetchAndGroupExternalTransactions(string $uploadID, string $groupCriteria, int $batchSize, int $offset): array;

    // ------------------------------------------------------------------
    // apikey
    // ------------------------------------------------------------------

    /**
     * Creates a new API key
     *
     * @param string[] $scopes
     * @throws ApiErrorException
     */
    public function createAPIKey(string $name, string $ownerID, array $scopes, \DateTimeImmutable $expiresAt): APIKey;

    /**
     * Retrieves an API key by its key string
     *
     * @throws NotFoundException when no API key matches
     * @throws ApiErrorException
     */
    public function getAPIKey(string $key): APIKey;

    /**
     * Revokes an API key
     *
     * @throws ApiErrorException
     */
    public function revokeAPIKey(string $id, string $ownerID): void;

    /**
     * Lists all API keys for a specific owner
     *
     * @return APIKey[]
     * @throws ApiErrorException
     */
    public function listAPIKeys(string $ownerID): array;

    /**
     * Updates the last_used_at timestamp for an API key
     *
     * @throws ApiErrorException
     */
    public function updateLastUsed(string $id): void;

    // ------------------------------------------------------------------
    // lineage defines methods for fund lineage tracking operations.
    // ------------------------------------------------------------------

    /**
     * Creates or updates a lineage mapping
     *
     * @throws ApiErrorException
     */
    public function upsertLineageMapping(LineageMapping $mapping): void;

    /**
     * Retrieves all lineage mappings for a balance
     *
     * @return LineageMapping[]
     * @throws ApiErrorException
     */
    public function getLineageMappings(string $balanceID): array;

    /**
     * Retrieves a specific lineage mapping
     *
     * @return LineageMapping|null null when no mapping exists (Go: `nil, nil` on sql.ErrNoRows).
     * @throws ApiErrorException
     */
    public function getLineageMappingByProvider(string $balanceID, string $provider): ?LineageMapping;

    /**
     * Deletes a lineage mapping
     *
     * @throws ApiErrorException
     */
    public function deleteLineageMapping(int $id): void;

    // Outbox methods for atomic lineage processing

    /**
     * Inserts outbox entry within a transaction
     *
     * @param \PDO $tx Go `*sql.Tx`: the connection whose `beginTransaction()` is currently open.
     * @throws ApiErrorException
     */
    public function insertLineageOutboxInTx(\PDO $tx, LineageOutbox $outbox): void;

    /**
     * Inserts outbox entry directly (for shadow work)
     *
     * @throws ApiErrorException
     */
    public function insertLineageOutbox(LineageOutbox $outbox): void;

    /**
     * Claims pending entries for processing
     *
     * @param int|float $lockDuration Go `time.Duration`, expressed in seconds.
     * @return LineageOutbox[]
     * @throws ApiErrorException
     */
    public function claimPendingOutboxEntries(int $batchSize, int|float $lockDuration): array;

    /**
     * Marks an outbox entry as completed
     *
     * @throws ApiErrorException
     */
    public function markOutboxCompleted(int $id): void;

    /**
     * Marks an outbox entry as failed
     *
     * @throws ApiErrorException
     */
    public function markOutboxFailed(int $id, string $errMsg): void;

    /**
     * Gets outbox entry by transaction ID
     *
     * @return LineageOutbox|null null when no entry exists (Go: `nil, nil` on sql.ErrNoRows).
     * @throws ApiErrorException
     */
    public function getOutboxByTransactionID(string $transactionID): ?LineageOutbox;

    /**
     * Checks if there are pending credit outbox entries for a balance
     *
     * @throws ApiErrorException
     */
    public function hasPendingCreditOutbox(string $balanceID): bool;

    // ------------------------------------------------------------------
    // chain defines the hash-chain (tamper-evidence) operations.
    // ------------------------------------------------------------------

    /**
     * Seals the next batch of unchained transactions
     *
     * @return int The number of transactions chained in this batch.
     * @throws ApiErrorException
     */
    public function chainPendingTransactions(\DateTimeImmutable $cutoff, int $batchSize): int;

    /**
     * Returns the global chain bookmark
     *
     * @throws ApiErrorException
     */
    public function getChainState(): ChainState;

    /**
     * Pages chained transactions in chain order
     *
     * @return ChainedTransaction[]
     * @throws ApiErrorException
     */
    public function getChainedTransactionsAfter(int $afterSeq, int $limit): array;

    /**
     * Counts the chainer backlog
     *
     * @throws ApiErrorException
     */
    public function countUnchainedTransactions(\DateTimeImmutable $cutoff): int;
}
