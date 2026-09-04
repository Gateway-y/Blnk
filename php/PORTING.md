# Blnk Go → PHP Porting Conventions

This document defines the binding conventions for porting the Blnk Go codebase
(repository root) into this PHP project (`php/`). Every ported file MUST follow
these rules so the modules produced by different porting passes compose into a
single coherent codebase.

## Language / runtime

- PHP >= 8.2, every file starts with `<?php` + `declare(strict_types=1);`.
- PSR-4 autoloading, namespace root `Blnk\` → `php/src/`.
- After writing any PHP file, run `php -l <file>` and fix errors before moving on.
- Composer deps available: brick/math, ramsey/uuid, monolog/monolog,
  slim/slim + slim/psr7, guzzlehttp/guzzle, dragonmantank/cron-expression,
  phpunit. PHP extensions: pdo_pgsql, pgsql, redis (phpredis), curl, openssl,
  mbstring, sockets. **No bcmath/gmp** — use brick/math for all big numbers.

## Package → namespace mapping

| Go package | PHP namespace | Directory |
|---|---|---|
| `model` | `Blnk\Model` | `src/Model` |
| `config` | `Blnk\Config` | `src/Config` |
| `database` | `Blnk\Database` | `src/Database` |
| `api` | `Blnk\Api` | `src/Api` |
| `api/middleware` | `Blnk\Api\Middleware` | `src/Api/Middleware` |
| `api/model` | `Blnk\Api\Model` | `src/Api/Model` |
| root package `blnk` | `Blnk\Core` | `src/Core` |
| `internal/apierror` | `Blnk\Internal\ApiError` | `src/Internal/ApiError` |
| `internal/cache` | `Blnk\Internal\Cache` | `src/Internal/Cache` |
| `internal/filter` | `Blnk\Internal\Filter` | `src/Internal/Filter` |
| `internal/hooks` | `Blnk\Internal\Hooks` | `src/Internal/Hooks` |
| `internal/lock` | `Blnk\Internal\Lock` | `src/Internal/Lock` |
| `internal/notification` | `Blnk\Internal\Notification` | `src/Internal/Notification` |
| `internal/redis-db` | `Blnk\Internal\Redis` | `src/Internal/Redis` |
| `internal/request` | `Blnk\Internal\Request` | `src/Internal/Request` |
| `internal/search` | `Blnk\Internal\Search` | `src/Internal/Search` |
| `internal/tokenization` | `Blnk\Internal\Tokenization` | `src/Internal/Tokenization` |
| `internal/files` | `Blnk\Internal\Files` | `src/Internal/Files` |
| `internal/hotpairs` | `Blnk\Internal\HotPairs` | `src/Internal/HotPairs` |
| `internal/pg-conn` | `Blnk\Internal\PgConn` | `src/Internal/PgConn` |
| `cmd` | `Blnk\Cmd` | `src/Cmd` (CLI entry: `bin/blnk`) |

File mapping is 1:1 where possible: `database/balance.go` →
`src/Database/BalanceRepository.php` etc. A Go file containing several types
may become several PHP files (one class per file, PSR-4). Keep the Go file's
doc comments, ported to PHPDoc.

## Types

- Go struct → PHP class with **public typed properties**, default values
  matching Go zero values only where the Go code relies on them; otherwise
  nullable.
- `*big.Int` → `Brick\Math\BigInteger` (nullable `?BigInteger`).
- `decimal.Decimal` (shopspring) → `Brick\Math\BigDecimal`.
- `float64` stays `float`; `int64`/`int` → `int`; `time.Time` →
  `\DateTimeImmutable`.
- `map[string]interface{}` → `array`; `[]T` → `array` (PHPDoc `T[]`).
- JSON: every model class implements `\JsonSerializable`; `jsonSerialize()`
  emits **exactly** the Go `json:"..."` tag names (snake_case) with the same
  omitempty semantics. Each model gets a `public static function fromArray(array $data): self`
  used for request binding, honoring the same JSON names.
- BigInteger fields serialize to JSON as **numbers when they fit in int range,
  else strings** — mirror the Go behavior of big.Int marshalling (marshals as a
  JSON number). Use `->toInt()` when within PHP int range, else `(string)`.
- DB timestamps: store/read as UTC. `\DateTimeImmutable` formatted
  `Y-m-d H:i:s.u` (or `DATE_RFC3339` in JSON, matching Go's `time.Time` JSON
  format `2006-01-02T15:04:05Z07:00`).

## Errors

- Go `(T, error)` returns → PHP return `T` and **throw** on error.
- Root exception: `Blnk\Internal\ApiError\ApiErrorException` carrying the same
  error codes as `internal/apierror` (port that package first-class).
- `sql.ErrNoRows` → throw `Blnk\Database\NotFoundException` (subclass of
  ApiErrorException with code NOT_FOUND) — repository callers catch it.
- Wrap PDO errors in `Blnk\Database\DatabaseException` preserving message and
  SQLSTATE; map unique-violation (SQLSTATE 23505) where the Go code checks
  `pq.Error.Code == "23505"`.

## Logging / observability

- logrus → Monolog via static accessor `Blnk\Internal\Log::get()` (channel
  "blnk", stderr handler). `logrus.WithFields(f).Info(msg)` →
  `Log::get()->info($msg, $fields)`.
- OpenTelemetry tracing (`internal/traces`, spans in services) → port as
  no-op-friendly wrapper `Blnk\Internal\Traces\Tracer` with `startSpan/end`
  that only logs at debug level. Do not pull an OTEL SDK.
- `internal/metrics` → simple in-memory counters class with the same method
  names.

## Concurrency

- Goroutines → synchronous calls in-line, unless the Go code's correctness
  depends on background processing; then the work goes through the Redis queue
  and the worker CLI (`blnk workers`).
- `sync.Mutex`/`RWMutex` guarding in-process caches → plain code (PHP request
  model is single-threaded); keep the cache classes and bounds.
- Distributed locking (`internal/lock`, Redis SETNX) → port as-is with
  phpredis.

## Queue (asynq replacement)

Go uses hibiken/asynq. PHP port implements the same semantics on raw Redis:

- `Blnk\Core\Queue` with methods `enqueue`, `enqueueScheduled` mirroring
  `queue.go`. Tasks are JSON payloads on Redis lists
  (`blnk:queue:<queue_name>`), scheduled tasks on a sorted set scored by
  fire-time. Queue names, task type strings, and the
  `transaction_<index>` sharded queues MUST match the Go names exactly.
- Worker loop in `Blnk\Cmd\WorkersCommand`: BRPOPLPUSH into a processing list,
  ack on success, retry count in payload, dead-letter after max retries —
  document divergences from asynq inline.

## HTTP layer

- Gin → Slim 4. Route paths, methods, status codes, and JSON response bodies
  MUST match `api/api.go` and handlers exactly.
- `gin.Context` request binding → `fromArray(json_decode(body))` on the api
  model + the same field validations and error messages.
- Middleware (auth/api-key, rate limit, secure headers) → PSR-15 middleware in
  `src/Api/Middleware` with identical behavior.

## Config

- Port `config/config.go` fully: same JSON file (`blnk.json`), same env
  overrides (`BLNK_*`), same defaults and validation. Singleton
  `Configuration::fetch()` mirrors `config.Fetch()`.

## Naming

- Exported Go func/method `DoThing` → PHP method `doThing` (camelCase);
  unexported `doThing` → private/internal `doThing`.
- Standalone Go funcs in a package land on a class named after the file or an
  existing service class; e.g. `model.GenerateUUIDWithSuffix` →
  `Blnk\Model\ModelHelpers::generateUUIDWithSuffix()`.
- Constants keep their Go names (e.g. `StatusQueued = "QUEUED"`) as class
  consts.
- The root service object `*blnk.Blnk` → `Blnk\Core\Blnk` class; its
  constructor takes the datasource + config exactly like `blnk.NewBlnk`.

## SQL

- All queries are copied **verbatim** from the Go repositories ($1,$2
  placeholders become PDO positional `?` in the same order, or named params —
  but keep the SQL text otherwise identical, including the `blnk.` schema
  prefix).
- Migrations in `php/sql/` are the exact Go `sql/` files; the migrate command
  (`blnk migrate up`) applies them in filename order recording applied ids in
  `blnk.gorp_migrations`, the same table (and row format) the Go sql-migrate
  based `blnk migrate` uses, so both binaries can be pointed at one database.

## What is intentionally adapted (documented divergences)

- asynq → Redis-list worker (above).
- OTEL traces/metrics → no-op logger stubs.
- Typesense client → thin Guzzle client (`Blnk\Internal\Search\TypesenseClient`)
  implementing only the calls Blnk makes.
- S3 backups (`internal/pg-backups`) → shell out to `pg_dump` + optional S3
  upload via signed PUT (curl); document if partially stubbed.
- Kubernetes manifests, Prometheus config: copied unchanged where useful.
