<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Cli;

use Threespot\WpFixtures\Exporter\PageExporter;
use Threespot\WpFixtures\Loader\FormLoader;
use Threespot\WpFixtures\Loader\LoadResult;
use Threespot\WpFixtures\Loader\PageLoader;
use Threespot\WpFixtures\Loader\TaxonomyLoader;
use Threespot\WpFixtures\Paths;

/**
 * WP-CLI entry point for `wp threespot fixtures ...`.
 *
 * Subcommands map directly to public methods on this class. WP-CLI reads the
 * method docblocks below to generate `--help` output and parse arguments, so
 * the OPTIONS / EXAMPLES sections are part of the contract — not just docs.
 *
 * NOTE: \WP_CLI::error() halts execution via exit(). \WP_CLI::warning() and
 * \WP_CLI::success() are non-fatal status prints. \WP_CLI::log() is plain
 * stderr output. Mixing them shapes how a run "feels" to the operator.
 */
final class FixturesCommand
{
    /**
     * Loads fixtures into the current site.
     *
     * Order: taxonomy terms → Gravity Forms → pages, so page markup can
     * reference already-imported terms and forms. Re-running is safe;
     * fixtures already imported are skipped unless --force is passed.
     *
     * ## OPTIONS
     *
     * [--pages]
     * : Only load page fixtures.
     *
     * [--taxonomies]
     * : Only load taxonomy term fixtures.
     *
     * [--forms]
     * : Only load Gravity Forms fixtures.
     *
     * [--force]
     * : Re-import fixtures even if their markers are already present.
     *
     * [--path=<path>]
     * : Override the fixtures directory. Defaults to the package's bundled fixtures.
     *
     * ## EXAMPLES
     *
     *     wp threespot fixtures load
     *     wp threespot fixtures load --pages
     *     wp threespot fixtures load --force
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args       Positional args (unused).
     * @param array<string,string> $assoc_args Flag args (--pages, --force, --path=…).
     */
    public function load(array $args, array $assoc_args): void
    {
        $path = (string) ($assoc_args['path'] ?? Paths::fixturesDir());
        $force = isset($assoc_args['force']);

        if (!is_dir($path)) {
            // \WP_CLI::error() prints the message and calls exit(1) — there's
            // no point continuing if the fixtures directory doesn't exist.
            \WP_CLI::error("Fixtures directory not found: $path");
        }

        // Decide which sections to run. If none of the type flags is passed,
        // run everything (the typical first-run case).
        $selected = array_filter([
            'taxonomies' => isset($assoc_args['taxonomies']),
            'forms'      => isset($assoc_args['forms']),
            'pages'      => isset($assoc_args['pages']),
        ]);
        $runAll = empty($selected);

        // Accumulates results across the three sections for the final summary line.
        $totals = new LoadResult();

        // Order matters: pages may reference imported terms (by slug) and
        // forms (by ID), so we load those first.
        if ($runAll || isset($selected['taxonomies'])) {
            \WP_CLI::log('Loading taxonomy terms...');
            $r = (new TaxonomyLoader())->load($path, $force);
            $this->reportSection($r);
            $totals->merge($r);
        }

        if ($runAll || isset($selected['forms'])) {
            \WP_CLI::log('Loading Gravity Forms...');
            $r = (new FormLoader())->load($path, $force);
            $this->reportSection($r);
            $totals->merge($r);
        }

        if ($runAll || isset($selected['pages'])) {
            \WP_CLI::log('Loading pages...');
            $r = (new PageLoader())->load($path, $force);
            $this->reportSection($r);
            $totals->merge($r);
        }

        $created = count($totals->created);
        $skipped = count($totals->skipped);
        $errors  = count($totals->errors);

        if ($errors > 0) {
            \WP_CLI::warning("Done with errors. Created: $created, Skipped: $skipped, Errors: $errors.");
            return;
        }

        \WP_CLI::success("Done. Created: $created, Skipped: $skipped.");
    }

    /**
     * Lists fixture files available in the package.
     *
     * ## OPTIONS
     *
     * [--path=<path>]
     * : Override the fixtures directory.
     *
     * [--format=<format>]
     * : Render format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * @subcommand list
     * @when after_wp_load
     *
     * Method named `list_` (trailing underscore) because `list` is a PHP
     * reserved word. The @subcommand annotation tells WP-CLI to expose it
     * as the cleaner `wp threespot fixtures list`.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function list_(array $args, array $assoc_args): void
    {
        $path = (string) ($assoc_args['path'] ?? Paths::fixturesDir());
        if (!is_dir($path)) {
            \WP_CLI::error("Fixtures directory not found: $path");
        }

        $rows = [];

        foreach (glob($path . '/pages/*.html') ?: [] as $file) {
            $rows[] = ['type' => 'page', 'path' => 'pages/' . basename($file)];
        }

        $taxFiles = array_merge(
            glob($path . '/taxonomies/*.yaml') ?: [],
            glob($path . '/taxonomies/*.yml') ?: [],
        );
        foreach ($taxFiles as $file) {
            $rows[] = ['type' => 'taxonomy', 'path' => 'taxonomies/' . basename($file)];
        }

        foreach (glob($path . '/forms/*.json') ?: [] as $file) {
            $rows[] = ['type' => 'form', 'path' => 'forms/' . basename($file)];
        }

        // The spaceship operator (<=>) returns -1/0/1 for less/equal/greater.
        // Comparing tuples sorts by type first, then by path — same result
        // as a multi-key SQL ORDER BY.
        usort($rows, fn($a, $b) => [$a['type'], $a['path']] <=> [$b['type'], $b['path']]);

        \WP_CLI\Utils\format_items(
            (string) $assoc_args['format'],
            $rows,
            ['type', 'path'],
        );
    }

    /**
     * Shows which fixtures have been loaded on this site.
     *
     * Queries the markers directly: postmeta for pages, termmeta for terms,
     * a site option for forms.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc_args
     */
    public function status(array $args, array $assoc_args): void
    {
        // $wpdb is WordPress's database abstraction (singleton). We use it
        // directly here because there's no get_posts/get_terms helper that
        // returns "all rows with this meta key" without a wasteful join to
        // the parent posts/terms table.
        global $wpdb;

        $rows = [];

        // Pages: postmeta marker.
        // $wpdb->prepare is the safe parameterized-query API; never interpolate
        // user input into raw SQL strings.
        $pageRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
                PageLoader::FIXTURE_META_KEY,
            ),
            ARRAY_A,
        ) ?: [];
        foreach ($pageRows as $row) {
            $rows[] = [
                'type' => 'page',
                'source' => (string) $row['meta_value'],
                'ref' => 'post ' . $row['post_id'],
            ];
        }

        // Taxonomy terms: termmeta marker (same key string, different table).
        $termRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT term_id, meta_value FROM $wpdb->termmeta WHERE meta_key = %s",
                TaxonomyLoader::FIXTURE_META_KEY,
            ),
            ARRAY_A,
        ) ?: [];
        foreach ($termRows as $row) {
            $rows[] = [
                'type' => 'taxonomy-term',
                'source' => (string) $row['meta_value'],
                'ref' => 'term ' . $row['term_id'],
            ];
        }

        // Forms: single site option (no per-form ref since GF assigns IDs
        // freely and we don't track them).
        $loadedForms = (array) get_option(FormLoader::LOADED_OPTION, []);
        foreach ($loadedForms as $path) {
            $rows[] = [
                'type' => 'form',
                'source' => (string) $path,
                'ref' => '—',
            ];
        }

        if (empty($rows)) {
            \WP_CLI::log('No fixtures have been loaded on this site yet.');
            return;
        }

        usort($rows, fn($a, $b) => [$a['type'], $a['source']] <=> [$b['type'], $b['source']]);

        \WP_CLI\Utils\format_items(
            (string) $assoc_args['format'],
            $rows,
            ['type', 'source', 'ref'],
        );
    }

    /**
     * Exports a page back to a fixture HTML file (printed to stdout).
     *
     * Useful for maintaining the shared fixture set: build the page in
     * WordPress's editor, then capture it as a fixture file. Pipe to a file
     * with `> fixtures/pages/new-page.html`.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : The post ID to export.
     *
     * ## EXAMPLES
     *
     *     wp threespot fixtures export 42 > fixtures/pages/new-page.html
     *
     * @when after_wp_load
     *
     * @param array<int,string>    $args       Positional args; $args[0] is the post ID.
     * @param array<string,string> $assoc_args Unused.
     */
    public function export(array $args, array $assoc_args): void
    {
        $postId = (int) ($args[0] ?? 0);
        if ($postId <= 0) {
            \WP_CLI::error('A positive post ID is required.');
        }

        try {
            // echo (rather than \WP_CLI::log) because this command's output
            // is meant to be redirected to a file. \WP_CLI::log writes to
            // stderr; echo writes to stdout.
            echo (new PageExporter())->export($postId);
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Prints a one-line summary for a section and lists any per-item errors.
     *
     * Called once per section (taxonomies / forms / pages) from load().
     */
    private function reportSection(LoadResult $r): void
    {
        \WP_CLI::log(sprintf(
            '  Created: %d   Skipped: %d   Errors: %d',
            count($r->created),
            count($r->skipped),
            count($r->errors),
        ));

        foreach ($r->errors as $err) {
            // Pick whichever context key the failing loader populated.
            // PageLoader uses 'path', TaxonomyLoader uses 'file' or 'slug',
            // FormLoader uses 'path'.
            $ctx = $err['path'] ?? $err['file'] ?? $err['slug'] ?? '';
            $ctxStr = $ctx !== '' ? "[$ctx] " : '';
            \WP_CLI::log('    ' . $ctxStr . $err['error']);
        }
    }
}
