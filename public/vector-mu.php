<?php
/**
 * Plugin Name: Vector MU
 * Description: MU plugin bootstrap framework
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Vector
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $autoloader ) ) {
	return;
}

require_once $autoloader;

VectorMU\Plugin::boot();
