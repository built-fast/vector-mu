<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use VectorMU\Runtime;

/**
 * Loads the plugins bundled under plugins/ as must-use plugins.
 *
 * Each plugin lists the runtimes it loads in. The Ymir fork is Lambda only:
 * on dev its S3 uploads, CloudFront, and asset URL rewriting stay off, so
 * uploads are served from disk.
 */
final class VendorPlugins implements ModuleInterface {

	/** @var array<string, list<string>> Entry file under plugins/ => runtimes it loads in. */
	private const PLUGINS = [
		'vector-wordpress-plugin/vector-wordpress-plugin.php' => [ Runtime::LAMBDA ],
	];

	public function register(): void {
		foreach ( self::pluginsFor( VECTOR_MU_RUNTIME ) as $plugin ) {
			$path = VECTOR_MU_PATH . '/plugins/' . $plugin;

			if ( ! file_exists( $path ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Vector MU: bundled plugin missing: ' . $plugin );
				continue;
			}

			require_once $path;
		}
	}

	/**
	 * The bundled plugins that load in a runtime, in load order.
	 *
	 * @return list<string>
	 */
	public static function pluginsFor( string $runtime ): array {
		$plugins = [];

		foreach ( self::PLUGINS as $plugin => $runtimes ) {
			if ( in_array( $runtime, $runtimes, true ) ) {
				$plugins[] = $plugin;
			}
		}

		return $plugins;
	}
}
