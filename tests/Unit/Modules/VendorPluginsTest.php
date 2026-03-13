<?php

declare(strict_types=1);

use VectorMU\Modules\VendorPlugins;

$projectRoot = dirname( __DIR__, 3 );
$wpContentTmp = sys_get_temp_dir() . '/vector-mu-test-wp-content-' . getmypid();

if ( ! defined( 'VECTOR_MU_PATH' ) ) {
	define( 'VECTOR_MU_PATH', $projectRoot );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', $wpContentTmp );
}

beforeEach( function () {
	$this->module = new VendorPlugins();
	@mkdir( WP_CONTENT_DIR, 0755, true );
} );

afterEach( function () {
	$target = WP_CONTENT_DIR . '/object-cache.php';
	if ( file_exists( $target ) ) {
		unlink( $target );
	}
} );

describe( 'ensureObjectCacheDropin', function () {
	it( 'copies drop-in when target file is missing', function () {
		$target = WP_CONTENT_DIR . '/object-cache.php';

		expect( file_exists( $target ) )->toBeFalse();

		$method = new ReflectionMethod( VendorPlugins::class, 'ensureObjectCacheDropin' );
		$method->setAccessible( true );
		$method->invoke( $this->module );

		expect( file_exists( $target ) )->toBeTrue();

		$source = VECTOR_MU_PATH . '/plugins/redis-cache/includes/object-cache.php';
		expect( md5_file( $target ) )->toBe( md5_file( $source ) );
	} );

	it( 'updates drop-in when target is outdated', function () {
		$target = WP_CONTENT_DIR . '/object-cache.php';
		file_put_contents( $target, '<?php // old version' );

		$method = new ReflectionMethod( VendorPlugins::class, 'ensureObjectCacheDropin' );
		$method->setAccessible( true );
		$method->invoke( $this->module );

		$source = VECTOR_MU_PATH . '/plugins/redis-cache/includes/object-cache.php';
		expect( md5_file( $target ) )->toBe( md5_file( $source ) );
	} );

	it( 'skips copy when drop-in is current', function () {
		$source = VECTOR_MU_PATH . '/plugins/redis-cache/includes/object-cache.php';
		$target = WP_CONTENT_DIR . '/object-cache.php';
		copy( $source, $target );
		$mtime = filemtime( $target );

		// Ensure any copy would produce a different mtime.
		sleep( 1 );

		$method = new ReflectionMethod( VendorPlugins::class, 'ensureObjectCacheDropin' );
		$method->setAccessible( true );
		$method->invoke( $this->module );

		clearstatcache( true, $target );
		expect( filemtime( $target ) )->toBe( $mtime );
	} );
} );
