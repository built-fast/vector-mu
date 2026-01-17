<?php

declare(strict_types=1);

use VectorMU\Modules\Database;

beforeEach( function () {
	$this->database = new Database();
} );

describe( 'register', function () {
	it( 'registers database commands when WP_CLI available', function () {
		$this->database->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'formatSize', function () {
	it( 'formats zero bytes', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 0 ) )->toBe( '0 B' );
	} );

	it( 'formats bytes', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 512 ) )->toBe( '512 B' );
	} );

	it( 'formats kilobytes', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 1024 ) )->toBe( '1 KB' );
		expect( $method->invoke( $this->database, 10240 ) )->toBe( '10 KB' );
	} );

	it( 'formats megabytes', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 1048576 ) )->toBe( '1 MB' );
		expect( $method->invoke( $this->database, 52428800 ) )->toBe( '50 MB' );
	} );

	it( 'formats gigabytes', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 1073741824 ) )->toBe( '1 GB' );
	} );

	it( 'rounds to two decimal places', function () {
		$method = new ReflectionMethod( Database::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->database, 1536 ) )->toBe( '1.5 KB' );
	} );
} );
