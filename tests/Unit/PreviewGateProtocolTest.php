<?php

declare(strict_types=1);

use VectorMU\Gate\Claims;
use VectorMU\Gate\Cookie;
use VectorMU\Gate\GateException;
use VectorMU\Gate\Protocol;
use VectorMU\Gate\Session;
use VectorMU\Gate\Token;
use VectorMU\Tests\Support\GateVectors;

// Every case here comes from fixed test vectors (tests/fixtures/gate-vectors.json),
// so the gate accepts and refuses exactly what the token and cookie format allow.

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function gateCases( string $kind ): array {
	$cases = [];

	foreach ( GateVectors::get( $kind ) as $case ) {
		$cases[ $case['name'] ] = [ $case ];
	}

	return $cases;
}

it( 'verifies tokens', function ( array $case ): void {
	$keys = [ 'k1' => GateVectors::publicKey( 'k1' ) ];

	try {
		$claims = Token::verify( $case['token'], $keys, $case['host'], $case['now'] );
	} catch ( GateException ) {
		$claims = null;
	}

	expect( null !== $claims )->toBe( $case['valid'] );

	if ( $case['valid'] ) {
		$want = $case['claims'];

		expect( $claims )->toEqual( new Claims( $want['kid'], $want['host'], $want['kind'], $want['sub'], $want['iat'], $want['exp'] ) );
	}
} )->with( gateCases( 'tokens' ) );

it( 'accepts a retired key\'s token only while the document lists the key', function (): void {
	$case = GateVectors::token( 'retired_key' );
	$both = [
		'k1' => GateVectors::publicKey( 'k1' ),
		'k2' => GateVectors::publicKey( 'k2' ),
	];

	expect( Token::verify( $case['token'], $both, $case['host'], $case['now'] )->kid )->toBe( 'k2' )
		->and( fn () => Token::verify( $case['token'], [ 'k1' => $both['k1'] ], $case['host'], $case['now'] ) )
		->toThrow( GateException::class, 'token key ID unknown' );
} );

it( 'refuses a token longer than 2048 bytes before decoding it', function (): void {
	$token = 'vg1.' . str_repeat( 'A', 2045 );

	expect( fn () => Token::verify( $token, [], 'a.test', 1 ) )->toThrow( GateException::class, 'token too long' );
} );

it( 'derives cookie keys', function (): void {
	$secret = base64_decode( GateVectors::get( 'cookie_secret_b64' ), true );

	foreach ( GateVectors::get( 'cookie_keys' ) as $epoch => $hex ) {
		expect( bin2hex( Cookie::key( $secret, (int) $epoch ) ) )->toBe( $hex );
	}
} );

it( 'refuses a bad cookie secret or a negative epoch', function (): void {
	expect( fn () => Cookie::key( str_repeat( 'x', 31 ), 0 ) )->toThrow( GateException::class )
		->and( fn () => Cookie::key( str_repeat( 'x', 32 ), -1 ) )->toThrow( GateException::class );
} );

it( 'makes the expected cookie values', function (): void {
	$now      = GateVectors::get( 'now' );
	$sessions = GateVectors::get( 'sessions' );
	$key7     = hex2bin( GateVectors::get( 'cookie_keys' )['7'] );
	$key0     = hex2bin( GateVectors::get( 'cookie_keys' )['0'] );
	$user     = GateVectors::token( 'user' )['claims'];
	$share    = GateVectors::token( 'share' )['claims'];

	$userSession  = Session::fromClaims( new Claims( $user['kid'], $user['host'], $user['kind'], $user['sub'], $user['iat'], $user['exp'] ), $now );
	$shareSession = Session::fromClaims( new Claims( $share['kid'], $share['host'], $share['kind'], $share['sub'], $share['iat'], $share['exp'] ), $now );

	expect( $userSession->exp )->toBe( $sessions['user']['ExpiresAt'] )
		->and( $shareSession->exp )->toBe( $sessions['share']['ExpiresAt'] )
		->and( Cookie::value( $key7, $userSession ) )->toBe( GateVectors::cookie( 'user_epoch7' )['value'] )
		->and( Cookie::value( $key0, $userSession ) )->toBe( GateVectors::cookie( 'user_epoch0' )['value'] )
		->and( Cookie::value( $key7, $shareSession ) )->toBe( GateVectors::cookie( 'share_epoch7' )['value'] );
} );

it( 'verifies cookies', function ( array $case ): void {
	try {
		$session = Cookie::verify( (string) hex2bin( $case['key_hex'] ), $case['value'], $case['host'], $case['now'] );
	} catch ( GateException ) {
		$session = null;
	}

	expect( null !== $session )->toBe( $case['valid'] );

	if ( $case['valid'] ) {
		expect( $session )->toEqual( new Session( $case['host'], $case['kind'], $case['sub'], $case['exp'] ) );
	}
} )->with( gateCases( 'cookies' ) );

it( 'refuses a cookie longer than 256 bytes', function (): void {
	$value = GateVectors::cookie( 'user_epoch7' )['value'] . str_repeat( 'A', 256 );

	expect( fn () => Cookie::verify( str_repeat( 'k', 32 ), $value, 'a.test', 1 ) )->toThrow( GateException::class, 'cookie too long' );
} );

it( 'checks redirects', function (): void {
	foreach ( GateVectors::get( 'redirects' ) as $input => $want ) {
		expect( Protocol::safeRedirect( (string) $input ) )->toBe( $want, 'for ' . json_encode( $input ) );
	}
} );

it( 'reads Host headers', function (): void {
	foreach ( GateVectors::get( 'hosts' ) as $header => $want ) {
		expect( Protocol::requestHost( (string) $header ) )->toBe( false === $want ? null : $want, 'for ' . json_encode( $header ) );
	}
} );

it( 'links to the site', function (): void {
	foreach ( GateVectors::get( 'control_urls' ) as $base => $want ) {
		expect( Protocol::controlSiteUrl( (string) $base, GateVectors::get( 'site_id' ) ) )->toBe( $want, 'for ' . json_encode( $base ) );
	}

	expect( Protocol::controlSiteUrl( GateVectors::CONTROL_URL, 'not-an-id' ) )->toBe( GateVectors::CONTROL_URL . '/' );
} );

it( 'accepts only ULIDs ids.Valid accepts', function (): void {
	expect( Protocol::validId( '01JD3V7Q8M2X4Y6Z9ABCDEFGHK' ) )->toBeTrue()
		->and( Protocol::validId( '7ZZZZZZZZZZZZZZZZZZZZZZZZZ' ) )->toBeTrue()
		->and( Protocol::validId( '8ZZZZZZZZZZZZZZZZZZZZZZZZZ' ) )->toBeFalse()
		->and( Protocol::validId( '01jd3v7q8m2x4y6z9abcdefghk' ) )->toBeFalse()
		->and( Protocol::validId( '01JD3V7Q8M2X4Y6Z9ABCDEFGHU' ) )->toBeFalse()
		->and( Protocol::validId( "01JD3V7Q8M2X4Y6Z9ABCDEFGHK\n" ) )->toBeFalse();
} );
