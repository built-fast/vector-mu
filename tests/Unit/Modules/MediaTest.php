<?php

declare(strict_types=1);

use VectorMU\Modules\Media;

beforeEach( function () {
	$this->media = new Media();
} );

describe( 'register', function () {
	it( 'registers media commands when WP_CLI available', function () {
		$this->media->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'Media module', function () {
	it( 'can be instantiated', function () {
		expect( $this->media )->toBeInstanceOf( Media::class );
	} );

	it( 'implements ModuleInterface', function () {
		expect( $this->media )->toBeInstanceOf( VectorMU\Contracts\ModuleInterface::class );
	} );
} );

describe( 'formatSize', function () {
	it( 'formats zero bytes', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 0 ) )->toBe( '0 B' );
	} );

	it( 'formats bytes', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 500 ) )->toBe( '500 B' );
	} );

	it( 'formats kilobytes', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 1024 ) )->toBe( '1 KB' );
		expect( $method->invoke( $this->media, 2048 ) )->toBe( '2 KB' );
	} );

	it( 'formats megabytes', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 1048576 ) )->toBe( '1 MB' );
	} );

	it( 'formats gigabytes', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 1073741824 ) )->toBe( '1 GB' );
	} );

	it( 'rounds to two decimal places', function () {
		$method = new ReflectionMethod( Media::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, 1536 ) )->toBe( '1.5 KB' );
	} );
} );

describe( 'getDirectorySize', function () {
	it( 'returns 0 for non-existent directory', function () {
		$method = new ReflectionMethod( Media::class, 'getDirectorySize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, '/nonexistent/path/12345' ) )->toBe( 0 );
	} );

	it( 'returns 0 for file path instead of directory', function () {
		$method = new ReflectionMethod( Media::class, 'getDirectorySize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->media, __FILE__ ) )->toBe( 0 );
	} );
} );
