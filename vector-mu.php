<?php
/**
 * Plugin Name: Vector MU
 * Description: Must-use plugin for the Vector hosting platform.
 * Version: 0.2.1
 * Requires PHP: 8.2
 * Author: Vector
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

use VectorMU\Plugin;

defined( 'ABSPATH' ) || exit;

$vector_mu_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! file_exists( $vector_mu_autoloader ) ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'Vector MU: autoloader missing, run "composer install --no-dev" in ' . __DIR__ );
	return;
}

require_once $vector_mu_autoloader;

Plugin::boot();
