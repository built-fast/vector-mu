<?php

declare(strict_types=1);

use VectorMU\Contracts\ModuleInterface;
use VectorMU\Modules\PreviewGate;
use VectorMU\Modules\VendorPlugins;
use VectorMU\Plugin;

it( 'registers only module classes', function (): void {
	$modules = ( new ReflectionClass( Plugin::class ) )->getReflectionConstant( 'MODULES' )->getValue();

	expect( $modules )->not->toBeEmpty();

	foreach ( $modules as $module ) {
		expect( is_subclass_of( $module, ModuleInterface::class ) )->toBeTrue();
	}
} );

it( 'bundles plugins whose entry files exist', function (): void {
	$plugins = ( new ReflectionClass( VendorPlugins::class ) )->getReflectionConstant( 'PLUGINS' )->getValue();

	foreach ( array_keys( $plugins ) as $plugin ) {
		expect( dirname( __DIR__, 2 ) . '/plugins/' . $plugin )->toBeFile();
	}
} );

it( 'registers the preview gate first, so it runs before any other module', function (): void {
	$modules = ( new ReflectionClass( Plugin::class ) )->getReflectionConstant( 'MODULES' )->getValue();

	expect( $modules[0] )->toBe( PreviewGate::class );
} );
