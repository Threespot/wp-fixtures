<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Parser;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Splits an optional Jekyll-style "---" YAML front-matter block from the rest
 * of a fixture file.
 *
 * Format:
 *
 *     ---
 *     slug: block-reference
 *     menu_order: 10
 *     ---
 *     <!-- wp:heading --> ...
 *
 * Used by PageLoader (to read fixture metadata) and PageExporter (to emit it
 * symmetrically during roundtrip export), so the two stay in sync.
 */
final class FrontMatter
{
    /**
     * Parses front-matter and returns [array $frontMatter, string $body].
     *
     * The parser is intentionally lenient: whenever anything looks off — no
     * leading delimiter, no closing delimiter, YAML parse error, or non-array
     * YAML payload — it returns ([], $contents) so the caller treats the file
     * as front-matter-free. That keeps the fixture format forgiving and
     * avoids surprising "your page failed to import because of a typo on
     * line 1" errors during a long load.
     *
     * @param string $contents Raw file contents.
     * @return array{0: array<string,mixed>, 1: string} Tuple of [frontMatter, body].
     */
    public static function parse(string $contents): array
    {
        // Normalize line endings so the delimiter check and line-splitting
        // logic don't have to handle CRLF (Windows editors) or bare CR
        // (legacy Mac) separately.
        $normalized = preg_replace("/\r\n|\r/", "\n", $contents);

        // Front-matter requires the very first line to be exactly "---".
        // We check for "---\n" so a file that just starts with three dashes
        // mid-paragraph doesn't get mis-detected as a front-matter opener.
        if (!str_starts_with($normalized, "---\n")) {
            return [[], $contents];
        }

        $lines = explode("\n", $normalized);
        array_shift($lines); // discard the opening "---"

        // Walk lines until we hit the closing "---" delimiter; everything
        // before it is YAML, everything after is the page body.
        $yamlLines = [];
        $bodyStart = null;
        foreach ($lines as $i => $line) {
            if ($line === '---') {
                $bodyStart = $i + 1;
                break;
            }
            $yamlLines[] = $line;
        }

        // No closing delimiter found — treat the whole file as body and
        // silently ignore what looked like a front-matter opener.
        if ($bodyStart === null) {
            return [[], $contents];
        }

        try {
            // `?? []` covers the case of an empty front-matter block:
            // `Yaml::parse('')` returns null, which we want to treat as "no
            // metadata" rather than "invalid".
            $parsed = Yaml::parse(implode("\n", $yamlLines)) ?? [];
        } catch (ParseException) {
            return [[], $contents];
        }

        // Front-matter must be a key/value map, not a list or a scalar.
        if (!is_array($parsed)) {
            return [[], $contents];
        }

        $body = implode("\n", array_slice($lines, $bodyStart));
        // Drop any blank line(s) typically left between the closing "---" and
        // the first block of content, so post_content doesn't begin with
        // unnecessary whitespace.
        $body = ltrim($body, "\n");

        return [$parsed, $body];
    }

    /**
     * Renders an associative array as a "---" front-matter block, ready to
     * prepend to a body.
     *
     * Returns '' when given an empty array so callers can unconditionally
     * concatenate (`render($fm) . $body`) without worrying about emitting an
     * empty delimiter pair.
     *
     * @param array<string,mixed> $frontMatter Metadata to encode as YAML.
     * @return string Either '' or "---\n…\n---\n\n".
     */
    public static function render(array $frontMatter): string
    {
        if (empty($frontMatter)) {
            return '';
        }

        // Yaml::dump args:
        //   inline = 2  → top-level scalars stay on their own line; nested
        //                 arrays inline at depth 2 (rare in fixtures).
        //   indent = 2  → conventional two-space YAML indentation.
        return "---\n" . Yaml::dump($frontMatter, 2, 2) . "---\n\n";
    }
}
