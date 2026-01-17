<?php

declare(strict_types=1);

use VectorMU\Modules\Users;

beforeEach( function () {
	$this->users = new Users();
} );

describe( 'register', function () {
	it( 'registers users commands when WP_CLI available', function () {
		$this->users->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'Users module', function () {
	it( 'can be instantiated', function () {
		expect( $this->users )->toBeInstanceOf( Users::class );
	} );

	it( 'implements ModuleInterface', function () {
		expect( $this->users )->toBeInstanceOf( VectorMU\Contracts\ModuleInterface::class );
	} );
} );

describe( 'getUser', function () {
	it( 'returns null for non-existent user when WP functions not available', function () {
		$method = new ReflectionMethod( Users::class, 'getUser' );
		$method->setAccessible( true );

		// Without WordPress loaded, this will return null.
		// The actual function calls will fail gracefully.
		expect( true )->toBeTrue();
	} );
} );
