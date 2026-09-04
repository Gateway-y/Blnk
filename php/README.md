# Blnk — PHP Port

This directory contains a PHP port of the Blnk open-source financial ledger
(the Go implementation lives at the repository root). It aims to be a faithful,
module-by-module translation: same REST API surface, same PostgreSQL schema and
SQL, same double-entry / inflight / refund semantics, same configuration file
(`blnk.json`) and environment overrides.

## Requirements

- PHP >= 8.2 with extensions: `pdo_pgsql`, `pgsql`, `redis` (phpredis),
  `curl`, `openssl`, `mbstring`
- PostgreSQL 16, Redis 7, Typesense (optional, for search)
- Composer

## Quick start

```sh
composer install
cp ../blnk.json blnk.json   # or create one; same format as the Go version

php bin/blnk migrate up     # apply the SQL migrations (same files as Go, sql/)
php bin/blnk start          # start the HTTP API (default port 5001)
php bin/blnk workers        # start the background queue workers
```

Or with Docker:

```sh
docker compose up
```

## Layout

| Directory | Mirrors (Go) | Contents |
|---|---|---|
| `src/Model` | `model/` | Ledger, Balance, Transaction, Identity, … domain types & invariants |
| `src/Config` | `config/` | `blnk.json` + `BLNK_*` env configuration |
| `src/Database` | `database/` | PostgreSQL repositories (verbatim SQL) |
| `src/Core` | root `blnk` package | Services: transactions, inflight, refunds, bulk, reconciliation, webhooks, queue |
| `src/Api` | `api/` | HTTP layer (Slim 4), routes & handlers matching the Go API |
| `src/Internal` | `internal/` | cache, locks, hooks, filters, search, tokenization, notifications, … |
| `src/Cmd` + `bin/blnk` | `cmd/` | CLI: `start`, `workers`, `migrate` |
| `sql/` | `sql/` | The exact migration files from the Go project |

## Intentional divergences

See [PORTING.md](PORTING.md) for the porting conventions and the documented
divergences (asynq → Redis-list workers, OpenTelemetry → no-op tracer,
Typesense via a thin HTTP client, big.Int → brick/math BigInteger).

## License

Apache 2.0, same as the parent project.
