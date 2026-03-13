<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;

final class VendorPlugins implements ModuleInterface {

	/** @var list<string> */
	private const PLUGINS = [
		'redis-cache/redis-cache.php',
	];

	public function register(): void {
		if ( ! defined( 'WP_REDIS_DISABLE_DROPIN_AUTOUPDATE' ) ) {
			define( 'WP_REDIS_DISABLE_DROPIN_AUTOUPDATE', true );
		}

		foreach ( self::PLUGINS as $plugin ) {
			require_once VECTOR_MU_PATH . '/plugins/' . $plugin; // @phpstan-ignore requireOnce.fileNotFound
		}

		$this->ensureObjectCacheDropin();
	}

	private function ensureObjectCacheDropin(): void {
		$source = VECTOR_MU_PATH . '/plugins/redis-cache/includes/object-cache.php';
		$target = WP_CONTENT_DIR . '/object-cache.php';

		if ( ! file_exists( $source ) ) {
			return;
		}

		if ( file_exists( $target ) && md5_file( $source ) === md5_file( $target ) ) {
			return;
		}

		copy( $source, $target );
	}
}
