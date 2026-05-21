<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Loader;

use Symfony\Component\Yaml\Yaml;

/**
 * Imports taxonomy terms from YAML fixture files.
 *
 * One file per taxonomy: fixtures/taxonomies/category.yaml feeds the
 * `category` taxonomy, post_tag.yaml feeds `post_tag`, etc. The taxonomy
 * itself must already be registered (typically via `extended-cpts` at the
 * `init` hook) before fixtures load — this loader doesn't create taxonomies,
 * only terms.
 *
 * Each YAML entry has:
 *   - name        (required)
 *   - slug        (optional, derived from name via sanitize_title)
 *   - description (optional)
 *   - parent      (optional, references another term's slug — either in the
 *                  same file or one already in the database)
 *
 * Idempotency is tracked via a termmeta marker ({@see FIXTURE_META_KEY}).
 */
final class TaxonomyLoader
{
    /**
     * Termmeta key used to remember which fixture file a term came from.
     *
     * Public so FixturesCommand::status can query it.
     */
    public const FIXTURE_META_KEY = '_threespot_fixture_source';

    /**
     * Imports every taxonomy fixture under $fixturesDir/taxonomies/.
     *
     * @param string $fixturesDir Absolute path to the fixtures root.
     * @param bool   $force       When true, re-import (update existing terms in place).
     */
    public function load(string $fixturesDir, bool $force = false): LoadResult
    {
        $result = new LoadResult();
        // Accept both .yaml and .yml — neither is more "correct" and users mix them.
        $files = array_merge(
            glob($fixturesDir . '/taxonomies/*.yaml') ?: [],
            glob($fixturesDir . '/taxonomies/*.yml') ?: [],
        );
        sort($files);

        foreach ($files as $file) {
            $taxonomy = pathinfo($file, PATHINFO_FILENAME);
            $relPath = 'taxonomies/' . basename($file);

            // Fail loudly if the taxonomy isn't registered yet. Usually means
            // the consuming site forgot to load extended-cpts, or the file is
            // named wrong (e.g. categories.yaml instead of category.yaml).
            if (!taxonomy_exists($taxonomy)) {
                $result->errors[] = [
                    'file' => $relPath,
                    'error' => "Taxonomy '$taxonomy' is not registered. Ensure CPTs/taxonomies are registered before loading fixtures.",
                ];
                continue;
            }

            try {
                $terms = Yaml::parseFile($file);
            } catch (\Throwable $e) {
                $result->errors[] = ['file' => $relPath, 'error' => 'YAML parse error: ' . $e->getMessage()];
                continue;
            }

            if (!is_array($terms)) {
                $result->errors[] = ['file' => $relPath, 'error' => 'Expected a list of terms at the top level.'];
                continue;
            }

            $this->insertTerms($taxonomy, $terms, $relPath, $force, $result);
        }

        return $result;
    }

    /**
     * Inserts a batch of terms into one taxonomy, resolving parent slugs in
     * passes so file order doesn't matter.
     *
     * Why multi-pass: a child term ("Press Releases") may declare
     * `parent: news` before "News" itself appears in the file. We can't
     * insert the child until "News" exists (wp_insert_term needs a numeric
     * parent ID). So we loop: insert anything whose parent is already
     * resolved, and repeat until nothing new can be inserted.
     *
     * @param array<int,mixed> $terms  Raw YAML list of term arrays.
     * @param string           $relPath  Source-path marker.
     * @param bool             $force    When true, update existing terms.
     * @param LoadResult       $result   Accumulator the caller will read.
     */
    private function insertTerms(string $taxonomy, array $terms, string $relPath, bool $force, LoadResult $result): void
    {
        // Index by slug so child terms can find their parent during the
        // multi-pass loop below.
        $bySlug = [];
        foreach ($terms as $index => $term) {
            if (!is_array($term) || !isset($term['name'])) {
                $result->errors[] = [
                    'taxonomy' => $taxonomy,
                    'file' => $relPath,
                    'error' => "Entry at index $index is missing 'name'.",
                ];
                continue;
            }
            $slug = (string) ($term['slug'] ?? sanitize_title((string) $term['name']));
            $term['slug'] = $slug;
            $bySlug[$slug] = $term;
        }

        // Map of slug => term_id for terms we've inserted (or confirmed already
        // exist) during this run. Lets child terms resolve their parent without
        // a fresh DB query each time.
        $inserted = [];
        $remaining = $bySlug;
        // Worst case: the chain is N levels deep (each term parents the next),
        // so N passes is always sufficient. The `progressed` flag below is the
        // real safety net against infinite loops.
        $maxPasses = max(1, count($bySlug));

        while (!empty($remaining) && $maxPasses-- > 0) {
            $progressed = false;
            foreach ($remaining as $slug => $term) {
                $parent = $term['parent'] ?? null;
                if ($parent !== null && $parent !== '' && !isset($inserted[$parent])) {
                    // Parent wasn't inserted this run — but maybe it already
                    // exists from a previous load. Check the database before
                    // giving up and deferring to the next pass.
                    $parentExists = term_exists((string) $parent, $taxonomy);
                    if (!$parentExists) {
                        // Defer this term; another pass may resolve its parent.
                        continue;
                    }
                    $inserted[$parent] = (int) $parentExists['term_id'];
                }
                $this->insertOne($taxonomy, $term, $relPath, $force, $inserted, $result);
                unset($remaining[$slug]);
                $progressed = true;
            }
            // If a complete pass made no progress, the remaining terms reference
            // parents that don't exist anywhere. Bail and report them rather
            // than spinning forever.
            if (!$progressed) {
                break;
            }
        }

        foreach ($remaining as $slug => $term) {
            $result->errors[] = [
                'taxonomy' => $taxonomy,
                'slug' => (string) $slug,
                'error' => "Unresolvable parent slug: " . (string) ($term['parent'] ?? ''),
            ];
        }
    }

    /**
     * Inserts or updates a single term and writes the fixture marker.
     *
     * @param array<string,mixed> $term     Normalized term data (must have name + slug).
     * @param array<string,int>   $inserted Mutable map of slug => term_id; updated on success.
     */
    private function insertOne(
        string $taxonomy,
        array $term,
        string $relPath,
        bool $force,
        array &$inserted,
        LoadResult $result,
    ): void {
        $slug = (string) $term['slug'];
        $name = (string) $term['name'];
        // term_exists() returns an array { term_id, term_taxonomy_id } when
        // found, or 0/null otherwise — a quirky WordPress API that must be
        // checked defensively rather than as a simple boolean.
        $existing = term_exists($slug, $taxonomy);
        $existingId = is_array($existing) ? (int) $existing['term_id'] : null;

        if ($existingId !== null && !$force) {
            // Term already present and we're not forcing — but still record
            // its ID so children later in this file can resolve `parent: $slug`.
            $inserted[$slug] = $existingId;
            $result->skipped[] = ['taxonomy' => $taxonomy, 'slug' => $slug, 'term_id' => $existingId];
            return;
        }

        $args = ['slug' => $slug];
        if (!empty($term['description'])) {
            $args['description'] = (string) $term['description'];
        }
        if (!empty($term['parent'])) {
            // Prefer the in-memory map (no DB roundtrip); fall back to a DB
            // lookup for parents that existed before this load began.
            $parentId = $inserted[(string) $term['parent']] ?? null;
            if ($parentId === null) {
                $parentTerm = term_exists((string) $term['parent'], $taxonomy);
                $parentId = is_array($parentTerm) ? (int) $parentTerm['term_id'] : 0;
            }
            if ($parentId) {
                $args['parent'] = $parentId;
            }
        }

        // API asymmetry, worth knowing: wp_insert_term() takes `name` as a
        // positional arg; wp_update_term() takes it inside the args array.
        if ($existingId !== null) {
            $args['name'] = $name;
            $response = wp_update_term($existingId, $taxonomy, $args);
        } else {
            $response = wp_insert_term($name, $taxonomy, $args);
        }

        if (is_wp_error($response)) {
            $result->errors[] = [
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'error' => $response->get_error_message(),
            ];
            return;
        }

        $termId = (int) $response['term_id'];
        $inserted[$slug] = $termId;
        update_term_meta($termId, self::FIXTURE_META_KEY, $relPath);
        $result->created[] = ['taxonomy' => $taxonomy, 'slug' => $slug, 'term_id' => $termId];
    }
}
