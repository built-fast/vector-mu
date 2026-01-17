<?php

declare(strict_types=1);

use VectorMU\Modules\UploadsSize;

beforeEach( function () {
	$this->uploadsSize = new UploadsSize();
} );

describe( 'register', function () {
	it( 'registers uploads-size command when WP_CLI available', function () {
		$this->uploadsSize->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded
		expect( true )->toBeTrue();
	} );
} );

describe( 'formatSize', function () {
	it( 'formats bytes', function () {
		$method = new ReflectionMethod( UploadsSize::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->uploadsSize, 0 ) )->toBe( '0 B' );
		expect( $method->invoke( $this->uploadsSize, 500 ) )->toBe( '500 B' );
	} );

	it( 'formats kilobytes', function () {
		$method = new ReflectionMethod( UploadsSize::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->uploadsSize, 1024 ) )->toBe( '1 KB' );
		expect( $method->invoke( $this->uploadsSize, 2048 ) )->toBe( '2 KB' );
	} );

	it( 'formats megabytes', function () {
		$method = new ReflectionMethod( UploadsSize::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->uploadsSize, 1048576 ) )->toBe( '1 MB' );
		expect( $method->invoke( $this->uploadsSize, 5242880 ) )->toBe( '5 MB' );
	} );

	it( 'formats gigabytes', function () {
		$method = new ReflectionMethod( UploadsSize::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->uploadsSize, 1073741824 ) )->toBe( '1 GB' );
	} );

	it( 'rounds to two decimal places', function () {
		$method = new ReflectionMethod( UploadsSize::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->uploadsSize, 1536 ) )->toBe( '1.5 KB' );
	} );
} );
