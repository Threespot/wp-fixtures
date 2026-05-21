<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Loader;

/**
 * Imports Gravity Forms JSON exports.
 *
 * Files under fixtures/forms/*.json are standard `wp gf form import` payloads —
 * the exact format Gravity Forms produces via Forms → Import/Export → Export
 * Forms. This loader doesn't parse or interpret form structure; it shells out
 * to Gravity Forms' own WP-CLI command so we stay compatible with whatever the
 * current GF version expects.
 *
 * Idempotency is tracked via a single site option holding the list of
 * imported source paths. We can't easily mark forms with postmeta (GF forms
 * aren't WordPress posts), so an option is the cheapest stable record.
 */
final class FormLoader
{
    /**
     * Site option key holding an array of imported fixture paths (e.g.
     * ["forms/example-form.json"]). Public so FixturesCommand::status can read it.
     */
    public const LOADED_OPTION = '_threespot_fixtures_loaded';

    /**
     * Imports every form fixture under $fixturesDir/forms/.
     *
     * @param string $fixturesDir Absolute path to the fixtures root.
     * @param bool   $force       When true, re-run the import even if the marker
     *                            is present. Note: Gravity Forms always assigns
     *                            a fresh form ID rather than updating in place,
     *                            so --force can create duplicates.
     */
    public function load(string $fixturesDir, bool $force = false): LoadResult
    {
        $result = new LoadResult();
        $files = glob($fixturesDir . '/forms/*.json') ?: [];
        sort($files);

        // No fixture files = nothing to do, even if Gravity Forms isn't
        // installed. We check this BEFORE the GF-active check so sites
        // without form fixtures don't see a spurious "GF not active" error
        // during an unqualified `load`.
        if (empty($files)) {
            return $result;
        }

        if (!$this->isGravityFormsActive()) {
            $result->errors[] = [
                'error' => 'Gravity Forms is not active; skipping form fixtures. Activate the plugin and re-run with --forms.',
            ];
            return $result;
        }

        $loaded = (array) get_option(self::LOADED_OPTION, []);

        foreach ($files as $file) {
            $relPath = 'forms/' . basename($file);

            if (in_array($relPath, $loaded, true) && !$force) {
                $result->skipped[] = ['path' => $relPath];
                continue;
            }

            try {
                $this->importViaWpCli($file);
                $loaded[] = $relPath;
                $result->created[] = ['path' => $relPath];
            } catch (\Throwable $e) {
                $result->errors[] = ['path' => $relPath, 'error' => $e->getMessage()];
            }
        }

        // array_unique guards against --force re-imports adding duplicate
        // entries to the marker.
        // The third arg to update_option (`false`) means "don't autoload" —
        // fixture markers aren't needed on every WP page request, so we keep
        // them out of the autoloaded options blob.
        update_option(self::LOADED_OPTION, array_values(array_unique($loaded)), false);

        return $result;
    }

    /**
     * Returns true when Gravity Forms is loaded into the current request.
     *
     * We probe by class existence rather than is_plugin_active(), because the
     * latter pulls in admin-context includes that aren't available in WP-CLI.
     * GFAPI and GFForms both ship with all GF versions we care about.
     */
    private function isGravityFormsActive(): bool
    {
        return class_exists('GFAPI') || class_exists('\\GFForms');
    }

    /**
     * Shells out to `wp gf form import <file>` to actually import a fixture.
     *
     * Calling Gravity Forms' own CLI command means we don't have to know
     * anything about the JSON schema — that's GF's problem, and they keep
     * it working across their own versions.
     *
     * @throws \RuntimeException When WP-CLI is missing or the GF command fails.
     */
    private function importViaWpCli(string $file): void
    {
        if (!class_exists('\\WP_CLI')) {
            throw new \RuntimeException('FormLoader requires WP-CLI to invoke `gf form import`.');
        }

        // WP_CLI::runcommand args:
        //   return: 'all'      → return stdout/stderr/return_code as a struct
        //                        rather than printing them to the terminal.
        //   exit_error: false  → don't let WP_CLI::error() (which calls exit())
        //                        kill the whole import on one bad form; we
        //                        want to record the failure and continue.
        //   launch: false      → run inside the current PHP process via the
        //                        WP-CLI dispatcher, rather than spawning a
        //                        child WP-CLI process. Faster and lets the
        //                        called command see in-memory state from us.
        $response = \WP_CLI::runcommand(
            // escapeshellarg on the file path: even with launch:false, WP-CLI
            // parses the string into argv using shell-like quoting rules, so
            // unsafe characters in the path could still cause problems.
            sprintf('gf form import %s', escapeshellarg($file)),
            ['return' => 'all', 'exit_error' => false, 'launch' => false],
        );

        if ((int) $response->return_code !== 0) {
            // Prefer stderr (real error message); fall back to stdout (some
            // GF errors print there); fall back to a generic message.
            $stderr = trim((string) $response->stderr);
            $stdout = trim((string) $response->stdout);
            throw new \RuntimeException($stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : '`gf form import` failed.'));
        }
    }
}
