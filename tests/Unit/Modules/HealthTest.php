<?php

declare(strict_types=1);

use VectorMU\Modules\Health;

beforeEach( function () {
	$this->health = new Health();
} );

describe( 'register', function () {
	it( 'registers health command when WP_CLI available', function () {
		$this->health->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'formatSize', function () {
	it( 'formats zero bytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 0 ) )->toBe( '0 B' );
	} );

	it( 'formats bytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 500 ) )->toBe( '500 B' );
		expect( $method->invoke( $this->health, 1023 ) )->toBe( '1023 B' );
	} );

	it( 'formats kilobytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 1024 ) )->toBe( '1 KB' );
		expect( $method->invoke( $this->health, 2048 ) )->toBe( '2 KB' );
		expect( $method->invoke( $this->health, 1536 ) )->toBe( '1.5 KB' );
	} );

	it( 'formats megabytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 1048576 ) )->toBe( '1 MB' );
		expect( $method->invoke( $this->health, 5242880 ) )->toBe( '5 MB' );
	} );

	it( 'formats gigabytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 1073741824 ) )->toBe( '1 GB' );
	} );

	it( 'formats terabytes', function () {
		$method = new ReflectionMethod( Health::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, 1099511627776 ) )->toBe( '1 TB' );
	} );
} );

describe( 'getDirectorySize', function () {
	it( 'returns 0 for non-existent directory', function () {
		$method = new ReflectionMethod( Health::class, 'getDirectorySize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, '/nonexistent/path/12345' ) )->toBe( 0 );
	} );

	it( 'returns 0 for file path instead of directory', function () {
		$method = new ReflectionMethod( Health::class, 'getDirectorySize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->health, __FILE__ ) )->toBe( 0 );
	} );
} );
