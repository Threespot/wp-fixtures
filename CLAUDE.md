# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## General Behavior

When answering WordPress/PHP questions, provide a direct answer first before exploring the codebase. Only investigate files if the user asks for project-specific context or if the question genuinely requires it.

## Code Style / Conventions

Never introduce Unicode smart quotes or curly quotes in any code file (PHP, JS, SCSS, Blade, JSON, etc.). Always use straight quotes (' and "). Do not alter existing comments solely to replace curly quotes with straight quotes — only avoid introducing new ones. A PostToolUse hook in `.claude/settings.json` scans added lines after every Edit and will block if any contain curly quotes.

## What this package is

`threespot/wp-fixtures` is a Composer **dev-dependency** that ships a WP-CLI command (`wp threespot fixtures …`) for loading example pages, taxonomy terms, and Gravity Forms into a fresh WordPress site. It is part of the Threespot Base Build framework — the architecture spec lives outside this repo at `~/Desktop/threespot-base-build-architecture.md` (sections "Example Content Fixtures", lines 488–646). Decisions like "dev-dependency only", "local-only loading", and "no per-site fixture overrides" are deliberate; don't add knobs the spec excludes.

Companion packages: `threespot/wp-core-config`, `threespot/wp-cache`, `threespot/wp-blocks`, `threespot/wp-admin-experience`, `@threespot/sage-base`. All ship as private GitHub repos, installed via Composer's `vcs` repository type — never Packagist.

## How it bootstraps

There is no plugin/mu-plugin/theme wiring. `composer.json` declares `bootstrap.php` under `autoload.files`, so Composer runs it the moment Bedrock includes `vendor/autoload.php` from `wp-config.php`. `bootstrap.php` checks for `WP_CLI` and registers `FixturesCommand`. If you change the bootstrap mechanism, remember the consuming site does *nothing* to opt in — that's the contract.

## Architecture

```
bootstrap.php
   └─ Cli/FixturesCommand        ← WP-CLI entry: load / list / status / export
        ├─ Loader/TaxonomyLoader ← reads fixtures/taxonomies/*.yaml
        ├─ Loader/FormLoader     ← reads fixtures/forms/*.json (shells to `wp gf form import`)
        ├─ Loader/PageLoader     ← reads fixtures/pages/*.html
        ├─ Loader/LoadResult     ← shared created/skipped/errors carrier
        ├─ Exporter/PageExporter ← inverse of PageLoader: post → fixture HTML
        ├─ Parser/FrontMatter    ← parses + renders Jekyll-style YAML front-matter
        └─ Paths                 ← locates the bundled fixtures dir
```

Things that are easy to miss:

- **Load order is load-bearing.** `FixturesCommand::load()` always runs taxonomies → forms → pages because page block markup may reference imported terms (by slug) and Gravity Forms (by ID). Don't reorder, and if you add a new fixture type, slot it into the dependency chain consciously.
- **Three different idempotency strategies for three fixture types.** Pages use a `postmeta` marker (`_threespot_fixture_source`). Terms use a `termmeta` marker with the same key. Forms use a single site `option` (`_threespot_fixtures_loaded`) holding an array of imported paths — because GF forms aren't WP posts, there's no postmeta to hang off. `FixturesCommand::status()` queries all three directly; don't break that contract without updating it.
- **`PageLoader` defaults and `PageExporter` omissions are paired.** The exporter writes front-matter only for fields whose values differ from the loader's defaults (`status: publish`, `post_type: page`, `menu_order: 0`, slug derived via `sanitize_title()`, no template override). If you change a default on one side, change it on the other — otherwise roundtripping a fixture mutates it.
- **`TaxonomyLoader` is multi-pass.** Children may declare `parent: <slug>` before their parent appears in the YAML file. The loader loops until either nothing remains or a full pass makes no progress; unresolved terms are reported as errors. Don't replace this with a single-pass insert "for simplicity."
- **`FormLoader` shells out, by design.** We invoke `WP_CLI::runcommand('gf form import …', ['launch' => false, 'exit_error' => false, 'return' => 'all'])` instead of parsing the GF JSON ourselves. Keep it that way — Gravity Forms own their schema across versions; we don't want to.
- **`list_` method, `list` subcommand.** `list` is a PHP reserved word, so the method is named `list_` with `@subcommand list` to keep the CLI clean. Same pattern applies if you add other reserved-word commands.

## Comment style (project rule)

This repo opts **out** of the default "minimal comments" stance. Every PHP function gets a docblock (one-line summary + `@param` / `@return` / `@throws` where they add information), and inline comments are added wherever WordPress APIs, parsing logic, or defensive choices would be opaque to a junior dev — e.g. `wp_slash`, `term_exists` return shape, `_wp_page_template`, `suppress_filters` defaults, `WP_CLI::runcommand` flags, the multi-pass term loop. Skip comments that just restate well-named identifiers; keep what's left tight.

The reason: Threespot has ~6 devs context-switching across many client sites; comments earn their keep when the reader doesn't already have the code in their head.

## Running and testing

There is no test suite, no linter, no CI. The package only exercises in the context of a running WordPress install. To work on it locally:

1. Have a Bedrock/WordPress site with WP-CLI available.
2. Symlink this checkout into that site's `vendor/threespot/wp-fixtures` (or add a path repository in the site's `composer.json`).
3. Drive it with `wp threespot fixtures load` / `list` / `status` / `export <post-id>`.

Useful flag combinations while iterating:

```bash
wp threespot fixtures load --taxonomies          # one section at a time
wp threespot fixtures load --pages --force       # re-import everything in a section
wp threespot fixtures load --path=/tmp/scratch   # point at a scratch fixture dir
wp threespot fixtures export 42 > new-page.html  # capture editor work as a fixture
```

`wp threespot fixtures export` writes to **stdout** (via `echo`) so it can be redirected; `load`, `list`, `status` write progress to **stderr** via `WP_CLI::log` / `success` / `warning`. Don't mix these up — it breaks the export-to-file workflow.

## Things to leave alone unless asked

- The shared fixture set under `fixtures/` is intentionally minimal. Don't expand it speculatively; new content should be driven by what a new Threespot site actually needs.
- The bundled `form-examples` page references **Gravity Forms form ID 1**, the ID GF assigns on a fresh install. If a fixture or test setup creates other forms first, that assumption breaks — call it out rather than papering over it.
- `--force` on forms re-runs the import, and GF assigns a new ID rather than updating in place. This can create duplicates; it's a known limitation of GF's import command, not something to "fix" in this package.
