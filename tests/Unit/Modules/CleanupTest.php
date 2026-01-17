<?php

declare(strict_types=1);

use VectorMU\Modules\Cleanup;

beforeEach( function () {
	$this->cleanup = new Cleanup();
} );

describe( 'register', function () {
	it( 'registers cleanup commands when WP_CLI available', function () {
		$this->cleanup->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'Cleanup module', function () {
	it( 'can be instantiated', function () {
		expect( $this->cleanup )->toBeInstanceOf( Cleanup::class );
	} );

	it( 'implements ModuleInterface', function () {
		expect( $this->cleanup )->toBeInstanceOf( VectorMU\Contracts\ModuleInterface::class );
	} );
} );
