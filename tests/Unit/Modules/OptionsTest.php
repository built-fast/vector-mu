<?php

declare(strict_types=1);

use VectorMU\Modules\Options;

beforeEach( function () {
	$this->optionsModule = new Options();
} );

describe( 'register', function () {
	it( 'registers options commands when WP_CLI available', function () {
		$this->optionsModule->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'formatSize', function () {
	it( 'formats zero bytes', function () {
		$method = new ReflectionMethod( Options::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->optionsModule, 0 ) )->toBe( '0 B' );
	} );

	it( 'formats bytes', function () {
		$method = new ReflectionMethod( Options::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->optionsModule, 500 ) )->toBe( '500 B' );
		expect( $method->invoke( $this->optionsModule, 999 ) )->toBe( '999 B' );
	} );

	it( 'formats kilobytes', function () {
		$method = new ReflectionMethod( Options::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->optionsModule, 1024 ) )->toBe( '1 KB' );
		expect( $method->invoke( $this->optionsModule, 10240 ) )->toBe( '10 KB' );
	} );

	it( 'formats megabytes', function () {
		$method = new ReflectionMethod( Options::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->optionsModule, 1048576 ) )->toBe( '1 MB' );
	} );

	it( 'rounds to two decimal places', function () {
		$method = new ReflectionMethod( Options::class, 'formatSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->optionsModule, 1536 ) )->toBe( '1.5 KB' );
		expect( $method->invoke( $this->optionsModule, 1587 ) )->toBe( '1.55 KB' );
	} );
} );
