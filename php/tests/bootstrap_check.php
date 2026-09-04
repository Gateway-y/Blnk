<?php

declare(strict_types=1);

/**
 * bootstrap_check.php loads the Composer autoloader and asserts that every
 * class, interface, trait and enum under src/ can be autoloaded (PSR-4 name
 * derived from the file path). Exit code 1 when anything fails to load.
 *
 * Usage: php tests/bootstrap_check.php
 */

require __DIR__ . '/../vendor/autoload.php';

$srcDir = realpath(__DIR__ . '/../src');
$failures = [];
$count = 0;

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

foreach ($files as $path) {
    $relative = substr($path, strlen($srcDir) + 1, -4);
    $name = 'Blnk\\' . str_replace('/', '\\', $relative);
    $count++;
    try {
        $ok = class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
    } catch (\Throwable $e) {
        $ok = false;
        $failures[] = sprintf('%s: %s: %s', $name, get_class($e), $e->getMessage());
        continue;
    }
    if (!$ok) {
        $failures[] = sprintf('%s: not found (file %s)', $name, $path);
    }
}

printf("checked %d files, %d failures\n", $count, count($failures));
foreach ($failures as $f) {
    echo "  FAIL " . $f . "\n";
}
exit($failures === [] ? 0 : 1);
