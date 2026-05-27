<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Loader;

/**
 * Plain data carrier describing what happened during a single load() call.
 *
 * Each loader (PageLoader, FormLoader) returns one of these; FixturesCommand
 * merges them together to produce a single end-of-run summary.
 *
 * Three buckets:
 *   - created: items newly inserted, or updated in place via --force
 *   - skipped: items already present (marker found) and not re-imported
 *   - errors:  per-item failures with a human-readable message
 *
 * Kept as public arrays rather than encapsulated with typed getters because
 * the only consumer is FixturesCommand, which just iterates and counts.
 */
final class LoadResult
{
    /** @var list<array{path?:string,post_id?:int}> */
    public array $created = [];

    /** @var list<array{path?:string,post_id?:int}> */
    public array $skipped = [];

    /** @var list<array{path?:string,error:string}> */
    public array $errors = [];

    /**
     * Concatenates another result's rows into this one.
     *
     * Used by FixturesCommand to roll per-section results (forms, pages) into
     * a single total for the run summary.
     */
    public function merge(self $other): void
    {
        $this->created = array_merge($this->created, $other->created);
        $this->skipped = array_merge($this->skipped, $other->skipped);
        $this->errors = array_merge($this->errors, $other->errors);
    }
}
