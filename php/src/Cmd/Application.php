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

namespace Blnk\Cmd;

use Blnk\Config\Configuration;
use Blnk\Core\Blnk;
use Blnk\Database\Datasource;
use Blnk\Internal\Log;
use Blnk\Internal\Notification\Notification;

/**
 * Application is the port of cmd/main.go: the `Blnk` CLI struct (root cobra
 * command) and `main`. `bin/blnk` calls {@see run()} with `$argv`.
 *
 * The cobra command tree is reproduced without cobra:
 *
 *   blnk [--config file]        root: "Open source ledger" (its Run is a no-op)
 *     start                     start blnk server              (ServerCommand)
 *     workers                   start blnk workers             (WorkersCommand)
 *     migrate                   start blnk migration
 *       up | down                                              (MigrateCommand)
 *     verify-chain              replay/verify the hash chain   (VerifyChainCommand)
 *     help [command]            cobra's built-in help
 *
 * with cobra's behaviors: `-h/--help` anywhere prints the command's help and
 * exits 0; a command group without subcommand (`blnk migrate`) prints its help
 * and exits 0; an unknown root argument is `unknown command "x" for "blnk"`
 * (with cobra's "Did you mean this?" suggestions) and exits 1; an unknown flag
 * exits 1 with the usage. The persistent pre-run ({@see preRun()}) loads the
 * configuration and builds the Blnk instance before every runnable command —
 * `migrate` included, exactly as in Go — and, like Go, reads the literal
 * "blnk.json" from the working directory: the `--config` flag is parsed but
 * `loadInstance` is called with "blnk.json" (cmd/main.go preRun), so the flag
 * value is stored ({@see configFile()}) but has no effect.
 *
 * Exit codes: 0 on success; 1 for a RunE error (printed to stderr, as
 * `executeCLI` does), a `log.Fatal`/`logrus.Fatal` in a command, or a
 * recovered panic (any uncaught exception, logged by {@see recoverPanic()}).
 */
final class Application
{
    /** cobra `Use` of the root command. */
    public const Use = 'blnk';

    /** cobra `Short` of the root command. */
    public const Short = 'Open source ledger';

    /** Default of the persistent `--config` flag. */
    public const DefaultConfigFile = './blnk.json';

    /** Usage text of the persistent `--config` flag. */
    public const ConfigFlagUsage = 'Configuration file for wallet lite';

    /** The file preRun loads (Go: `loadInstance(app, "blnk.json")`). */
    public const PreRunConfigFile = 'blnk.json';

    /** cobra's minimum name padding in "Available Commands". */
    private const NamePadding = 11;

    /** Go: `b := &blnkInstance{}` — the instance passed into the commands. */
    private BlnkInstance $app;

    /** Go: `var configFile string` bound to the persistent `--config` flag. */
    private string $configFile = self::DefaultConfigFile;

    /**
     * The cobra command tree: name → {short, runnable, commands}.
     *
     * @var array<string, mixed>
     */
    private array $root;

    private function __construct()
    {
        $this->app = new BlnkInstance();
        $this->root = [
            'short' => self::Short,
            'runnable' => true, // Run: func(cmd *cobra.Command, args []string) {}
            'commands' => [
                ServerCommand::Use => ['short' => ServerCommand::Short, 'runnable' => true, 'commands' => []],
                WorkersCommand::Use => ['short' => WorkersCommand::Short, 'runnable' => true, 'commands' => []],
                MigrateCommand::Use => [
                    'short' => MigrateCommand::Short,
                    'runnable' => false,
                    'commands' => [
                        'up' => ['short' => '', 'runnable' => true, 'commands' => []],
                        'down' => ['short' => '', 'runnable' => true, 'commands' => []],
                    ],
                ],
                VerifyChainCommand::Use => ['short' => VerifyChainCommand::Short, 'runnable' => true, 'commands' => []],
            ],
        ];
    }

    /**
     * run is the main function and the entry point for the application.
     * It recovers from any panic, initializes the CLI, and executes it.
     * Returns the process exit code.
     *
     * @param string[] $argv the full argv (argv[0] is the program name)
     */
    public static function run(array $argv): int
    {
        try {
            $cli = self::newCLI();
            return $cli->executeCLI(\array_slice(array_map('strval', $argv), 1));
        } catch (\Throwable $rec) {
            return self::recoverPanic($rec); // defer recoverPanic()
        }
    }

    /**
     * recoverPanic handles any panics during program execution and logs the error using Logrus.
     * Returns the exit status 1 the Go version exits with.
     */
    public static function recoverPanic(\Throwable $rec): int
    {
        Log::get()->error($rec->getMessage(), [
            'exception' => $rec::class,
            'file' => $rec->getFile(),
            'line' => $rec->getLine(),
        ]); // Log the recovered panic
        return 1; // Exit the program with an error status
    }

    /**
     * loadInstance loads the configuration file and initializes the Blnk
     * instance into app. Extracted from preRun so the initialization sequence
     * returns errors instead of exiting, keeping the Fatal at the command layer.
     *
     * @throws \Throwable
     */
    public static function loadInstance(BlnkInstance $app, string $configFile): void
    {
        // Initialize configuration from the specified configuration file.
        try {
            Configuration::initConfig($configFile);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error loading config: %s', $err->getMessage()), 0, $err);
        }

        // Fetch the configuration settings.
        $cnf = Configuration::fetch();

        // Initialize the Blnk instance using the fetched configuration.
        try {
            $newBlnk = self::setupBlnk($cnf);
        } catch (\Throwable $err) {
            Notification::notifyError($err); // Notify via the internal notification system
            throw $err;
        }

        // Assign the new Blnk instance and configuration to the app struct.
        $app->blnk = $newBlnk;
        $app->cnf = $cnf;

        $resolved = realpath($configFile);
        if ($resolved !== false) {
            $app->configFile = $resolved;
        } else {
            $app->configFile = str_starts_with($configFile, '/') ? $configFile : (string) getcwd() . '/' . $configFile;
        }
    }

    /**
     * preRun sets up the configuration and initializes the Blnk instance before running any command.
     * It ensures that the configuration is loaded, and the Blnk instance is initialized properly.
     *
     * Returns a `callable(string[] $args): bool` that reports false after
     * printing the `log.Fatal` line (the Go version exits the process there).
     *
     * @return callable(string[]): bool
     */
    public static function preRun(BlnkInstance $app): callable
    {
        return static function (array $args) use ($app): bool {
            try {
                self::loadInstance($app, self::PreRunConfigFile);
            } catch (\Throwable $err) {
                // log.Fatal(err): "YYYY/MM/DD HH:MM:SS <error>" on stderr, exit 1
                self::stderr(date('Y/m/d H:i:s') . ' ' . $err->getMessage() . "\n");
                return false;
            }
            return true;
        };
    }

    /**
     * setupBlnk creates and initializes a new Blnk instance based on the provided configuration.
     * It connects to the data source (such as a database) using the configuration settings.
     *
     * @throws \RuntimeException "error getting datasource: ..." / "error creating blnk: ..."
     */
    public static function setupBlnk(Configuration $cfg): Blnk
    {
        // Initialize a new data source from the configuration.
        try {
            $db = Datasource::newDataSource($cfg);
        } catch (\Throwable $err) {
            throw new \RuntimeException(sprintf('error getting datasource: %s', $err->getMessage()), 0, $err);
        }

        // Create a new Blnk instance using the initialized data source.
        try {
            return Blnk::newBlnk($db);
        } catch (\Throwable $err) {
            Log::get()->error($err->getMessage()); // Log the error using Logrus
            throw new \RuntimeException(sprintf('error creating blnk: %s', $err->getMessage()), 0, $err);
        }
    }

    /**
     * NewCLI creates the command-line interface (CLI) for the Blnk application.
     * It sets up the root command and subcommands like serverCommands, workerCommands, and migrateCommands.
     */
    public static function newCLI(): self
    {
        return new self();
    }

    /**
     * executeCLI runs the root command, handling any errors that occur during execution.
     * It serves as the main entry point for the CLI application. Returns the exit code.
     *
     * @param string[] $args the arguments after the program name
     */
    public function executeCLI(array $args): int
    {
        return $this->execute($args);
    }

    /** instance returns the blnkInstance the commands receive. */
    public function instance(): BlnkInstance
    {
        return $this->app;
    }

    /** configFile returns the value of the persistent `--config` flag. */
    public function configFile(): string
    {
        return $this->configFile;
    }

    /**
     * execute parses the flags, resolves the command path, runs the persistent
     * pre-run and dispatches to the command (cobra `Command.Execute`).
     *
     * @param string[] $args
     */
    private function execute(array $args): int
    {
        $positional = [];
        $help = false;
        $n = \count($args);
        for ($i = 0; $i < $n; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                $positional = array_merge($positional, \array_slice($args, $i + 1));
                break;
            }
            if ($arg === '-h' || $arg === '--help') {
                $help = true;
                continue;
            }
            if ($arg === '--config') {
                if ($i + 1 >= $n) {
                    return $this->flagError('flag needs an argument: --config', $positional);
                }
                $this->configFile = $args[++$i];
                continue;
            }
            if (str_starts_with($arg, '--config=')) {
                $this->configFile = substr($arg, \strlen('--config='));
                continue;
            }
            if (str_starts_with($arg, '--')) {
                return $this->flagError(sprintf('unknown flag: %s', $arg), $positional);
            }
            if (str_starts_with($arg, '-') && \strlen($arg) > 1) {
                return $this->flagError(sprintf("unknown shorthand flag: '%s' in %s", substr($arg, 1, 1), $arg), $positional);
            }
            $positional[] = $arg;
        }

        // cobra's built-in `help [command]`
        if ($positional !== [] && $positional[0] === 'help') {
            [$node, $path, $rest] = $this->resolve(\array_slice($positional, 1));
            if ($rest !== []) {
                self::stdout(sprintf("Unknown help topic [`%s`]\n", implode('` `', \array_slice($positional, 1))));
                self::stdout($this->usage($this->root, [self::Use]));
                return 0;
            }
            self::stdout($this->help($node, $path));
            return 0;
        }

        [$node, $path, $rest] = $this->resolve($positional);

        // Root command with subcommands: arbitrary arguments are unknown commands.
        if ($rest !== [] && \count($path) === 1) {
            $msg = sprintf('unknown command "%s" for "%s"%s', $rest[0], self::Use, $this->findSuggestions($rest[0]));
            self::stderr(sprintf("Error: %s\nRun '%s --help' for usage.\n", $msg, self::Use));
            self::stderr($msg . "\n"); // executeCLI: fmt.Fprintln(os.Stderr, err)
            return 1;
        }

        if ($help || !$node['runnable']) {
            // --help, or a non-runnable command group (cobra: flag.ErrHelp → help, no error)
            self::stdout($this->help($node, $path));
            return 0;
        }

        // PersistentPreRunE: initialize the app and config before executing any command.
        if (!(self::preRun($this->app))($rest)) {
            return 1;
        }

        return $this->dispatch($path);
    }

    /**
     * dispatch runs the resolved command.
     *
     * @param string[] $path
     */
    private function dispatch(array $path): int
    {
        switch (implode(' ', \array_slice($path, 1))) {
            case '':
                return 0; // root Run: func(cmd *cobra.Command, args []string) {}
            case ServerCommand::Use:
                return ServerCommand::run($this->app);
            case WorkersCommand::Use:
                return WorkersCommand::run($this->app);
            case MigrateCommand::Use . ' up':
                return self::runE(static function (): void {
                    MigrateCommand::migrateUp();
                });
            case MigrateCommand::Use . ' down':
                return self::runE(static function (): void {
                    MigrateCommand::migrateDown();
                });
            case VerifyChainCommand::Use:
                return self::runE(function (): void {
                    VerifyChainCommand::run($this->app);
                });
        }
        throw new \LogicException(sprintf('unknown command path %s', implode(' ', $path)));
    }

    /**
     * runE runs a cobra RunE body: an error is printed to stderr (the command
     * has SilenceErrors/SilenceUsage, so only executeCLI's line appears) and
     * the exit code is 1.
     *
     * @param callable(): void $fn
     */
    private static function runE(callable $fn): int
    {
        try {
            $fn();
            return 0;
        } catch (\Throwable $err) {
            self::stderr($err->getMessage() . "\n"); // fmt.Fprintln(os.Stderr, err)
            return 1;
        }
    }

    /**
     * resolve walks the command tree along the positional arguments.
     *
     * @param string[] $positional
     * @return array{0: array<string, mixed>, 1: string[], 2: string[]} the node, its path (starting with "blnk") and the remaining args
     */
    private function resolve(array $positional): array
    {
        $node = $this->root;
        $path = [self::Use];
        $i = 0;
        while ($i < \count($positional) && isset($node['commands'][$positional[$i]])) {
            $node = $node['commands'][$positional[$i]];
            $path[] = $positional[$i];
            $i++;
        }
        return [$node, $path, \array_slice($positional, $i)];
    }

    /**
     * flagError mirrors cobra's flag error output: "Error: <msg>", the usage
     * of the command being run, then the error line of executeCLI; exit 1.
     *
     * @param string[] $positional
     */
    private function flagError(string $msg, array $positional): int
    {
        [$node, $path] = $this->resolve($positional);
        self::stderr(sprintf("Error: %s\n", $msg));
        self::stderr($this->usage($node, $path));
        self::stderr($msg . "\n");
        return 1;
    }

    /**
     * findSuggestions reproduces cobra's "Did you mean this?" hint for an
     * unknown root command (Levenshtein distance <= 2 or a name prefix).
     */
    private function findSuggestions(string $typedName): string
    {
        $suggestions = [];
        foreach (array_keys($this->root['commands']) as $name) {
            $name = (string) $name;
            $ld = levenshtein(strtolower($typedName), strtolower($name));
            if ($ld <= 2 || str_starts_with(strtolower($name), strtolower($typedName))) {
                $suggestions[] = $name;
            }
        }
        if ($suggestions === []) {
            return '';
        }
        $out = "\n\nDid you mean this?\n";
        foreach ($suggestions as $s) {
            $out .= "\t" . $s . "\n";
        }
        return $out;
    }

    /**
     * help renders cobra's help for a command (its Short, then the usage).
     *
     * @param array<string, mixed> $node
     * @param string[] $path
     */
    private function help(array $node, array $path): string
    {
        $out = '';
        if (($node['short'] ?? '') !== '') {
            $out .= $node['short'] . "\n\n";
        }
        return $out . $this->usage($node, $path);
    }

    /**
     * usage renders cobra's usage block for a command.
     *
     * @param array<string, mixed> $node
     * @param string[] $path
     */
    private function usage(array $node, array $path): string
    {
        $name = implode(' ', $path);
        $isRoot = \count($path) === 1;
        $commands = $node['commands'] ?? [];

        $out = "Usage:\n";
        if ($node['runnable']) {
            $out .= sprintf("  %s [flags]\n", $name);
        }
        if ($commands !== []) {
            $out .= sprintf("  %s [command]\n", $name);
        }

        if ($commands !== []) {
            $rows = [];
            foreach ($commands as $sub => $def) {
                $rows[(string) $sub] = (string) ($def['short'] ?? '');
            }
            if ($isRoot) {
                $rows['help'] = 'Help about any command';
            }
            ksort($rows, \SORT_STRING);
            $width = self::NamePadding;
            foreach (array_keys($rows) as $sub) {
                $width = max($width, \strlen($sub));
            }
            $out .= "\nAvailable Commands:\n";
            foreach ($rows as $sub => $short) {
                $out .= rtrim(sprintf('  %-' . $width . 's %s', $sub, $short)) . "\n";
            }
        }

        $configFlag = sprintf('      --config string   %s (default "%s")', self::ConfigFlagUsage, self::DefaultConfigFile);
        $out .= "\nFlags:\n";
        if ($isRoot) {
            $out .= $configFlag . "\n";
            $out .= sprintf("  -h, --help            help for %s\n", $name);
        } else {
            $out .= sprintf("  -h, --help   help for %s\n", end($path));
            $out .= "\nGlobal Flags:\n" . $configFlag . "\n";
        }

        if ($commands !== []) {
            $out .= sprintf("\nUse \"%s [command] --help\" for more information about a command.\n", $name);
        }

        return $out;
    }

    private static function stdout(string $text): void
    {
        fwrite(\defined('STDOUT') ? \STDOUT : fopen('php://stdout', 'w'), $text);
    }

    private static function stderr(string $text): void
    {
        fwrite(\defined('STDERR') ? \STDERR : fopen('php://stderr', 'w'), $text);
    }
}
