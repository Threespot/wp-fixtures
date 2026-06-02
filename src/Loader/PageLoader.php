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
     * user_login of the account fixtures are attributed to when front-matter
     * doesn't specify an `author`. PageExporter omits the `author:` line for
     * pages owned by this account, so loader and exporter stay symmetric.
     */
    public const DEFAULT_AUTHOR_LOGIN = 'admin';

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
        // so a site with no page fixtures doesn't error out when `--pages`
        // is implied by an unqualified `load`.
        $files = glob($fixturesDir . '/pages/*.html') ?: [];
        // Deterministic processing order makes log output comparable across
        // runs and gives a predictable insert order in WordPress.
        sort($files);

        // Parent wiring is deferred to a second pass: a child fixture can be
        // processed before its parent because files load in filename-sorted
        // order. Each entry is ['post_id' => int, 'parent' => string, 'path' => string].
        $pendingParents = [];

        foreach ($files as $file) {
            $relPath = 'pages/' . basename($file);
            $existingId = $this->findByMarker($relPath);

            if ($existingId !== null && !$force) {
                $result->skipped[] = ['path' => $relPath, 'post_id' => $existingId];
                continue;
            }

            try {
                [$postId, $parentSlug] = $this->import($file, $relPath, $existingId);
                $result->created[] = ['path' => $relPath, 'post_id' => $postId];
                if ($parentSlug !== null) {
                    $pendingParents[] = ['post_id' => $postId, 'parent' => $parentSlug, 'path' => $relPath];
                }
            } catch (\Throwable $e) {
                // Per-file failure — record and keep going so one bad fixture
                // doesn't abort the whole import.
                $result->errors[] = ['path' => $relPath, 'error' => $e->getMessage()];
            }
        }

        // Now that every fixture page exists in the DB, resolve `parent:`
        // slugs to IDs and set post_parent.
        $this->resolveParents($pendingParents, $result);

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
     * Note: post_parent is intentionally NOT set here. Parent wiring happens
     * in a second pass ({@see resolveParents}) so a child can be imported
     * before its parent exists; this method just hands the requested parent
     * slug back to the caller.
     *
     * @param string   $file       Absolute path to the HTML fixture.
     * @param string   $relPath    Source-path string to store as the marker.
     * @param int|null $existingId Post ID to update, or null to insert new.
     * @return array{0: int, 1: ?string} [post ID, requested parent slug or null].
     * @throws \RuntimeException When the file can't be read or wp_insert/update fails.
     */
    private function import(string $file, string $relPath, ?int $existingId): array
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
            'post_author'  => $this->resolveAuthorId($frontMatter['author'] ?? null),
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

        // If the page embeds a core Page List block, scope it to this page's
        // own children by writing the now-known post ID into the block's
        // parentPageID attribute. We can't do this in the insert above because
        // the ID doesn't exist until WordPress assigns it, so it's a follow-up
        // content update — only triggered for pages that actually use the block.
        if (str_contains($body, 'wp:page-list')) {
            $this->scopePageList($postId, $body);
        }

        // A blank or absent `parent:` means "top-level page" (post_parent 0),
        // which is the default — so we only propagate a non-empty slug.
        $parent = $frontMatter['parent'] ?? null;
        $parentSlug = ($parent !== null && $parent !== '') ? (string) $parent : null;

        return [$postId, $parentSlug];
    }

    /**
     * Writes $parentId into the parentPageID attribute of every core Page List
     * block in $body, then persists the rewritten content for $postId.
     *
     * Scoping a Page List block to a parent page makes it render only that
     * page's children. Passing the page's own ID therefore turns a bare
     * `<!-- wp:page-list /-->` into an index of its child pages — generated
     * live by WordPress at render time, so it always reflects the current
     * post_parent tree (no baked-in slugs or URLs to go stale).
     *
     * @param int    $postId The page being scoped (also the parent whose children to list).
     * @param string $body   The page's block markup, as read from the fixture.
     * @throws \RuntimeException When the follow-up content update fails.
     */
    private function scopePageList(int $postId, string $body): void
    {
        // Match a Page List block delimiter, with or without existing
        // attributes and in self-closing (`/-->`) or paired (`-->`) form.
        // Non-greedy `\{.*?\}` captures only this block's attribute JSON.
        $pattern = '/<!--\s+wp:page-list(?:\s+(\{.*?\}))?\s*(\/)?-->/';

        $scoped = preg_replace_callback($pattern, static function (array $m) use ($postId): string {
            // Merge into any existing attributes rather than clobbering them,
            // so a hand-authored block keeping other settings is respected.
            $attrs = [];
            if (!empty($m[1])) {
                $decoded = json_decode($m[1], true);
                if (is_array($decoded)) {
                    $attrs = $decoded;
                }
            }
            $attrs['parentPageID'] = $postId;

            // wp_json_encode matches WordPress's own block serialization for a
            // simple integer attribute, e.g. {"parentPageID":42}.
            $json    = wp_json_encode($attrs);
            $selfEnd = ($m[2] ?? '') === '/' ? ' /-->' : ' -->';

            return '<!-- wp:page-list ' . $json . $selfEnd;
        }, $body);

        // preg_replace_callback returns null only on a regex engine error;
        // bail to the original body so a hiccup never blanks the content.
        if ($scoped === null || $scoped === $body) {
            return;
        }

        // wp_slash for the same reason as the insert above — WordPress
        // unslashes post_content on save.
        $updated = wp_update_post(wp_slash([
            'ID'           => $postId,
            'post_content' => $scoped,
        ]), true);

        if (is_wp_error($updated)) {
            throw new \RuntimeException($updated->get_error_message());
        }
    }

    /**
     * Second-pass parent wiring: resolves each deferred `parent:` slug to a
     * post ID and sets post_parent.
     *
     * Run after every fixture page has been inserted, so a parent referenced
     * by a child is guaranteed to exist regardless of filename order. Failures
     * (unknown slug, self-reference, update error) are recorded on $result and
     * don't abort the rest — consistent with the per-file tolerance in load().
     *
     * @param array<int, array{post_id: int, parent: string, path: string}> $pending
     */
    private function resolveParents(array $pending, LoadResult $result): void
    {
        foreach ($pending as $entry) {
            $parentId = $this->resolveParentBySlug($entry['parent']);

            if ($parentId === null) {
                $result->errors[] = [
                    'path'  => $entry['path'],
                    'error' => "parent page '{$entry['parent']}' not found",
                ];
                continue;
            }

            // Guard against a fixture naming itself as its own parent, which
            // WordPress would otherwise accept and produce a page that can't
            // render in the admin tree.
            if ($parentId === $entry['post_id']) {
                $result->errors[] = [
                    'path'  => $entry['path'],
                    'error' => "page lists itself as parent ('{$entry['parent']}')",
                ];
                continue;
            }

            $updated = wp_update_post([
                'ID'          => $entry['post_id'],
                'post_parent' => $parentId,
            ], true);

            if (is_wp_error($updated)) {
                $result->errors[] = [
                    'path'  => $entry['path'],
                    'error' => $updated->get_error_message(),
                ];
            }
        }
    }

    /**
     * Looks up a page ID by its slug (post_name).
     *
     * Used to turn a fixture's `parent:` slug into a post_parent ID. We match
     * on slug rather than storing an ID because post IDs aren't portable across
     * sites — the same reasoning that drives author resolution by login.
     *
     * @param string $slug The parent page's slug (post_name).
     * @return int|null Matching post ID, or null when no page has that slug.
     */
    private function resolveParentBySlug(string $slug): ?int
    {
        $posts = get_posts([
            'post_type'   => 'any',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            // 'name' queries against post_name (the slug), not the title.
            'name'        => $slug,
            // Match findByMarker(): re-enable filters get_posts() suppresses by
            // default so language scoping etc. behaves on multilingual sites.
            'suppress_filters' => false,
        ]);

        return $posts ? (int) $posts[0] : null;
    }

    /**
     * Resolves the post_author ID for a fixture.
     *
     * Resolution order:
     *   1. Front-matter `author` override — a numeric user ID, or a user_login
     *      to look up. An unmatched login falls through to the default.
     *   2. The conventional {@see DEFAULT_AUTHOR_LOGIN} ("admin") account.
     *   3. The lowest-ID administrator, for sites where the admin account was
     *      renamed.
     *   4. 0 as a last resort, which lets WordPress assign its own default
     *      rather than erroring the import.
     *
     * We resolve to an ID at import time rather than storing one in fixtures
     * because user IDs aren't portable across sites; the marker we rely on is
     * the login, not the ID.
     *
     * @param int|string|null $author Front-matter override, or null for default.
     */
    private function resolveAuthorId(int|string|null $author): int
    {
        if ($author !== null && $author !== '') {
            if (is_numeric($author)) {
                return (int) $author;
            }

            $user = get_user_by('login', (string) $author);
            if ($user) {
                return (int) $user->ID;
            }
        }

        $default = get_user_by('login', self::DEFAULT_AUTHOR_LOGIN);
        if ($default) {
            return (int) $default->ID;
        }

        $admins = get_users([
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => 'ID',
        ]);

        return $admins ? (int) $admins[0] : 0;
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
