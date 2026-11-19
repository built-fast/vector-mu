<?php

declare(strict_types=1);

use VectorMU\Gate\CurlDocumentFetcher;
use VectorMU\Gate\DocumentStore;
use VectorMU\Gate\GateException;
use VectorMU\Modules\PreviewGate;
use VectorMU\Runtime;
use VectorMU\Tests\Support\GateVectors;
use VectorMU\Tests\Support\FakeFetcher;

const PREVIEW_SITE_LINK = 'https://control.example.test/sites/01JD3V7Q8M2X4Y6Z9ABCDEFGHK';

beforeEach( function (): void {
	$this->now     = GateVectors::get( 'now' );
	$this->host    = GateVectors::get( 'preview_host' );
	$this->env     = GateVectors::environment();
	$this->fetcher = FakeFetcher::with( GateVectors::document() );
	$this->logs    = [];
	$this->dir     = sys_get_temp_dir() . '/vector-mu-gate-' . bin2hex( random_bytes( 6 ) );
	mkdir( $this->dir, 0700 );

	// A gate as one request in a container sees it; the cache directory
	// stands in for the container's /tmp.
	$this->gate = fn (): PreviewGate => new PreviewGate(
		Runtime::LAMBDA,
		fn ( string $name ): string|false => $this->env[ $name ] ?? false,
		fn (): int => $this->now,
		$this->fetcher,
		$this->dir,
		function ( string $line ): void {
			$this->logs[] = $line;
		}
	);

	$this->request = function ( string $uri, array $server = [], string $sapi = 'fpm-fcgi' ): array {
		return ( $this->gate )()->handle(
			$server + [
				'REQUEST_METHOD' => 'GET',
				'REQUEST_URI'    => $uri,
				'HTTP_HOST'      => $this->host,
			],
			$sapi
		);
	};

	$this->withCookie = fn ( string $name, string $uri = '/' ): array => ( $this->request )(
		$uri,
		[ 'HTTP_COOKIE' => 'wordpress_test_cookie=WP%20Cookie%20check; __Host-vector_gate=' . GateVectors::cookie( $name )['value'] ]
	);

	$this->signIn = fn ( string $token, string $r = '/' ): array => ( $this->request )(
		'/__vector/auth?r=' . rawurlencode( $r ) . '&t=' . rawurlencode( $token )
	);
} );

afterEach( function (): void {
	foreach ( glob( $this->dir . '/{,.}*', GLOB_BRACE ) ?: [] as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}

	rmdir( $this->dir );
} );

/**
 * A response's headers as name => value, the last of each name.
 *
 * @param array{headers: list<array{0: string, 1: string}>} $response
 * @return array<string, string>
 */
function gateHeaders( array $response ): array {
	$headers = [];

	foreach ( $response['headers'] as [ $name, $value ] ) {
		$headers[ $name ] = $value;
	}

	return $headers;
}

/**
 * Expects the private page: a 401 with no site content and the link.
 *
 * @param array{gated: bool, response: array{status: int, headers: list<array{0: string, 1: string}>, body: string}|null} $result
 */
function expectPrivate( array $result, string $link = PREVIEW_SITE_LINK ): void {
	expect( $result['gated'] )->toBeTrue()
		->and( $result['response'] )->not->toBeNull()
		->and( $result['response']['status'] )->toBe( 401 );

	$headers = gateHeaders( $result['response'] );

	expect( $headers['X-Robots-Tag'] )->toBe( 'noindex, nofollow' )
		->and( $headers['Cache-Control'] )->toBe( 'private, no-store' )
		->and( $headers['Content-Type'] )->toBe( 'text/html; charset=utf-8' )
		->and( $headers['Content-Security-Policy'] )->toStartWith( "default-src 'none'" )
		->and( $result['response']['body'] )->toContain( 'This preview site is private' );

	if ( '' === $link ) {
		expect( $result['response']['body'] )->not->toContain( '<a href' );
	} else {
		expect( $result['response']['body'] )->toContain( '<a href="' . $link . '" rel="noreferrer">Open in Vector</a>' );
	}
}

/**
 * Expects WordPress to serve the request on the preview hostname.
 *
 * @param array{gated: bool, response: mixed} $result
 */
function expectAllowed( array $result ): void {
	expect( $result )->toBe( [ 'gated' => true, 'response' => null ] );
}

// --- Sign-in ------------------------------------------------------------------

it( 'signs in with a valid user token, sets the cookie, and redirects', function (): void {
	$result = ( $this->signIn )( GateVectors::token( 'user' )['token'], '/wp-admin/?page=a&b=c' );

	expect( $result['gated'] )->toBeTrue()
		->and( $result['response']['status'] )->toBe( 303 )
		->and( $result['response']['body'] )->toBe( '' )
		->and( gateHeaders( $result['response'] ) )->toBe( [
			'X-Robots-Tag'           => 'noindex, nofollow',
			'Cache-Control'          => 'private, no-store',
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'no-referrer',
			// The same value the reference implementation makes for this token at this time.
			'Set-Cookie'             => '__Host-vector_gate=' . GateVectors::cookie( 'user_epoch7' )['value'] . '; Path=/; Max-Age=290; HttpOnly; Secure; SameSite=Lax',
			'Location'               => '/wp-admin/?page=a&b=c',
		] )
		->and( $this->fetcher->calls )->toBe( [ [ 'url' => GateVectors::documentUrl(), 'token' => GateVectors::DOCUMENT_TOKEN ] ] );
} );

it( 'caps a share token\'s cookie at 12 hours', function (): void {
	$result = ( $this->signIn )( GateVectors::token( 'share' )['token'] );

	expect( gateHeaders( $result['response'] )['Set-Cookie'] )
		->toBe( '__Host-vector_gate=' . GateVectors::cookie( 'share_epoch7' )['value'] . '; Path=/; Max-Age=43200; HttpOnly; Secure; SameSite=Lax' );
} );

it( 'redirects only to a safe relative path', function ( string $r, string $want ): void {
	$result = ( $this->signIn )( GateVectors::token( 'user' )['token'], $r );

	expect( gateHeaders( $result['response'] )['Location'] )->toBe( $want );
} )->with( [
	'scheme-relative' => [ '//evil.example/', '/' ],
	'backslash'       => [ '/\\evil.example', '/' ],
	'absolute'        => [ 'https://evil.example/', '/' ],
	'space'           => [ '/a b', '/a%20b' ],
	'newline'         => [ "/a\r\nSet-Cookie: x=y", '/' ],
] );

it( 'redirects to / without an r parameter', function (): void {
	$result = ( $this->request )( '/__vector/auth?t=' . GateVectors::token( 'user' )['token'] );

	expect( gateHeaders( $result['response'] )['Location'] )->toBe( '/' );
} );

it( 'refuses a token it can\'t accept with the private page', function ( string $name, string $reason ): void {
	$result = ( $this->signIn )( GateVectors::token( $name )['token'] );

	expectPrivate( $result );
	expect( gateHeaders( $result['response'] ) )->toHaveKey( 'Referrer-Policy' )->not->toHaveKey( 'Set-Cookie' )
		->and( $this->logs )->toBe( [ 'Vector MU: preview gate refused a sign-in: ' . $reason ] );
} )->with( [
	'expired'             => [ 'expired', 'token expired' ],
	'for a dev hostname'  => [ 'wrong_host', 'token for another host' ],
	'retired key'         => [ 'retired_key', 'token key ID unknown' ],
	'tampered signature'  => [ 'tampered_signature', 'token signature bad' ],
	'revoked share link'  => [ 'share_revoked', 'share link revoked' ],
] );

it( 'accepts a rotated-in key as soon as the document lists it', function (): void {
	$document         = GateVectors::document();
	$document['keys'] = [ ...$document['keys'], [ 'kid' => 'k2', 'public_key' => GateVectors::all()['keys']['k2']['public_key'] ] ];
	$this->fetcher    = FakeFetcher::with( $document );

	expect( ( $this->signIn )( GateVectors::token( 'retired_key' )['token'] )['response']['status'] )->toBe( 303 );
} );

it( 'wants exactly one well-formed token', function ( string $query ): void {
	expectPrivate( ( $this->request )( '/__vector/auth' . $query ) );
} )->with( [
	'none'            => [ '' ],
	'empty'           => [ '?t=' ],
	'two'             => [ '?t=a&t=b' ],
	'semicolon'       => [ '?t=a;r=/' ],
	'bad escape'      => [ '?t=%zz' ],
	'too long'        => [ '?t=' . str_repeat( 'a', 12289 ) ],
] );

it( 'answers 405 for another method on the auth path', function (): void {
	$result = ( $this->request )( '/__vector/auth?t=x', [ 'REQUEST_METHOD' => 'POST' ] );

	expect( $result['response']['status'] )->toBe( 405 )
		->and( gateHeaders( $result['response'] )['Allow'] )->toBe( 'GET, HEAD' );
} );

it( 'never logs a token', function (): void {
	foreach ( [ 'user', 'expired', 'retired_key' ] as $name ) {
		( $this->signIn )( GateVectors::token( $name )['token'] );
	}

	$logs = implode( "\n", $this->logs );

	foreach ( [ 'user', 'expired', 'retired_key' ] as $name ) {
		[ , $payload, $signature ] = explode( '.', GateVectors::token( $name )['token'] );
		expect( $logs )->not->toContain( $payload )->not->toContain( $signature );
	}

	expect( $logs )->not->toContain( GateVectors::DOCUMENT_TOKEN )->not->toContain( 'vg1.' );
} );

// --- Cookies ------------------------------------------------------------------

it( 'lets a request with a valid cookie through to WordPress', function ( string $name ): void {
	expectAllowed( ( $this->withCookie )( $name, '/some/page/?x=1' ) );
} )->with( [ 'user' => 'user_epoch7', 'share' => 'share_epoch7' ] );

it( 'refuses a cookie it can\'t accept', function ( string $name ): void {
	expectPrivate( ( $this->withCookie )( $name ) );
} )->with( [
	'revoked share link'   => 'share_revoked_epoch7',
	'earlier epoch'        => 'epoch0_cookie_under_epoch7',
	'tampered MAC'         => 'tampered_mac',
	'tampered kind'        => 'tampered_kind',
	'tampered expiry'      => 'tampered_exp',
] );

it( 'refuses a cookie at its expiry', function (): void {
	$cookie    = GateVectors::cookie( 'expired_at_exp' );
	$this->now = $cookie['now'] - 1;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );

	$this->now = $cookie['now'];

	expectPrivate( ( $this->withCookie )( 'user_epoch7' ) );
} );

it( 'signs everyone out when the epoch rises', function (): void {
	$this->fetcher = FakeFetcher::with( GateVectors::document( [ 'session_epoch' => 0 ] ) );

	expectAllowed( ( $this->withCookie )( 'user_epoch0' ) );
	expectPrivate( ( $this->withCookie )( 'user_epoch7' ) );
} );

it( 'reads a cookie from the raw header among others', function (): void {
	$good = GateVectors::cookie( 'user_epoch7' )['value'];

	expectAllowed( ( $this->request )( '/', [ 'HTTP_COOKIE' => '__Host-vector_gate=bogus;__Host-vector_gate="' . $good . '"' ] ) );
	expectPrivate( ( $this->request )( '/', [ 'HTTP_COOKIE' => str_repeat( '__Host-vector_gate=bogus; ', 4 ) . '__Host-vector_gate=' . $good ] ) );
	expectPrivate( ( $this->request )( '/', [ 'HTTP_COOKIE' => 'Host-vector_gate=' . $good ] ) );
} );

it( 'refuses a request with no cookie without fetching the document', function (): void {
	expectPrivate( ( $this->request )( '/' ) );
	expect( $this->fetcher->calls )->toBe( [] );
} );

it( 'serves the private page without a body for HEAD', function (): void {
	$result = ( $this->request )( '/', [ 'REQUEST_METHOD' => 'HEAD' ] );

	expect( $result['response']['status'] )->toBe( 401 )
		->and( $result['response']['body'] )->toBe( '' )
		->and( (int) gateHeaders( $result['response'] )['Content-Length'] )->toBeGreaterThan( 0 );
} );

it( 'serves the private page at its own path and gates the rest of /__vector/', function (): void {
	expectPrivate( ( $this->request )( '/__vector/private' ) );
	expectPrivate( ( $this->request )( '/__vector/check' ) );
	expectAllowed( ( $this->withCookie )( 'user_epoch7', '/__vector/check' ) );
} );

// --- Where the gate applies ---------------------------------------------------

it( 'gates the preview hostname in any case and with a port', function ( string $host ): void {
	expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => $host ] ) );
} )->with( [ 'MY-SITE.Sites.Example.Test', 'my-site.sites.example.test:443' ] );

it( 'gates every host that isn\'t public, with the private page and no fetch', function ( string $host ): void {
	$result = ( $this->request )( '/', [ 'HTTP_HOST' => $host, 'HTTP_COOKIE' => '__Host-vector_gate=' . GateVectors::cookie( 'user_epoch7' )['value'] ] );

	expectPrivate( $result );
	expect( $this->fetcher->calls )->toBe( [] );
} )->with( [
	'vanity domain'      => 'my-site-abc123.ymirsite.com',
	'CloudFront'         => 'd1234abcd.cloudfront.net',
	'API Gateway'        => 'abc123.execute-api.us-west-2.amazonaws.com',
	'dev hostname'       => 'my-site.dev.example.test',
	'preview subdomain'  => 'x.my-site.sites.example.test',
	'no Host'            => '',
	'an invalid Host'    => 'bad_host.example',
] );

it( 'gates a forged X-Forwarded-Host that names a non-public host', function (): void {
	$this->env['VECTOR_PUBLIC_HOSTS'] = 'www.example.com';

	// Ymir's runtime sets HTTP_HOST from X-Forwarded-Host.
	expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example' ] ) );
} );

it( 'never signs in or accepts a cookie on a gated host other than the preview hostname', function (): void {
	$result = ( $this->request )( '/__vector/auth?t=' . GateVectors::token( 'user' )['token'], [ 'HTTP_HOST' => 'my-site-abc123.ymirsite.com' ] );

	expectPrivate( $result );
	expect( gateHeaders( $result['response'] ) )->not->toHaveKey( 'Set-Cookie' )->not->toHaveKey( 'Location' );
} );

it( 'never gates a public host, and sends it no gate headers', function ( string $host ): void {
	$this->env['VECTOR_PUBLIC_HOSTS'] = 'www.example.com,example.com';

	expect( ( $this->request )( '/', [ 'HTTP_HOST' => $host ] ) )->toBe( [ 'gated' => false, 'response' => null ] )
		->and( ( $this->request )( '/__vector/auth?t=x', [ 'HTTP_HOST' => $host ] ) )->toBe( [ 'gated' => false, 'response' => null ] )
		->and( $this->fetcher->calls )->toBe( [] )
		->and( $this->logs )->toBe( [] );
} )->with( [ 'www.example.com', 'Example.COM:443' ] );

it( 'keeps public hosts public even with a broken gate config', function (): void {
	$this->env['VECTOR_PUBLIC_HOSTS'] = 'www.example.com';
	unset( $this->env['VECTOR_GATE_COOKIE_SECRET'] );

	expect( ( $this->request )( '/', [ 'HTTP_HOST' => 'www.example.com' ] ) )->toBe( [ 'gated' => false, 'response' => null ] );
	expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => 'my-site-abc123.ymirsite.com' ] ), '' );
} );

it( 'gates the preview hostname even when the public list names it', function (): void {
	$this->env['VECTOR_PUBLIC_HOSTS'] = 'www.example.com,' . $this->host;

	expectPrivate( ( $this->request )( '/' ) );
	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
} );

it( 'gates every host when the public list is missing or empty', function ( ?string $value ): void {
	if ( null !== $value ) {
		$this->env['VECTOR_PUBLIC_HOSTS'] = $value;
	}

	expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => 'www.example.com' ] ) );
	expect( $this->logs )->toBe( [] );
} )->with( [ 'missing' => null, 'empty' => '' ] );

it( 'fails closed on an invalid public list, without logging it', function ( string $value ): void {
	$this->env['VECTOR_PUBLIC_HOSTS'] = $value;

	foreach ( [ 'www.example.com', 'example.com', $this->host ] as $host ) {
		expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => $host ] ) );
	}

	expect( $this->logs[0] )->toBe( 'Vector MU: preview gate: VECTOR_PUBLIC_HOSTS is invalid (an entry is not a lowercase DNS name); gating every host' )
		->and( implode( "\n", $this->logs ) )->not->toContain( 'example.com,' );
} )->with( [
	'uppercase entry'  => 'www.example.com,Example.com',
	'space after comma' => 'www.example.com, example.com',
	'trailing comma'   => 'www.example.com,example.com,',
	'port'             => 'www.example.com,example.com:443',
	'URL'              => 'www.example.com,https://example.com',
] );

it( 'exempts the build endpoint for GET and HEAD only', function (): void {
	expectAllowed( ( $this->request )( '/__vector/build?vector_check=1' ) );
	expectAllowed( ( $this->request )( '/__vector/build', [ 'REQUEST_METHOD' => 'HEAD' ] ) );
	expectPrivate( ( $this->request )( '/__vector/build', [ 'REQUEST_METHOD' => 'POST' ] ) );
	expectPrivate( ( $this->request )( '/__vector/build/' ) );
} );

it( 'exempts invocations that aren\'t HTTP requests', function ( string $sapi ): void {
	expect( ( $this->request )( '/', [], $sapi ) )->toBe( [ 'gated' => false, 'response' => null ] );
} )->with( [ 'cli', 'phpdbg' ] );

it( 'exempts a request with the loopback secret', function (): void {
	expectAllowed( ( $this->request )( '/wp-cron.php', [ 'HTTP_X_VECTOR_LOOPBACK' => GateVectors::LOOPBACK_SECRET ] ) );
	expectPrivate( ( $this->request )( '/wp-cron.php', [ 'HTTP_X_VECTOR_LOOPBACK' => strtoupper( GateVectors::LOOPBACK_SECRET ) ] ) );
	expectPrivate( ( $this->request )( '/wp-cron.php', [ 'HTTP_X_VECTOR_LOOPBACK' => '' ] ) );
} );

it( 'emits the gate\'s response or marks a gated response from register()', function (): void {
	$server  = $_SERVER;
	$emitted = [];
	$marked  = 0;
	$gate    = function ( string $runtime ) use ( &$emitted, &$marked ): PreviewGate {
		return new PreviewGate(
			$runtime,
			fn ( string $name ): string|false => $this->env[ $name ] ?? false,
			fn (): int => $this->now,
			$this->fetcher,
			$this->dir,
			function ( string $line ): void {
				$this->logs[] = $line;
			},
			function ( int $status, array $headers, string $body ) use ( &$emitted ): void {
				$emitted[] = $status;
			},
			function () use ( &$marked ): void {
				++$marked;
			},
			'fpm-fcgi'
		);
	};

	try {
		$_SERVER = [ 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => $this->host ];
		$gate( Runtime::LAMBDA )->register();
		$gate( Runtime::DEV )->register();

		$_SERVER['HTTP_COOKIE'] = '__Host-vector_gate=' . GateVectors::cookie( 'user_epoch7' )['value'];
		$gate( Runtime::LAMBDA )->register();

		$_SERVER['HTTP_HOST'] = 'my-site-abc123.ymirsite.com';
		$gate( Runtime::LAMBDA )->register();

		$this->env['VECTOR_PUBLIC_HOSTS'] = 'www.example.com';
		$_SERVER['HTTP_HOST']             = 'www.example.com';
		$gate( Runtime::LAMBDA )->register();
	} finally {
		$_SERVER = $server;
	}

	expect( $emitted )->toBe( [ 401, 401 ] )->and( $marked )->toBe( 1 );
} );

// --- A broken environment -----------------------------------------------------

it( 'refuses every non-exempt request on every hostname when a variable is missing or invalid', function ( string $name, string $value ): void {
	$this->env[ $name ] = $value;

	foreach ( [ $this->host, 'www.example.com' ] as $host ) {
		expectPrivate( ( $this->request )( '/', [ 'HTTP_HOST' => $host ] ), '' );
	}

	expect( $this->logs[0] )->toBe( 'Vector MU: preview gate refused a request: ' . $name . ' missing or invalid' )
		->and( $this->fetcher->calls )->toBe( [] );

	foreach ( GateVectors::environment() as $secret ) {
		if ( '' !== $value ) {
			expect( implode( "\n", $this->logs ) )->not->toContain( $value );
		}

		expect( implode( "\n", $this->logs ) )->not->toContain( $secret );
	}
} )->with( [
	'no preview host'          => [ 'VECTOR_PREVIEW_HOST', '' ],
	'uppercase preview host'   => [ 'VECTOR_PREVIEW_HOST', 'MY-SITE.sites.example.test' ],
	'http document URL'        => [ 'VECTOR_GATE_DOCUMENT_URL', 'http://control.example.test/api/v1/gate/previews/01JD3V7Q8M2X4Y6Z9ABCDEFGHK' ],
	'document URL with a port' => [ 'VECTOR_GATE_DOCUMENT_URL', 'https://control.example.test:8443/api/v1/gate/previews/01JD3V7Q8M2X4Y6Z9ABCDEFGHK' ],
	'document URL, other path' => [ 'VECTOR_GATE_DOCUMENT_URL', 'https://control.example.test/api/v1/gate/previews/01JD3V7Q8M2X4Y6Z9ABCDEFGHK/x' ],
	'short document token'     => [ 'VECTOR_GATE_DOCUMENT_TOKEN', 'abc' ],
	'token with a newline'     => [ 'VECTOR_GATE_DOCUMENT_TOKEN', "3Sbj1NMLfeNNH_HKBee_FbUUViRBsawucl6GW--K__\n" ],
	'cookie secret, 31 bytes'  => [ 'VECTOR_GATE_COOKIE_SECRET', base64_encode( str_repeat( 'x', 31 ) ) ],
	'cookie secret, base64url' => [ 'VECTOR_GATE_COOKIE_SECRET', rtrim( strtr( base64_encode( str_repeat( "\xfb", 32 ) ), '+/', '-_' ), '=' ) ],
	'uppercase loopback'       => [ 'VECTOR_LOOPBACK_SECRET', strtoupper( GateVectors::LOOPBACK_SECRET ) ],
] );

it( 'still exempts the build endpoint, CLI, and the loopback secret with a broken environment', function (): void {
	unset( $this->env['VECTOR_GATE_COOKIE_SECRET'] );

	expectAllowed( ( $this->request )( '/__vector/build' ) );
	expect( ( $this->request )( '/', [], 'cli' ) )->toBe( [ 'gated' => false, 'response' => null ] );
	expectAllowed( ( $this->request )( '/', [ 'HTTP_X_VECTOR_LOOPBACK' => GateVectors::LOOPBACK_SECRET ] ) );

	unset( $this->env['VECTOR_LOOPBACK_SECRET'] );

	expectPrivate( ( $this->request )( '/', [ 'HTTP_X_VECTOR_LOOPBACK' => GateVectors::LOOPBACK_SECRET ] ), '' );
} );

// --- The gate document --------------------------------------------------------

it( 'shares a fresh document across requests through the cache file', function (): void {
	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );

	$this->now += 59;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->fetcher->calls )->toHaveCount( 1 );

	$file = $this->dir . '/vector-mu-gate-01jd3v7q8m2x4y6z9abcdefghk.json';

	expect( $file )->toBeFile()
		->and( fileperms( $file ) & 0777 )->toBe( 0600 )
		->and( file_get_contents( $file ) )->not->toContain( GateVectors::DOCUMENT_TOKEN )
		->and( glob( $this->dir . '/.*.tmp' ) )->toBe( [] );

	$this->now += 1;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->fetcher->calls )->toHaveCount( 2 );
} );

it( 'clamps the document\'s ttl to 30-300 seconds', function ( int $ttl, int $fresh ): void {
	$this->fetcher = FakeFetcher::with( GateVectors::document( [ 'ttl' => $ttl ] ) );
	$start         = $this->now;

	( $this->withCookie )( 'user_epoch7' );

	$this->now = $start + $fresh - 1;
	( $this->withCookie )( 'user_epoch7' );
	expect( $this->fetcher->calls )->toHaveCount( 1 );

	$this->now = $start + $fresh;
	( $this->withCookie )( 'user_epoch7' );
	expect( $this->fetcher->calls )->toHaveCount( 2 );
} )->with( [
	'zero'    => [ 0, 30 ],
	'in range' => [ 120, 120 ],
	'too long' => [ 86400, 300 ],
] );

it( 'picks up a revocation on the next fetch', function (): void {
	expectAllowed( ( $this->withCookie )( 'share_epoch7' ) );

	$this->fetcher->answer = FakeFetcher::answer( GateVectors::document( [ 'revoked_share_links' => [ GateVectors::get( 'share_id' ) ] ] ) );
	$this->now            += 60;

	expectPrivate( ( $this->withCookie )( 'share_epoch7' ) );
} );

it( 'uses the last good document when a refetch fails within the grace', function (): void {
	expectAllowed( ( $this->withCookie )( 'share_epoch7' ) );

	$this->fetcher->answer = FakeFetcher::answer( null );
	$this->now            += DocumentStore::STALE_GRACE - 1;

	expectAllowed( ( $this->withCookie )( 'share_epoch7' ) );
	expect( $this->logs )->toBe( [ 'Vector MU: preview gate: document fetch failed: curl error 28 (Timeout was reached)' ] );
} );

it( 'closes once the last good document is an hour old', function (): void {
	expectAllowed( ( $this->withCookie )( 'share_epoch7' ) );

	$this->fetcher->answer = FakeFetcher::answer( null );
	$this->now            += DocumentStore::STALE_GRACE;

	expectPrivate( ( $this->withCookie )( 'share_epoch7' ) );
	expectPrivate( ( $this->signIn )( GateVectors::token( 'share' )['token'] ) );
} );

it( 'waits before retrying a failed fetch', function (): void {
	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );

	$this->fetcher->answer = FakeFetcher::answer( null );
	$this->now            += 60;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->fetcher->calls )->toHaveCount( 2 );

	$this->now += DocumentStore::RETRY_AFTER - 1;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->fetcher->calls )->toHaveCount( 2 );

	$this->fetcher->answer = FakeFetcher::answer( GateVectors::document() );
	$this->now            += 1;

	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->fetcher->calls )->toHaveCount( 3 );
} );

it( 'refuses everyone in a cold container whose fetch fails', function (): void {
	$this->fetcher = FakeFetcher::with( null );

	expectPrivate( ( $this->withCookie )( 'user_epoch7' ) );
	expectPrivate( ( $this->signIn )( GateVectors::token( 'user' )['token'] ) );
	expect( $this->logs )->toContain( 'Vector MU: preview gate refused a sign-in: no gate document' );
} );

it( 'treats a document for another site, host, or version as a failed fetch', function ( array $overrides, string $reason ): void {
	$this->fetcher = FakeFetcher::with( GateVectors::document( $overrides ) );

	expectPrivate( ( $this->withCookie )( 'user_epoch7' ) );
	expect( $this->logs[0] )->toBe( 'Vector MU: preview gate: ' . $reason );
} )->with( [
	'site'             => [ [ 'site_id' => '01JD3V7Q8M2X4Y6Z9ABCDEFGHM' ], 'document is for another site' ],
	'host'             => [ [ 'host' => 'other.sites.example.test' ], 'document is for another host' ],
	'version'          => [ [ 'version' => 2 ], 'document version is not 1' ],
	'no keys'          => [ [ 'keys' => [] ], 'document keys must be 1 to 8 keys' ],
	'bad key'          => [ [ 'keys' => [ [ 'kid' => 'k1', 'public_key' => 'AAAA' ] ] ], 'document has an invalid key' ],
	'repeated kid'     => [ [ 'keys' => [ GateVectors::document()['keys'][0], GateVectors::document()['keys'][0] ] ], 'document repeats a key ID' ],
	'negative epoch'   => [ [ 'session_epoch' => -1 ], 'document session_epoch invalid' ],
	'string ttl'       => [ [ 'ttl' => '60' ], 'document ttl invalid' ],
	'bad revocation'   => [ [ 'revoked_share_links' => [ 'x' ] ], 'document has an invalid revoked share link' ],
	'http control URL' => [ [ 'control_url' => 'http://control.example.test' ], 'document control_url invalid' ],
] );

it( 'links to Vector Control from the environment when it has no document', function (): void {
	$this->fetcher = FakeFetcher::with( null );

	expectPrivate( ( $this->signIn )( GateVectors::token( 'user' )['token'] ) );
} );

it( 'ignores a cache file others could have written', function (): void {
	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );

	$file = $this->dir . '/vector-mu-gate-01jd3v7q8m2x4y6z9abcdefghk.json';
	chmod( $file, 0644 );
	$this->fetcher->answer = FakeFetcher::answer( null );

	expectPrivate( ( $this->withCookie )( 'user_epoch7' ) );
} );

it( 'ignores a cache file from another environment', function (): void {
	expectAllowed( ( $this->withCookie )( 'user_epoch7' ) );

	$this->fetcher->answer = FakeFetcher::answer( null );
	$this->env['VECTOR_PREVIEW_HOST'] = 'my-site.sites2.example.test';
	$this->host                       = 'my-site.sites2.example.test';

	expectPrivate( ( $this->request )( '/', [ 'HTTP_COOKIE' => '__Host-vector_gate=' . GateVectors::cookie( 'user_epoch7' )['value'] ] ) );
} );

it( 'fetches over HTTPS only', function (): void {
	expect( fn () => ( new CurlDocumentFetcher() )->fetch( 'http://127.0.0.1:9/api/v1/gate/previews/x', 'token' ) )
		->toThrow( GateException::class, 'curl error 1 ' );
} );

it( 'keeps the exemptions on every gated host', function (): void {
	$vanity = [ 'HTTP_HOST' => 'my-site-abc123.ymirsite.com' ];

	expectAllowed( ( $this->request )( '/__vector/build', $vanity ) );
	expectAllowed( ( $this->request )( '/', $vanity + [ 'HTTP_X_VECTOR_LOOPBACK' => GateVectors::LOOPBACK_SECRET ] ) );
	expect( ( $this->request )( '/', $vanity, 'cli' ) )->toBe( [ 'gated' => false, 'response' => null ] );
} );
