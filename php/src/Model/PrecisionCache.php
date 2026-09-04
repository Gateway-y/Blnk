<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * PrecisionCache stores transaction precisions fetched from the database.
 * It is read-through over the database (the source of truth) and is bounded.
 *
 * Go source: the package-level `precisionCache` map in model/model.go guarded
 * by `precisionCacheMu`. PHP's request model is single-threaded, so the mutex
 * is dropped per PORTING.md ("sync.Mutex guarding in-process caches → plain
 * code; keep the cache classes and bounds").
 */
final class PrecisionCache
{
    /**
     * Go: `const precisionCacheMaxEntries = 100_000`.
     */
    public const precisionCacheMaxEntries = 100000;

    /** @var array<string, float> */
    private static array $precisionCache = [];

    /** Not instantiable: static cache only. */
    private function __construct()
    {
    }

    /**
     * getCachedPrecision returns a cached precision for transactionID, if present.
     *
     * Go returns `(float64, bool)`; PHP returns the precision or null when absent.
     */
    public static function getCachedPrecision(string $transactionID): ?float
    {
        return self::$precisionCache[$transactionID] ?? null;
    }

    /**
     * setCachedPrecision stores a precision, resetting the cache if it reaches the
     * size bound (entries are immutable, so a reset only forces re-fetches).
     */
    public static function setCachedPrecision(string $transactionID, float $precision): void
    {
        if (\count(self::$precisionCache) >= self::precisionCacheMaxEntries) {
            self::$precisionCache = [];
        }
        self::$precisionCache[$transactionID] = $precision;
    }
}
