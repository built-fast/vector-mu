<?php

declare(strict_types=1);

namespace VectorMU\Gate;

/**
 * The gate's v1 cookie: derives its key, makes its value, and checks it.
 *
 * Format: v1.<exp>.<kind>.<sub>.<base64url(HMAC-SHA256(key, CONTEXT ||
 * host || 0 || exp || 0 || kind || 0 || sub))>.
 */
final class Cookie {

	public const VERSION = 'v1';

	public const SECRET_SIZE = 32;

	/** Prefixed to the MAC's input. */
	private const CONTEXT = "vector-dev-gate-cookie-v1\x00";

	/** Prefixed to the epoch in key()'s derivation (cookieEpochContext). */
	private const EPOCH_CONTEXT = "vector-dev-gate-cookie-epoch-v1\x00";

	private function __construct() {
	}

	/**
	 * The MAC key for a session epoch: the secret itself at epoch 0, and
	 * HMAC-SHA256(secret, EPOCH_CONTEXT || uint64 big-endian epoch) above
	 * it, so raising the epoch invalidates every earlier cookie.
	 *
	 * @throws GateException For a secret that isn't 32 bytes or a negative epoch.
	 */
	public static function key( string $secret, int $epoch ): string {
		if ( self::SECRET_SIZE !== strlen( $secret ) ) {
			throw new GateException( 'cookie secret is not 32 bytes' );
		}

		if ( $epoch < 0 ) {
			throw new GateException( 'session epoch is negative' );
		}

		if ( 0 === $epoch ) {
			return $secret;
		}

		return hash_hmac( 'sha256', self::EPOCH_CONTEXT . pack( 'J', $epoch ), $secret, true );
	}

	/**
	 * The cookie value for $session, MACed with $key.
	 *
	 * @throws GateException For a key that isn't 32 bytes or an incomplete session.
	 */
	public static function value( string $key, Session $session ): string {
		if ( self::SECRET_SIZE !== strlen( $key ) ) {
			throw new GateException( 'cookie key is not 32 bytes' );
		}

		if ( ! Protocol::validHost( $session->host ) || ! Protocol::validKind( $session->kind )
			|| ! Protocol::validId( $session->sub ) || $session->exp <= 0 ) {
			throw new GateException( 'session needs a host, kind, subject, and expiry' );
		}

		$exp = (string) $session->exp;
		$mac = self::mac( $key, $session->host, $exp, $session->kind, $session->sub );

		return implode( '.', [ self::VERSION, $exp, $session->kind, $session->sub, Protocol::base64UrlEncode( $mac ) ] );
	}

	/**
	 * Checks a cookie value against $key for a request to $host at $now and
	 * returns its session. The MAC is compared in constant time before
	 * anything but the value's shape is trusted; the expiry must be after
	 * now. Revocation is the caller's.
	 *
	 * @throws GateException With a reason for logs.
	 */
	public static function verify( string $key, string $value, string $host, int $now ): Session {
		if ( self::SECRET_SIZE !== strlen( $key ) ) {
			throw new GateException( 'no cookie key' );
		}

		if ( strlen( $value ) > Protocol::MAX_COOKIE_LENGTH ) {
			throw new GateException( 'cookie too long' );
		}

		$parts = explode( '.', $value );

		if ( 5 !== count( $parts ) || self::VERSION !== $parts[0] ) {
			throw new GateException( 'not a v1 cookie' );
		}

		[ , $exp, $kind, $sub, $encodedMac ] = $parts;

		// Only the canonical form, so each session has one cookie value.
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/', $exp ) || (string) (int) $exp !== $exp ) {
			throw new GateException( 'cookie expiry bad' );
		}

		$mac = Protocol::base64UrlDecode( $encodedMac );

		if ( null === $mac || 32 !== strlen( $mac ) ) {
			throw new GateException( 'cookie MAC encoding bad' );
		}

		if ( ! hash_equals( self::mac( $key, $host, $exp, $kind, $sub ), $mac ) ) {
			throw new GateException( 'cookie MAC bad' );
		}

		if ( ! Protocol::validKind( $kind ) || ! Protocol::validId( $sub ) ) {
			throw new GateException( 'cookie kind or subject bad' );
		}

		$expires = (int) $exp;

		if ( $now >= $expires ) {
			throw new GateException( 'cookie expired' );
		}

		return new Session( $host, $kind, $sub, $expires );
	}

	private static function mac( string $key, string $host, string $exp, string $kind, string $sub ): string {
		return hash_hmac( 'sha256', self::CONTEXT . $host . "\x00" . $exp . "\x00" . $kind . "\x00" . $sub, $key, true );
	}
}
