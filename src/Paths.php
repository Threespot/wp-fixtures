<?php

declare(strict_types=1);

namespace Threespot\WpFixtures;

/**
 * Helpers for locating the package's own directories.
 *
 * Lets the loaders find bundled fixtures without hard-coding paths relative
 * to each call site. Centralized here so a future restructure of src/ only
 * needs to touch one place.
 */
final class Paths
{
    /**
     * Absolute path to the package root (the directory containing composer.json).
     *
     * Computed from this file's location, so it works whether the package
     * lives in vendor/threespot/wp-fixtures/ or is symlinked into vendor/
     * during local development.
     */
    public static function packageDir(): string
    {
        // __DIR__ is .../src, so one level up is the package root.
        return dirname(__DIR__);
    }

    /**
     * Absolute path to the bundled fixtures directory.
     *
     * Used by FixturesCommand as the default --path when none is supplied.
     */
    public static function fixturesDir(): string
    {
        return self::packageDir() . '/fixtures';
    }
}
