<?php

declare(strict_types=1);

namespace Threespot\WpFixtures\Exporter;

use Threespot\WpFixtures\Loader\PageLoader;
use Threespot\WpFixtures\Parser\FrontMatter;

/**
 * Inverse of PageLoader: takes a post in the database and renders it back to
 * fixture HTML (front-matter + Gutenberg body) suitable for committing.
 *
 * Designed to be symmetric with the loader. Only metadata that *differs* from
 * the loader's defaults is emitted as front-matter, so a roundtrip
 * (load → export) produces a file equivalent to the original — not one
 * bloated with redundant `status: publish`, `post_type: page`, etc.
 */
final class PageExporter
{
    /**
     * Renders the given post as fixture file contents.
     *
     * @param int $postId Post ID to export.
     * @return string Fixture file contents (write to disk or stdout).
     * @throws \RuntimeException When the post can't be found.
     */
    public function export(int $postId): string
    {
        $post = get_post($postId);
        if (!$post) {
            throw new \RuntimeException("Post $postId not found");
        }

        $frontMatter = [];

        // For each field below, only include it in front-matter when its
        // value differs from what PageLoader::import() would assume by
        // default. The pairings here mirror the defaults in the loader —
        // keep them in sync if either side changes.

        // post_name (slug) is auto-derived from the title at insert time.
        // Only emit it when the editor has overridden the default
        // sanitize_title() result.
        $autoSlug = sanitize_title($post->post_title);
        if ($post->post_name !== '' && $post->post_name !== $autoSlug) {
            $frontMatter['slug'] = $post->post_name;
        }

        if ($post->post_status !== 'publish') {
            $frontMatter['status'] = $post->post_status;
        }

        if ($post->post_type !== 'page') {
            $frontMatter['post_type'] = $post->post_type;
        }

        if ((int) $post->menu_order !== 0) {
            $frontMatter['menu_order'] = (int) $post->menu_order;
        }

        // The loader defaults to the "admin" account, so only emit `author:`
        // when this page is owned by someone else. We write the user_login
        // (not the ID) because IDs aren't portable across sites — matching how
        // PageLoader::resolveAuthorId() consumes the field.
        $author = get_user_by('id', (int) $post->post_author);
        if ($author && $author->user_login !== PageLoader::DEFAULT_AUTHOR_LOGIN) {
            $frontMatter['author'] = $author->user_login;
        }

        // _wp_page_template == 'default' is WordPress's way of saying "no
        // template override" — same as not setting it at all — so we treat
        // both as "don't emit a `template:` line".
        $template = (string) get_post_meta($postId, '_wp_page_template', true);
        if ($template !== '' && $template !== 'default') {
            $frontMatter['template'] = $template;
        }

        // FrontMatter::render returns '' when given [], so the concatenation
        // is safe even when the post matches every default.
        return FrontMatter::render($frontMatter) . $post->post_content;
    }
}
