<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;

/**
 * Loads the plugins bundled under plugins/ as must-use plugins.
 */
final class VendorPlugins implements ModuleInterface {

	/** @var list<string> */
	private const PLUGINS = [
		'vector-wordpress-plugin/vector-wordpress-plugin.php',
	];

	public function register(): void {
		foreach ( self::PLUGINS as $plugin ) {
			$path = VECTOR_MU_PATH . '/plugins/' . $plugin;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Vector MU: bundled plugin missing: ' . $plugin );
				continue;
			}

			require_once $path; // @phpstan-ignore requireOnce.fileNotFound (VECTOR_MU_PATH is only known at runtime)
		}
	}
}
