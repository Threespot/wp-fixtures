<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Loader;

use Threespot\WpFixtures\Parser\FrontMatter;

/**
 * Imports HTML fixture files as WordPress pages.
 *
 * Files live under fixtures/pages/*.html. The body is exactly what Gutenberg
 * saves (block markup with HTML comments). An optional YAML front-matter
 * block at the top of the file overrides defaults — see {@see FrontMatter}
 * for the format.
 *
 * Idempotency is tracked via a postmeta marker ({@see FIXTURE_META_KEY})
 * holding the fixture's source path. Re-running load() skips any file whose
 * marker is already present, unless --force is passed, in which case the
 * existing post is updated in place rather than duplicated.
 */
final class PageLoader
{
    /**
     * Postmeta key used to remember which fixture file a page came from.
     *
     * Public so FixturesCommand::status can query it without reaching into
     * the loader's internals.
     */
    public const FIXTURE_META_KEY = '_threespot_fixture_source';

    /**
     * Imports every page fixture under $fixturesDir/pages/.
     *
     * @param string $fixturesDir Absolute path to the fixtures root.
     * @param bool   $force       When true, re-import even if a marker exists.
     */
    public function load(string $fixturesDir, bool $force = false): LoadResult
    {
        $result = new LoadResult();
        // glob() returns false if the directory is missing; we coerce to []
        // so a site that only has taxonomy fixtures, etc., doesn't error out
        // when `--pages` is implied by an unqualified `load`.
        $files = glob($fixturesDir . '/pages/*.html') ?: [];
        // Deterministic processing order makes log output comparable across
        // runs and gives a predictable insert order in WordPress.
        sort($files);

        foreach ($files as $file) {
            $relPath = 'pages/' . basename($file);
            $existingId = $this->findByMarker($relPath);

            if ($existingId !== null && !$force) {
                $result->skipped[] = ['path' => $relPath, 'post_id' => $existingId];
                continue;
            }

            try {
                $postId = $this->import($file, $relPath, $existingId);
                $result->created[] = ['path' => $relPath, 'post_id' => $postId];
            } catch (\Throwable $e) {
                // Per-file failure — record and keep going so one bad fixture
                // doesn't abort the whole import.
                $result->errors[] = ['path' => $relPath, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Looks up the post (if any) that was previously imported from $relPath.
     *
     * Searches across all post types and statuses, because fixtures may
     * legitimately import drafts, custom post types, or non-publish statuses
     * via front-matter — the marker is the source of truth, not post_status.
     *
     * @param string $relPath Source-path marker (e.g. "pages/block-reference.html").
     * @return int|null Post ID, or null when no marker is found.
     */
    public function findByMarker(string $relPath): ?int
    {
        $posts = get_posts([
            'post_type'   => 'any',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => self::FIXTURE_META_KEY,
            'meta_value'  => $relPath,
            // get_posts() defaults `suppress_filters` to TRUE (unlike WP_Query),
            // which disables query filters like WPML's language scoping. We
            // re-enable them so this query behaves correctly on multilingual
            // client sites.
            'suppress_filters' => false,
        ]);

        return $posts ? (int) $posts[0] : null;
    }

    /**
     * Performs the actual insert or update for a single fixture file.
     *
     * @param string   $file       Absolute path to the HTML fixture.
     * @param string   $relPath    Source-path string to store as the marker.
     * @param int|null $existingId Post ID to update, or null to insert new.
     * @return int The post ID that was inserted or updated.
     * @throws \RuntimeException When the file can't be read or wp_insert/update fails.
     */
    private function import(string $file, string $relPath, ?int $existingId): int
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException("Could not read $relPath");
        }

        [$frontMatter, $body] = FrontMatter::parse($contents);
        $filenameBase = pathinfo($file, PATHINFO_FILENAME);

        // Build the post array. Each key falls back to a default that mirrors
        // a sensible "this is a published page" baseline; PageExporter relies
        // on these same defaults to decide what to omit from front-matter.
        $postData = [
            'post_title'   => (string) ($frontMatter['title'] ?? self::titleFromFilename($filenameBase)),
            'post_name'    => (string) ($frontMatter['slug'] ?? sanitize_title($filenameBase)),
            'post_status'  => (string) ($frontMatter['status'] ?? 'publish'),
            'post_type'    => (string) ($frontMatter['post_type'] ?? 'page'),
            'menu_order'   => (int) ($frontMatter['menu_order'] ?? 0),
            'post_content' => $body,
        ];

        // wp_slash() escapes the array before insert/update because WordPress
        // expects "slashed" input from forms and calls wp_unslash() on it
        // internally. Without this, single quotes and backslashes in block
        // markup get mangled on save — a notorious WordPress gotcha.
        if ($existingId !== null) {
            $postData['ID'] = $existingId;
            $postId = wp_update_post(wp_slash($postData), true);
        } else {
            $postId = wp_insert_post(wp_slash($postData), true);
        }

        // Many WordPress functions return either a useful value OR a WP_Error
        // when something fails (passing `true` as the second arg above opts us
        // into the WP_Error return). is_wp_error() is the safe way to detect it.
        if (is_wp_error($postId)) {
            throw new \RuntimeException($postId->get_error_message());
        }

        $postId = (int) $postId;

        // _wp_page_template is WordPress's internal meta key for the
        // "Template:" dropdown in the page editor — i.e. which Sage
        // per-template PHP file should render this page.
        if (!empty($frontMatter['template'])) {
            update_post_meta($postId, '_wp_page_template', (string) $frontMatter['template']);
        }

        // Always re-write the marker, including on --force updates, so the
        // value stays accurate even if the source path was renamed.
        update_post_meta($postId, self::FIXTURE_META_KEY, $relPath);

        return $postId;
    }

    /**
     * Derives a human-readable title from a fixture filename.
     *
     * E.g. "block-reference" → "Block Reference". Used when front-matter
     * doesn't supply an explicit `title` field.
     */
    private static function titleFromFilename(string $base): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $base));
    }
}
