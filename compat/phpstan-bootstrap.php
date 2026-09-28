<?php

declare(strict_types=1);

/*
 * PHPStan bootstrap for applications still importing Opis\Database\* classes.
 *
 * PHPStan cannot see aliases created lazily by opis-aliases.php, so this file
 * registers every alias up front. Add it to the application's phpstan.neon:
 *
 *     parameters:
 *         bootstrapFiles:
 *             - vendor/noirapi/database/compat/phpstan-bootstrap.php
 *
 * Remove it once the application imports Noirapi\Database\* directly.
 */

(static function (): void {
    $root = dirname(__DIR__) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1, -4);
        $suffix = str_replace('/', '\\', $relative);
        $target = 'Noirapi\\Database\\' . $suffix;
        $alias = 'Opis\\Database\\' . $suffix;

        if (
            (class_exists($target) || interface_exists($target) || enum_exists($target))
            && !class_exists($alias, false)
            && !interface_exists($alias, false)
        ) {
            class_alias($target, $alias);
        }
    }
})();
