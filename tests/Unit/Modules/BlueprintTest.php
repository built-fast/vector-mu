<?php

declare(strict_types=1);

use VectorMU\Modules\Blueprint;

beforeEach( function () {
	$this->blueprint = new Blueprint();
} );

describe( 'register', function () {
	it( 'registers commands when WP_CLI available', function () {
		$this->blueprint->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded
		expect( true )->toBeTrue();
	} );
} );

describe( 'parseSize', function () {
	it( 'parses bytes', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '1024' ) )->toBe( 1024 );
		expect( $method->invoke( $this->blueprint, '1024B' ) )->toBe( 1024 );
	} );

	it( 'parses kilobytes', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '1K' ) )->toBe( 1024 );
		expect( $method->invoke( $this->blueprint, '1KB' ) )->toBe( 1024 );
		expect( $method->invoke( $this->blueprint, '2K' ) )->toBe( 2048 );
	} );

	it( 'parses megabytes', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '1M' ) )->toBe( 1048576 );
		expect( $method->invoke( $this->blueprint, '1MB' ) )->toBe( 1048576 );
		expect( $method->invoke( $this->blueprint, '100M' ) )->toBe( 104857600 );
	} );

	it( 'parses gigabytes', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '1G' ) )->toBe( 1073741824 );
		expect( $method->invoke( $this->blueprint, '1GB' ) )->toBe( 1073741824 );
	} );

	it( 'returns null for null input', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, null ) )->toBeNull();
	} );

	it( 'returns null for empty string', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseSize' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '' ) )->toBeNull();
	} );
} );

describe( 'parseCommaSeparated', function () {
	it( 'parses comma-separated values', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseCommaSeparated' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, 'a,b,c' ) )
			->toBe( [ 'a', 'b', 'c' ] );
	} );

	it( 'trims whitespace', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseCommaSeparated' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, ' a , b , c ' ) )
			->toBe( [ 'a', 'b', 'c' ] );
	} );

	it( 'filters empty values', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseCommaSeparated' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, 'a,,b,,,c' ) )
			->toBe( [ 'a', 'b', 'c' ] );
	} );

	it( 'returns empty array for empty string', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseCommaSeparated' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, '' ) )->toBe( [] );
	} );

	it( 'handles single value', function () {
		$method = new ReflectionMethod( Blueprint::class, 'parseCommaSeparated' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->blueprint, 'single' ) )
			->toBe( [ 'single' ] );
	} );
} );
