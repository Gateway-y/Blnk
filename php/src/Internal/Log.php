<?php

declare(strict_types=1);

namespace Blnk\Internal;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Log is the static Monolog accessor used across the Blnk PHP port, replacing
 * the Go codebase's global logrus logger (see PORTING.md "Logging /
 * observability").
 *
 * Go: `logrus.WithFields(f).Info(msg)` → PHP: `Log::get()->info($msg, $fields)`.
 *
 * The logger writes to php://stderr on the "blnk" channel. The minimum level is
 * taken from the BLNK_LOG_LEVEL environment variable (logrus-style level names
 * are accepted: panic, fatal, error, warn/warning, info, debug, trace) and
 * defaults to Info.
 */
final class Log
{
    private static ?Logger $logger = null;

    /** Not instantiable: static accessor only. */
    private function __construct()
    {
    }

    /**
     * get returns the process-wide logger, creating it on first use.
     */
    public static function get(): Logger
    {
        if (self::$logger === null) {
            $logger = new Logger('blnk');
            $logger->pushHandler(new StreamHandler('php://stderr', self::levelFromEnv()));
            self::$logger = $logger;
        }

        return self::$logger;
    }

    /**
     * levelFromEnv resolves BLNK_LOG_LEVEL to a Monolog level, defaulting to
     * Info when unset or unparseable. logrus aliases (warn, trace, panic, ...)
     * are mapped onto their closest Monolog equivalents.
     */
    private static function levelFromEnv(): Level
    {
        $name = getenv('BLNK_LOG_LEVEL');
        if ($name === false || $name === '') {
            return Level::Info;
        }

        // logrus level names → Monolog level names.
        $aliases = [
            'panic' => 'emergency',
            'fatal' => 'critical',
            'warn' => 'warning',
            'trace' => 'debug',
        ];
        $normalized = strtolower(trim($name));
        $normalized = $aliases[$normalized] ?? $normalized;

        try {
            return Logger::toMonologLevel($normalized);
        } catch (\Throwable) {
            return Level::Info;
        }
    }
}
