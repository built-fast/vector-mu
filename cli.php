<?php
/**
 * WP-CLI entry point for standalone usage.
 *
 * Usage: wp --require=/path/to/vector-mu/cli.php vector <command>
 */

declare(strict_types=1);

use VectorMU\Plugin;

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

$autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
    WP_CLI::error( 'Run composer install in ' . __DIR__ );
}

require_once $autoloader;

WP_CLI::add_hook( 'after_wp_load', [ Plugin::class, 'boot' ] );
