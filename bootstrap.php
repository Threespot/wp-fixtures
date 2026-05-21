<?php

declare(strict_types=1);

namespace Threespot\WpFixtures;

/**
 * Package bootstrap.
 *
 * Composer auto-loads this file (see "autoload.files" in composer.json) every
 * time vendor/autoload.php is included, which Bedrock does in wp-config.php.
 * That means the WP-CLI command is registered as soon as the autoloader runs,
 * with no mu-plugin or theme `functions.php` wiring required from the
 * consuming site.
 */

if (defined('WP_CLI') && \WP_CLI) {
    // Subcommands map to public methods on FixturesCommand:
    //   `wp threespot fixtures load`   → FixturesCommand::load()
    //   `wp threespot fixtures list`   → FixturesCommand::list_()  (trailing _
    //                                     because `list` is a PHP reserved word;
    //                                     WP-CLI strips it and uses @subcommand)
    //   `wp threespot fixtures status` → FixturesCommand::status()
    //   `wp threespot fixtures export` → FixturesCommand::export()
    \WP_CLI::add_command('threespot fixtures', Cli\FixturesCommand::class);
}
