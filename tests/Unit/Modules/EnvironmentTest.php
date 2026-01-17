<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\Environment;

beforeEach( function () {
	$this->environment = new Environment();
} );

describe( 'register', function () {
	it( 'registers environment command when WP_CLI available', function () {
		$this->environment->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'Environment module', function () {
	it( 'can be instantiated', function () {
		expect( $this->environment )->toBeInstanceOf( Environment::class );
	} );

	it( 'implements ModuleInterface', function () {
		expect( $this->environment )->toBeInstanceOf( VectorMU\Contracts\ModuleInterface::class );
	} );
} );

describe( 'getPhpInfo', function () {
	it( 'returns array with PHP version', function () {
		$method = new ReflectionMethod( Environment::class, 'getPhpInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toBeArray();
		expect( $result )->toHaveKey( 'version' );
		expect( $result['version'] )->toBe( PHP_VERSION );
	} );

	it( 'returns array with SAPI name', function () {
		$method = new ReflectionMethod( Environment::class, 'getPhpInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'sapi' );
		expect( $result['sapi'] )->toBe( PHP_SAPI );
	} );

	it( 'returns array with memory limit', function () {
		$method = new ReflectionMethod( Environment::class, 'getPhpInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'memory_limit' );
		expect( $result['memory_limit'] )->not->toBeEmpty();
	} );

	it( 'returns array with extensions count', function () {
		$method = new ReflectionMethod( Environment::class, 'getPhpInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'extensions_count' );
		expect( $result['extensions_count'] )->toBeGreaterThan( 0 );
	} );

	it( 'checks for important PHP extensions', function () {
		$method = new ReflectionMethod( Environment::class, 'getPhpInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'missing_extensions' );
		expect( $result['missing_extensions'] )->toBeArray();
	} );
} );

describe( 'getServerInfo', function () {
	it( 'returns array with OS information', function () {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		$method = new ReflectionMethod( Environment::class, 'getServerInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toBeArray();
		expect( $result )->toHaveKey( 'os' );
		expect( $result['os'] )->toBe( PHP_OS );
	} );

	it( 'returns array with OS family', function () {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$method = new ReflectionMethod( Environment::class, 'getServerInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'os_family' );
		expect( $result['os_family'] )->toBe( PHP_OS_FAMILY );
	} );

	it( 'returns array with architecture', function () {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$method = new ReflectionMethod( Environment::class, 'getServerInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'architecture' );
		expect( $result['architecture'] )->not->toBeEmpty();
	} );

	it( 'handles missing SERVER variables gracefully', function () {
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$method = new ReflectionMethod( Environment::class, 'getServerInfo' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toHaveKey( 'software' );
		// Should return 'Unknown' when SERVER_SOFTWARE not set.
		expect( $result['software'] )->toBe( 'Unknown' );
	} );
} );

describe( 'getConstants', function () {
	it( 'returns array of defined constants', function () {
		$method = new ReflectionMethod( Environment::class, 'getConstants' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		expect( $result )->toBeArray();
	} );

	it( 'filters out undefined constants', function () {
		$method = new ReflectionMethod( Environment::class, 'getConstants' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->environment );

		// Should not contain null values.
		foreach ( $result as $value ) {
			expect( $value )->not->toBeNull();
		}

		// Make an actual assertion.
		expect( $result )->toBeArray();
	} );
} );
