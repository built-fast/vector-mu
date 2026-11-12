<?php

declare(strict_types=1);

use VectorMU\Modules\VendorPlugins;
use VectorMU\Runtime;

it( 'loads the Ymir fork on lambda', function (): void {
	expect( VendorPlugins::pluginsFor( Runtime::LAMBDA ) )
		->toContain( 'vector-wordpress-plugin/vector-wordpress-plugin.php' );
} );

it( 'does not load the Ymir fork on dev', function (): void {
	expect( VendorPlugins::pluginsFor( Runtime::DEV ) )
		->not->toContain( 'vector-wordpress-plugin/vector-wordpress-plugin.php' );
} );

it( 'declares only known runtimes for each plugin', function (): void {
	$plugins = ( new ReflectionClass( VendorPlugins::class ) )->getReflectionConstant( 'PLUGINS' )->getValue();

	foreach ( $plugins as $runtimes ) {
		expect( $runtimes )->not->toBeEmpty();

		foreach ( $runtimes as $runtime ) {
			expect( $runtime )->toBeIn( [ Runtime::LAMBDA, Runtime::DEV ] );
		}
	}
} );
