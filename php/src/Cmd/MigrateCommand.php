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

/*
Package main provides the CLI commands for managing database migrations in the Blnk application.
This includes commands for applying and rolling back migrations.
*/

declare(strict_types=1);

namespace Blnk\Cmd;

use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Database\Datasource;
use Blnk\Internal\Log;

/**
 * MigrateCommand is the port of cmd/migrate.go (`blnk migrate up|down`)
 * together with the parts of github.com/rubenv/sql-migrate it drives.
 *
 * Migration table: exactly what sql-migrate + gorp create for
 * `migrate.SetSchema("blnk")` — `blnk.gorp_migrations` ("id" text primary
 * key = the migration file name, "applied_at" timestamptz) — so a database
 * migrated by the Go binary and one migrated by this command are
 * interchangeable. (This is sql-migrate's table, not golang-migrate's
 * `schema_migrations`; the Go code never used golang-migrate.)
 *
 * Semantics mirrored from sql-migrate v1.7.1:
 *  - migrations are the `.sql` files of {@see Blnk::SQLFiles} (Go: the
 *    embedded `sql/` directory), sorted by their numeric prefix;
 *  - each file is split on `-- +migrate Up` / `-- +migrate Down` sections
 *    (`notransaction` option honored, `-- +migrate StatementBegin` /
 *    `StatementEnd` blocks kept whole, statements otherwise terminated by a
 *    line ending in `;`);
 *  - `up` applies every migration after the last applied one — plus any
 *    older migration missing from the table (catch-up after merges);
 *  - `down` rolls back EVERY applied migration in reverse order
 *    (`migrate.Exec(..., migrate.Down)` has no limit);
 *  - each migration runs in its own transaction unless `notransaction`,
 *    and its record is inserted/deleted in that transaction;
 *  - a migration recorded in the table but absent from `sql/` aborts the
 *    plan ("unknown migration in database"), as sql-migrate does by default.
 */
final class MigrateCommand
{
    public const Use = 'migrate';
    public const Short = 'start blnk migration';

    /** migrate.MigrationDirection */
    public const Up = 0;
    public const Down = 1;

    /** The only dialect the Blnk migrations target (Go: migrate.Exec(db, "postgres", ...)). */
    public const Dialect = 'postgres';

    /** sql-migrate default migration table name. */
    public const DefaultTableName = 'gorp_migrations';

    /** The schema the migrations (and their table) live in (Go: migrate.SetSchema("blnk")). */
    public const Schema = 'blnk';

    /** sqlparse: command prefix of the migration annotations. */
    public const SqlCmdPrefix = '-- +migrate ';
    public const OptionNoTransaction = 'notransaction';

    /** sqlparse.LineSeparator (unset in Go: "" disables the alternative terminator). */
    public const LineSeparator = '';

    private const DirectionNone = -1;

    /** Go: `migSet.SchemaName` (set through migrate.SetSchema). */
    private static string $schemaName = '';

    /** Go: `migSet.TableName` (set through migrate.SetTable). */
    private static string $tableName = self::DefaultTableName;

    /**
     * SetSchema sets the name of a schema that the migration table be referenced.
     */
    public static function setSchema(string $name): void
    {
        if ($name !== '') {
            self::$schemaName = $name;
        }
    }

    /**
     * SetTable sets the name of the migration table.
     */
    public static function setTable(string $name): void
    {
        if ($name !== '') {
            self::$tableName = $name;
        }
    }

    /**
     * runMigrations fetches the configuration, connects to the database, and
     * applies (or rolls back) the embedded SQL migrations in the "blnk" schema.
     * It returns the number of migrations applied.
     *
     * @param int $direction {@see self::Up} or {@see self::Down}
     * @throws \RuntimeException
     */
    public static function runMigrations(int $direction): int
    {
        // Define the source of the migrations.
        $migrations = Blnk::SQLFiles; // Go: EmbedFileSystemMigrationSource{FileSystem: blnk.SQLFiles, Root: "sql"}

        // Fetch the configuration.
        try {
            $cnf = Configuration::fetch();
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error fetching config: %s', $err->getMessage()), 0, $err);
        }

        // Connect to the database.
        try {
            $db = Datasource::connectDB($cnf->dataSource);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error connecting to database: %s', $err->getMessage()), 0, $err);
        }

        try {
            // Set the schema for the migrations. (Previously only the up path set
            // this; a standalone `migrate down` would have targeted the default
            // search_path instead of the blnk schema.)
            self::setSchema(self::Schema);

            return self::exec($db, self::Dialect, $migrations, $direction);
        } finally {
            $db = null; // defer db.Close()
        }
    }

    /**
     * migrateUp is the RunE of the `up` command: applies the pending migrations.
     *
     * @throws \RuntimeException "error migrating up: ..."
     */
    public static function migrateUp(): void
    {
        try {
            $n = self::runMigrations(self::Up);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error migrating up: %s', $err->getMessage()), 0, $err);
        }
        Log::get()->info(sprintf('Applied %d migrations!', $n));
    }

    /**
     * migrateDown is the RunE of the `down` command: rolls back the applied migrations.
     *
     * @throws \RuntimeException "error migrating down: ..."
     */
    public static function migrateDown(): void
    {
        try {
            $n = self::runMigrations(self::Down);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error migrating down: %s', $err->getMessage()), 0, $err);
        }
        Log::get()->info(sprintf('Rolled back %d migrations!', $n));
    }

    // ------------------------------------------------------------------
    // sql-migrate replacement
    // ------------------------------------------------------------------

    /**
     * Exec executes all migrations of the source in the given direction
     * (sql-migrate `Exec` = `ExecMax(..., 0)`: no limit).
     *
     * @param string $sourceRoot directory holding the `.sql` migration files
     * @throws \RuntimeException
     */
    public static function exec(\PDO $db, string $dialect, string $sourceRoot, int $dir): int
    {
        return self::execMax($db, $dialect, $sourceRoot, $dir, 0);
    }

    /**
     * ExecMax executes at most `$max` migrations (0 = all).
     *
     * @throws \RuntimeException
     */
    public static function execMax(\PDO $db, string $dialect, string $sourceRoot, int $dir, int $max): int
    {
        $planned = self::planMigration($db, $dialect, $sourceRoot, $dir, $max);
        return self::applyMigrations($db, $planned, $dir);
    }

    /**
     * PlanMigration computes the migrations to run: unknown-migration check,
     * catch-up of older migrations missing from the table, then the pending
     * ones in the requested direction.
     *
     * @return PlannedMigration[]
     * @throws \RuntimeException
     */
    public static function planMigration(\PDO $db, string $dialect, string $sourceRoot, int $dir, int $max): array
    {
        if ($dialect !== self::Dialect) {
            throw new \RuntimeException(sprintf('Unknown dialect: %s', $dialect));
        }
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // getMigrationDbMap: gorp CreateTablesIfNotExists (schema + table).
        self::ensureMigrationTable($db);

        $migrations = self::findMigrations($sourceRoot);

        $migrationRecords = self::selectRecords($db, false);

        // Sort migrations that have been run by Id.
        $existingMigrations = [];
        foreach ($migrationRecords as $migrationRecord) {
            $existingMigrations[] = new Migration((string) $migrationRecord['id']);
        }
        usort($existingMigrations, [Migration::class, 'compare']);

        // Make sure all migrations in the database are also available in the source
        $migrationsSearch = [];
        foreach ($migrations as $migration) {
            $migrationsSearch[$migration->id] = true;
        }
        foreach ($existingMigrations as $existingMigration) {
            if (!isset($migrationsSearch[$existingMigration->id])) {
                throw new \RuntimeException(sprintf('Unable to create migration plan because of %s: %s', $existingMigration->id, 'unknown migration in database'));
            }
        }

        // Get last migration that was run
        $record = new Migration('');
        if ($existingMigrations !== []) {
            $record = $existingMigrations[\count($existingMigrations) - 1];
        }

        $result = [];

        // Add missing migrations up to the last run migration.
        // This can happen for example when merges happened.
        if ($existingMigrations !== []) {
            $result = array_merge($result, self::toCatchup($migrations, $existingMigrations, $record));
        }

        // Figure out which migrations to apply
        $toApply = self::toApply($migrations, $record->id, $dir);
        $toApplyCount = \count($toApply);
        if ($max > 0 && $max < $toApplyCount) {
            $toApplyCount = $max;
        }
        foreach (\array_slice($toApply, 0, $toApplyCount) as $v) {
            if ($dir === self::Up) {
                $result[] = new PlannedMigration($v, $v->up, $v->disableTransactionUp);
            } elseif ($dir === self::Down) {
                $result[] = new PlannedMigration($v, $v->down, $v->disableTransactionDown);
            }
        }

        return $result;
    }

    /**
     * ToApply filters the migrations to apply after `$current` (Up) or the
     * migrations to roll back up to and including `$current`, in reverse
     * order (Down).
     *
     * @param Migration[] $migrations
     * @return Migration[]
     */
    public static function toApply(array $migrations, string $current, int $direction): array
    {
        $migrations = array_values($migrations);
        $index = -1;
        if ($current !== '') {
            while ($index < \count($migrations) - 1) {
                $index++;
                if ($migrations[$index]->id === $current) {
                    break;
                }
            }
        }

        if ($direction === self::Up) {
            return \array_slice($migrations, $index + 1);
        }
        if ($direction === self::Down) {
            if ($index === -1) {
                return [];
            }
            // Add in reverse order
            $toApply = array_fill(0, $index + 1, null);
            for ($i = 0; $i < $index + 1; $i++) {
                $toApply[$index - $i] = $migrations[$i];
            }
            return $toApply;
        }

        throw new \LogicException('Not possible');
    }

    /**
     * ToCatchup lists the migrations older than the last applied one that are
     * missing from the database, as Up migrations.
     *
     * @param Migration[] $migrations
     * @param Migration[] $existingMigrations
     * @return PlannedMigration[]
     */
    public static function toCatchup(array $migrations, array $existingMigrations, Migration $lastRun): array
    {
        $missing = [];
        foreach ($migrations as $migration) {
            $found = false;
            foreach ($existingMigrations as $existing) {
                if ($existing->id === $migration->id) {
                    $found = true;
                    break;
                }
            }
            if (!$found && $migration->less($lastRun)) {
                $missing[] = new PlannedMigration($migration, $migration->up, $migration->disableTransactionUp);
            }
        }
        return $missing;
    }

    /**
     * FindMigrations reads and parses every `.sql` file of the directory,
     * sorted by id (sql-migrate `findMigrations` over the embedded FS).
     *
     * @return Migration[]
     * @throws \RuntimeException
     */
    public static function findMigrations(string $root): array
    {
        $entries = @scandir($root);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('open %s: no such file or directory', $root));
        }

        $migrations = [];
        foreach ($entries as $name) {
            if (!str_ends_with($name, '.sql') || !is_file($root . '/' . $name)) {
                continue;
            }
            $contents = @file_get_contents($root . '/' . $name);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Error while opening %s: unable to read file', $name));
            }
            try {
                $migrations[] = self::parseMigration($name, $contents);
            } catch (\RuntimeException $err) {
                throw new \RuntimeException(sprintf('Error while parsing %s: %s', $name, $err->getMessage()), 0, $err);
            }
        }

        // Make sure migrations are sorted
        usort($migrations, [Migration::class, 'compare']);

        return $migrations;
    }

    /**
     * ParseMigration parses one migration file (sql-migrate `ParseMigration`
     * over `sqlparse.ParseMigration`).
     *
     * @throws \RuntimeException "Error parsing migration (<id>): ..."
     */
    public static function parseMigration(string $id, string $contents): Migration
    {
        $m = new Migration($id);
        try {
            $parsed = self::parseStatements($contents);
        } catch (\RuntimeException $err) {
            throw new \RuntimeException(sprintf('Error parsing migration (%s): %s', $id, $err->getMessage()), 0, $err);
        }
        $m->up = $parsed['up'];
        $m->down = $parsed['down'];
        $m->disableTransactionUp = $parsed['disableTransactionUp'];
        $m->disableTransactionDown = $parsed['disableTransactionDown'];
        return $m;
    }

    /**
     * GetMigrationRecords returns the applied migration records ordered by id.
     *
     * @return array<int, array{id: string, applied_at: string|null}>
     */
    public static function getMigrationRecords(\PDO $db): array
    {
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        self::ensureMigrationTable($db);
        return self::selectRecords($db, true);
    }

    /**
     * quotedTableForQuery mirrors gorp's PostgresDialect: `schema."table"` (or
     * `"table"` without schema).
     */
    public static function quotedTableForQuery(): string
    {
        if (trim(self::$schemaName) === '') {
            return sprintf('"%s"', self::$tableName);
        }
        return sprintf('%s."%s"', self::$schemaName, self::$tableName);
    }

    /**
     * parseStatements is the port of `sqlparse.ParseMigration`: splits the
     * file into Up/Down statements honoring the migrate annotations.
     *
     * @return array{up: string[], down: string[], disableTransactionUp: bool, disableTransactionDown: bool}
     * @throws \RuntimeException
     */
    private static function parseStatements(string $contents): array
    {
        $upStatements = [];
        $downStatements = [];
        $disableTransactionUp = false;
        $disableTransactionDown = false;

        $buf = '';
        $statementEnded = false;
        $ignoreSemicolons = false;
        $currentDirection = self::DirectionNone;

        // bufio.Scanner (ScanLines): a trailing newline does not produce a final empty line.
        $lines = explode("\n", $contents);
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        foreach ($lines as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            // ignore comment except beginning with '-- +'
            if (str_starts_with($line, '-- ') && !str_starts_with($line, '-- +')) {
                continue;
            }

            // handle any migrate-specific commands
            if (str_starts_with($line, self::SqlCmdPrefix)) {
                $cmd = self::parseCommand($line);

                switch ($cmd['command']) {
                    case 'Up':
                        if (trim($buf) !== '') {
                            throw self::errNoTerminator();
                        }
                        $currentDirection = self::Up;
                        if (\in_array(self::OptionNoTransaction, $cmd['options'], true)) {
                            $disableTransactionUp = true;
                        }
                        break;

                    case 'Down':
                        if (trim($buf) !== '') {
                            throw self::errNoTerminator();
                        }
                        $currentDirection = self::Down;
                        if (\in_array(self::OptionNoTransaction, $cmd['options'], true)) {
                            $disableTransactionDown = true;
                        }
                        break;

                    case 'StatementBegin':
                        if ($currentDirection !== self::DirectionNone) {
                            $ignoreSemicolons = true;
                        }
                        break;

                    case 'StatementEnd':
                        if ($currentDirection !== self::DirectionNone) {
                            $statementEnded = ($ignoreSemicolons === true);
                            $ignoreSemicolons = false;
                        }
                        break;
                }
            }

            if ($currentDirection === self::DirectionNone) {
                continue;
            }

            $isLineSeparator = !$ignoreSemicolons && self::LineSeparator !== '' && $line === self::LineSeparator;

            if (!$isLineSeparator && !str_starts_with($line, '-- +')) {
                $buf .= $line . "\n";
            }

            // Wrap up the two supported cases: 1) basic with semicolon; 2) psql statement
            // Lines that end with semicolon that are in a statement block
            // do not conflict with lines that are not in a statement block.
            if ((!$ignoreSemicolons && (self::endsWithSemicolon($line) || $isLineSeparator)) || $statementEnded) {
                $statementEnded = false;
                switch ($currentDirection) {
                    case self::Up:
                        $upStatements[] = $buf;
                        break;

                    case self::Down:
                        $downStatements[] = $buf;
                        break;

                    default:
                        throw new \LogicException('impossible state');
                }

                $buf = '';
            }
        }

        // diagnose likely migration script errors
        if ($ignoreSemicolons) {
            throw new \RuntimeException("ERROR: saw '-- +migrate StatementBegin' with no matching '-- +migrate StatementEnd'");
        }

        if ($currentDirection === self::DirectionNone) {
            throw new \RuntimeException("ERROR: no Up/Down annotations found, so no statements were executed.\n\t\t\tSee https://github.com/rubenv/sql-migrate for details.");
        }

        // allow comment without sql instruction. Example:
        // -- +migrate Down
        // -- nothing to downgrade!
        if (trim($buf) !== '' && !str_starts_with($buf, '-- +')) {
            throw self::errNoTerminator();
        }

        return [
            'up' => $upStatements,
            'down' => $downStatements,
            'disableTransactionUp' => $disableTransactionUp,
            'disableTransactionDown' => $disableTransactionDown,
        ];
    }

    /**
     * parseCommand splits a `-- +migrate <Command> [options...]` line.
     *
     * @return array{command: string, options: string[]}
     * @throws \RuntimeException
     */
    private static function parseCommand(string $line): array
    {
        if (!str_starts_with($line, self::SqlCmdPrefix)) {
            throw new \RuntimeException('ERROR: not a sql-migrate command');
        }

        $fields = preg_split('/\s+/', substr($line, \strlen(self::SqlCmdPrefix)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ($fields === []) {
            throw new \RuntimeException('ERROR: incomplete migration command');
        }

        return ['command' => $fields[0], 'options' => \array_slice($fields, 1)];
    }

    /**
     * endsWithSemicolon checks whether the last word of the line (before any
     * `--` comment) ends with a semicolon.
     */
    private static function endsWithSemicolon(string $line): bool
    {
        $prev = '';
        foreach (preg_split('/\s+/', $line, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (str_starts_with($word, '--')) {
                break;
            }
            $prev = $word;
        }
        return str_ends_with($prev, ';');
    }

    private static function errNoTerminator(): \RuntimeException
    {
        if (self::LineSeparator === '') {
            return new \RuntimeException("ERROR: The last statement must be ended by a semicolon or '-- +migrate StatementEnd' marker.\n\t\t\tSee https://github.com/rubenv/sql-migrate for details.");
        }
        return new \RuntimeException(sprintf("ERROR: The last statement must be ended by a semicolon, a line whose contents are %s, or '-- +migrate StatementEnd' marker.\n\t\t\tSee https://github.com/rubenv/sql-migrate for details.", json_encode(self::LineSeparator)));
    }

    /**
     * ensureMigrationTable is gorp's `CreateTablesIfNotExists` for the
     * MigrationRecord table (PostgresDialect DDL):
     *   create schema if not exists <schema>;
     *   create table if not exists <schema>."gorp_migrations" ("id" text not null primary key, "applied_at" timestamp with time zone) ;
     */
    private static function ensureMigrationTable(\PDO $db): void
    {
        if (trim(self::$schemaName) !== '') {
            $db->exec(sprintf('create schema if not exists %s;', self::$schemaName));
        }
        $db->exec(sprintf(
            'create table if not exists %s ("id" text not null primary key, "applied_at" timestamp with time zone) ;',
            self::quotedTableForQuery()
        ));
    }

    /**
     * selectRecords reads the migration table (`SELECT * FROM <table>`,
     * ordered by id when requested).
     *
     * @return array<int, array{id: string, applied_at: string|null}>
     */
    private static function selectRecords(\PDO $db, bool $ordered): array
    {
        $query = sprintf('SELECT * FROM %s', self::quotedTableForQuery());
        if ($ordered) {
            $query .= ' ORDER BY "id" ASC';
        }
        $stmt = $db->query($query);
        if ($stmt === false) {
            throw new \RuntimeException('failed to read the migration table');
        }
        $records = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $records[] = ['id' => (string) $row['id'], 'applied_at' => $row['applied_at'] === null ? null : (string) $row['applied_at']];
        }
        return $records;
    }

    /**
     * applyMigrations runs the planned migrations one by one, each in its own
     * transaction unless disabled, recording (Up) or deleting (Down) its row.
     * Returns the number applied; a failure surfaces as sql-migrate's TxError
     * "<error> handling <id>".
     *
     * @param PlannedMigration[] $migrations
     * @throws \RuntimeException
     */
    private static function applyMigrations(\PDO $db, array $migrations, int $dir): int
    {
        $applied = 0;
        foreach ($migrations as $migration) {
            $inTransaction = !$migration->disableTransaction;
            if ($inTransaction) {
                try {
                    $db->beginTransaction();
                } catch (\Throwable $err) {
                    throw self::txError($migration, $err);
                }
            }

            try {
                foreach ($migration->queries as $stmt) {
                    // remove the semicolon from stmt, fix ORA-00922 issue in Oracle
                    $stmt = self::trimSuffix($stmt, "\n");
                    $stmt = self::trimSuffix($stmt, ' ');
                    $stmt = self::trimSuffix($stmt, ';');
                    $db->exec($stmt);
                }

                switch ($dir) {
                    case self::Up:
                        $insert = $db->prepare(sprintf('insert into %s ("id","applied_at") values (?,?);', self::quotedTableForQuery()));
                        $insert->execute([$migration->migration->id, (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s.uP')]);
                        break;
                    case self::Down:
                        $delete = $db->prepare(sprintf('delete from %s where "id"=?;', self::quotedTableForQuery()));
                        $delete->execute([$migration->migration->id]);
                        break;
                    default:
                        throw new \LogicException('directions other than up and down not supported');
                }

                if ($inTransaction) {
                    $db->commit();
                }
            } catch (\Throwable $err) {
                if ($inTransaction && $db->inTransaction()) {
                    try {
                        $db->rollBack();
                    } catch (\Throwable) {
                        // the connection is unusable; the original error is what matters
                    }
                }
                throw self::txError($migration, $err);
            }

            $applied++;
        }

        return $applied;
    }

    /** txError renders sql-migrate's `TxError`: "<err> handling <migration id>". */
    private static function txError(PlannedMigration $migration, \Throwable $err): \RuntimeException
    {
        return new \RuntimeException(sprintf('%s handling %s', $err->getMessage(), $migration->migration->id), 0, $err);
    }

    /** trimSuffix is Go's strings.TrimSuffix: removes one trailing occurrence of `$suffix`. */
    private static function trimSuffix(string $s, string $suffix): string
    {
        if ($suffix !== '' && str_ends_with($s, $suffix)) {
            return substr($s, 0, -\strlen($suffix));
        }
        return $s;
    }
}
