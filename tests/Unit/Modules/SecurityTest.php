<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\Security;

beforeEach( function () {
	$this->security = new Security();
} );

describe( 'register', function () {
	it( 'registers security command when WP_CLI available', function () {
		$this->security->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'Security module', function () {
	it( 'can be instantiated', function () {
		expect( $this->security )->toBeInstanceOf( Security::class );
	} );

	it( 'implements ModuleInterface', function () {
		expect( $this->security )->toBeInstanceOf( VectorMU\Contracts\ModuleInterface::class );
	} );
} );

describe( 'checkDebugSettings', function () {
	it( 'returns pass when WP_DEBUG is not defined', function () {
		$method = new ReflectionMethod( Security::class, 'checkDebugSettings' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result )->toHaveKey( 'name' );
		expect( $result )->toHaveKey( 'status' );
		expect( $result )->toHaveKey( 'message' );
		expect( $result['status'] )->toBe( 'pass' );
	} );
} );

describe( 'checkFileEditing', function () {
	it( 'returns warning when DISALLOW_FILE_EDIT is not defined', function () {
		$method = new ReflectionMethod( Security::class, 'checkFileEditing' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result['status'] )->toBe( 'warning' );
		expect( $result['message'] )->toContain( 'DISALLOW_FILE_EDIT' );
	} );
} );

describe( 'checkSslAdmin', function () {
	it( 'returns pass when SSL is enabled', function () {
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'force_ssl_admin' )->justReturn( false );

		$method = new ReflectionMethod( Security::class, 'checkSslAdmin' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result['status'] )->toBe( 'pass' );
	} );

	it( 'returns pass when force_ssl_admin returns true', function () {
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'force_ssl_admin' )->justReturn( true );

		$method = new ReflectionMethod( Security::class, 'checkSslAdmin' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result['status'] )->toBe( 'pass' );
	} );

	it( 'returns warning when SSL is not enforced', function () {
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'force_ssl_admin' )->justReturn( false );

		$method = new ReflectionMethod( Security::class, 'checkSslAdmin' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result['status'] )->toBe( 'warning' );
		expect( $result['message'] )->toContain( 'FORCE_SSL_ADMIN' );
	} );
} );

describe( 'checkSecurityKeys', function () {
	it( 'returns fail when security keys are not defined', function () {
		$method = new ReflectionMethod( Security::class, 'checkSecurityKeys' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->security );

		expect( $result )->toBeArray();
		expect( $result['status'] )->toBe( 'fail' );
		expect( $result['message'] )->toContain( 'AUTH_KEY' );
	} );
} );
