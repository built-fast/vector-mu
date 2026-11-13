<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\LoopbackHeader;

const LOOPBACK_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach( function (): void {
	$this->dir  = sys_get_temp_dir() . '/vector-mu-loopback-' . bin2hex( random_bytes( 6 ) );
	$this->user = posix_getpwuid( posix_geteuid() )['name'];
	mkdir( $this->dir . '/' . $this->user, 0700, true );

	$this->writeSecret = function ( string $contents ): void {
		file_put_contents( $this->dir . '/' . $this->user . '/loopback', $contents );
	};

	Functions\when( 'home_url' )->justReturn( 'https://Example.test' );
	Functions\when( 'site_url' )->justReturn( 'https://wp.example.test/wp' );
	Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
} );

afterEach( function (): void {
	$file = $this->dir . '/' . $this->user . '/loopback';

	if ( is_file( $file ) ) {
		unlink( $file );
	}

	rmdir( $this->dir . '/' . $this->user );
	rmdir( $this->dir );
} );

/**
 * Runs the before_request callback and returns the resulting headers.
 *
 * @param array<string, string> $headers
 * @return array<string, string>
 */
function loopbackHeaders( LoopbackHeader $module, string $url, array $headers = [ 'Accept' => '*/*' ] ): array {
	$module->beforeRequest( $url, $headers );

	return $headers;
}

it( 'registers the Requests hooks', function (): void {
	$module = new LoopbackHeader( $this->dir );
	$module->register();

	expect( has_action( 'requests-requests.before_request', [ $module, 'beforeRequest' ] ) )->toBe( PHP_INT_MAX )
		->and( has_action( 'requests-requests.before_redirect', [ $module, 'beforeRedirect' ] ) )->toBe( PHP_INT_MIN );
} );

it( 'adds the header for the site\'s own host', function ( string $url ): void {
	( $this->writeSecret )( LOOPBACK_SECRET . "\n" );

	$headers = loopbackHeaders( new LoopbackHeader( $this->dir ), $url );

	expect( $headers )->toBe( [ 'Accept' => '*/*', LoopbackHeader::HEADER => LOOPBACK_SECRET ] );
} )->with( [
	'home url'          => 'https://example.test/wp-json/wp/v2/types/post',
	'other scheme'      => 'http://example.test/wp-cron.php?doing_wp_cron=1',
	'host case'         => 'https://EXAMPLE.TEST/',
	'site url host'     => 'https://wp.example.test/wp/wp-admin/admin-ajax.php',
	'non-default port'  => 'https://example.test:8443/',
] );

it( 'does not add the header for other hosts', function ( string $url ): void {
	( $this->writeSecret )( LOOPBACK_SECRET );

	$headers = loopbackHeaders( new LoopbackHeader( $this->dir ), $url );

	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} )->with( [
	'other host'     => 'https://api.wordpress.org/core/version-check/1.7/',
	'subdomain'      => 'https://evil.example.test/',
	'suffix'         => 'https://example.test.evil.com/',
	'userinfo trick' => 'https://example.test@evil.com/',
	'no host'        => '/wp-cron.php',
] );

it( 'removes a header left from an earlier hop for other hosts', function (): void {
	( $this->writeSecret )( LOOPBACK_SECRET );

	$headers = loopbackHeaders(
		new LoopbackHeader( $this->dir ),
		'https://evil.test/',
		[ 'x-vector-loopback' => LOOPBACK_SECRET, 'Accept' => '*/*' ]
	);

	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} );

it( 'stays inert when the secret file is missing', function (): void {
	$headers = loopbackHeaders( new LoopbackHeader( $this->dir ), 'https://example.test/' );

	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} );

it( 'stays inert when the secret is malformed', function ( string $contents ): void {
	( $this->writeSecret )( $contents );

	$headers = loopbackHeaders( new LoopbackHeader( $this->dir ), 'https://example.test/' );

	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} )->with( [
	'empty'      => '',
	'short'      => substr( LOOPBACK_SECRET, 1 ),
	'long'       => LOOPBACK_SECRET . 'a',
	'uppercase'  => strtoupper( LOOPBACK_SECRET ),
	'non-hex'    => str_replace( 'a', 'g', LOOPBACK_SECRET ),
	'two lines'  => LOOPBACK_SECRET . "\n" . LOOPBACK_SECRET,
] );

it( 'stays inert when the secret file is unreadable', function (): void {
	if ( 0 === posix_geteuid() ) {
		$this->markTestSkipped( 'root can read any file' );
	}

	$file = $this->dir . '/' . $this->user . '/loopback';
	( $this->writeSecret )( LOOPBACK_SECRET );
	chmod( $file, 0000 );

	$headers = loopbackHeaders( new LoopbackHeader( $this->dir ), 'https://example.test/' );

	chmod( $file, 0600 );

	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} );

it( 'reads the secret once per request', function (): void {
	( $this->writeSecret )( LOOPBACK_SECRET );
	$module = new LoopbackHeader( $this->dir );

	loopbackHeaders( $module, 'https://example.test/' );
	unlink( $this->dir . '/' . $this->user . '/loopback' );

	expect( loopbackHeaders( $module, 'https://example.test/' ) )->toHaveKey( LoopbackHeader::HEADER );
} );

it( 'strips the header before a redirect to another host', function (): void {
	( $this->writeSecret )( LOOPBACK_SECRET );
	$module  = new LoopbackHeader( $this->dir );
	$url     = 'https://example.test/wp-admin/admin-ajax.php';
	$headers = [ 'Accept' => '*/*' ];

	$module->beforeRequest( $url, $headers );
	expect( $headers )->toHaveKey( LoopbackHeader::HEADER );

	$location = 'https://evil.test/collect';
	$module->beforeRedirect( $location, $headers );
	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );

	$data    = [];
	$type    = 'GET';
	$options = [ 'redirected' => 1 ];
	$module->beforeRequest( $location, $headers, $data, $type, $options );
	expect( $headers )->toBe( [ 'Accept' => '*/*' ] );
} );

it( 'keeps the header across a redirect on the site\'s own host', function (): void {
	( $this->writeSecret )( LOOPBACK_SECRET );
	$module  = new LoopbackHeader( $this->dir );
	$url     = 'http://example.test/';
	$headers = [];

	$module->beforeRequest( $url, $headers );

	$location = 'https://example.test/';
	$data     = [];
	$type     = 'GET';
	$options  = [ 'redirected' => 1 ];
	$module->beforeRedirect( $location, $headers );
	$module->beforeRequest( $location, $headers, $data, $type, $options );

	expect( $headers )->toBe( [ LoopbackHeader::HEADER => LOOPBACK_SECRET ] );
} );

it( 'does not restore the header when a redirect chain comes back from another host', function (): void {
	( $this->writeSecret )( LOOPBACK_SECRET );
	$module  = new LoopbackHeader( $this->dir );
	$url     = 'https://example.test/';
	$headers = [];
	$data    = [];
	$type    = 'GET';

	$module->beforeRequest( $url, $headers );

	$location = 'https://evil.test/';
	$options  = [ 'redirected' => 1 ];
	$module->beforeRedirect( $location, $headers );
	$module->beforeRequest( $location, $headers, $data, $type, $options );

	$back    = 'https://example.test/again';
	$options = [ 'redirected' => 2 ];
	$module->beforeRedirect( $back, $headers );
	$module->beforeRequest( $back, $headers, $data, $type, $options );

	expect( $headers )->toBe( [] );
} );
