<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\YmirConflictGuard;

beforeEach( function (): void {
	Functions\stubEscapeFunctions();
	Functions\stubTranslationFunctions();
	Functions\when( 'is_network_admin' )->justReturn( false );
} );

it( 'registers the plugin filters and admin notices', function (): void {
	$guard = new YmirConflictGuard();
	$guard->register();

	expect( has_filter( 'option_active_plugins', [ $guard, 'filterActivePlugins' ] ) )->not->toBeFalse()
		->and( has_filter( 'site_option_active_sitewide_plugins', [ $guard, 'filterNetworkActivePlugins' ] ) )->not->toBeFalse()
		->and( has_action( 'admin_notices', [ $guard, 'renderNotice' ] ) )->not->toBeFalse()
		->and( has_action( 'network_admin_notices', [ $guard, 'renderNotice' ] ) )->not->toBeFalse();
} );

it( 'removes Ymir from the active plugins list', function (): void {
	$guard = new YmirConflictGuard();

	$result = $guard->filterActivePlugins( [
		'akismet/akismet.php',
		'ymir/ymir.php',
		'wordpress-plugin/ymir.php',
		'hello.php',
	] );

	expect( $result )->toBe( [ 'akismet/akismet.php', 'hello.php' ] );
} );

it( 'removes Ymir from the network active plugins map', function (): void {
	$guard = new YmirConflictGuard();

	$result = $guard->filterNetworkActivePlugins( [
		'akismet/akismet.php' => 1700000000,
		'ymir/ymir.php'       => 1700000001,
	] );

	expect( $result )->toBe( [ 'akismet/akismet.php' => 1700000000 ] );
} );

it( 'passes non-array option values through', function (): void {
	$guard = new YmirConflictGuard();

	expect( $guard->filterActivePlugins( false ) )->toBeFalse()
		->and( $guard->filterNetworkActivePlugins( '' ) )->toBe( '' );
} );

it( 'shows a notice to capable users when Ymir was blocked', function (): void {
	Functions\expect( 'current_user_can' )->once()->with( 'activate_plugins' )->andReturn( true );

	$guard = new YmirConflictGuard();
	$guard->filterActivePlugins( [ 'ymir/ymir.php' ] );

	ob_start();
	$guard->renderNotice();
	$output = (string) ob_get_clean();

	expect( $output )->toContain( 'notice-warning' )
		->and( $output )->toContain( '<code>ymir/ymir.php</code>' );
} );

it( 'checks the network capability on network admin screens', function (): void {
	Functions\when( 'is_network_admin' )->justReturn( true );
	Functions\expect( 'current_user_can' )->once()->with( 'manage_network_plugins' )->andReturn( true );

	$guard = new YmirConflictGuard();
	$guard->filterNetworkActivePlugins( [ 'ymir/ymir.php' => 1 ] );

	ob_start();
	$guard->renderNotice();

	expect( (string) ob_get_clean() )->toContain( 'ymir/ymir.php' );
} );

it( 'stays silent when nothing was blocked', function (): void {
	Functions\expect( 'current_user_can' )->never();

	$guard = new YmirConflictGuard();
	$guard->filterActivePlugins( [ 'akismet/akismet.php' ] );

	ob_start();
	$guard->renderNotice();

	expect( (string) ob_get_clean() )->toBe( '' );
} );

it( 'stays silent for users who cannot manage plugins', function (): void {
	Functions\when( 'current_user_can' )->justReturn( false );

	$guard = new YmirConflictGuard();
	$guard->filterActivePlugins( [ 'ymir/ymir.php' ] );

	ob_start();
	$guard->renderNotice();

	expect( (string) ob_get_clean() )->toBe( '' );
} );
