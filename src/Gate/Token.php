<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use JsonException;
use SodiumException;
use stdClass;

/**
 * Verifies vg1 tokens.
 *
 * Format: vg1.<base64url(payload)>.<base64url(signature)>, unpadded, with
 * signature = Ed25519(key, CONTEXT || payload) over the
 * payload bytes exactly as encoded.
 */
final class Token {

	public const VERSION = 'vg1';

	/** Prefixed to the payload for signing. */
	public const CONTEXT = "vector-dev-gate-token-v1\x00";

	private const FIELDS = [ 'kid', 'host', 'kind', 'sub', 'iat', 'exp' ];

	private function __construct() {
	}

	/**
	 * Checks $token against $keys for a request to $host at $now and
	 * returns its claims. In order: the token's shape and size, its
	 * payload's strict JSON (no unknown fields, the right types), its key ID
	 * among $keys, the signature over the payload as encoded, the claims'
	 * fields, a lifetime within its kind's maximum, iat no more than the
	 * clock skew ahead of now, exp no more than the skew behind it, and host.
	 *
	 * @param array<string, string> $keys Raw 32-byte Ed25519 public keys by key ID.
	 * @throws GateException With a reason for logs.
	 */
	public static function verify( string $token, array $keys, string $host, int $now ): Claims {
		if ( strlen( $token ) > Protocol::MAX_TOKEN_LENGTH ) {
			throw new GateException( 'token too long' );
		}

		$parts = explode( '.', $token );

		if ( self::VERSION !== $parts[0] ) {
			throw new GateException( 'not a vg1 token' );
		}

		if ( 3 !== count( $parts ) ) {
			throw new GateException( 'token is not three dot-separated parts' );
		}

		$payload = Protocol::base64UrlDecode( $parts[1] );

		if ( null === $payload || '' === $payload ) {
			throw new GateException( 'token payload is not unpadded base64url' );
		}

		$signature = Protocol::base64UrlDecode( $parts[2] );

		if ( null === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			throw new GateException( 'token signature is not 64 bytes of unpadded base64url' );
		}

		$claims = self::decode( $payload );
		$key    = $keys[ $claims->kid ] ?? null;

		if ( null === $key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			throw new GateException( 'token key ID unknown' );
		}

		if ( ! self::signatureValid( $signature, self::CONTEXT . $payload, $key ) ) {
			throw new GateException( 'token signature bad' );
		}

		self::validate( $claims );

		if ( $claims->iat > $now + Protocol::CLOCK_SKEW ) {
			throw new GateException( 'token not valid yet' );
		}

		if ( $now > $claims->exp + Protocol::CLOCK_SKEW ) {
			throw new GateException( 'token expired' );
		}

		if ( $claims->host !== $host ) {
			throw new GateException( 'token for another host' );
		}

		return $claims;
	}

	/**
	 * Decodes the payload strictly: one JSON object holding exactly the six
	 * claims, strings and integers as they should be.
	 *
	 * @throws GateException
	 */
	private static function decode( string $payload ): Claims {
		try {
			$value = json_decode( $payload, false, 2, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new GateException( 'token payload is not JSON' );
		}

		if ( ! $value instanceof stdClass ) {
			throw new GateException( 'token payload is not an object' );
		}

		$fields = get_object_vars( $value );

		foreach ( array_keys( $fields ) as $name ) {
			if ( ! in_array( $name, self::FIELDS, true ) ) {
				throw new GateException( 'token payload has an unknown field' );
			}
		}

		// A missing or null field is left empty or 0 and fails validation
		// later.
		$strings = [];

		foreach ( [ 'kid', 'host', 'kind', 'sub' ] as $name ) {
			$strings[ $name ] = $fields[ $name ] ?? '';

			if ( ! is_string( $strings[ $name ] ) ) {
				throw new GateException( 'token ' . $name . ' is not a string' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a log reason, never output.
			}
		}

		$ints = [];

		foreach ( [ 'iat', 'exp' ] as $name ) {
			$ints[ $name ] = $fields[ $name ] ?? 0;

			if ( ! is_int( $ints[ $name ] ) ) {
				throw new GateException( 'token ' . $name . ' is not an integer' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a log reason, never output.
			}
		}

		return new Claims( $strings['kid'], $strings['host'], $strings['kind'], $strings['sub'], $ints['iat'], $ints['exp'] );
	}

	/**
	 * Checks the claims on their own, without a clock.
	 *
	 * @throws GateException
	 */
	private static function validate( Claims $claims ): void {
		if ( ! Protocol::validKeyId( $claims->kid ) ) {
			throw new GateException( 'token kid invalid' );
		}

		if ( ! Protocol::validHost( $claims->host ) ) {
			throw new GateException( 'token host invalid' );
		}

		if ( ! Protocol::validKind( $claims->kind ) ) {
			throw new GateException( 'token kind invalid' );
		}

		if ( ! Protocol::validId( $claims->sub ) ) {
			throw new GateException( 'token sub invalid' );
		}

		if ( $claims->iat <= 0 || $claims->exp <= $claims->iat ) {
			throw new GateException( 'token iat must be positive and before exp' );
		}

		if ( $claims->exp - $claims->iat > Protocol::maxLifetime( $claims->kind ) ) {
			throw new GateException( 'token lifetime too long for its kind' );
		}
	}

	private static function signatureValid( string $signature, string $message, string $key ): bool {
		if ( '' === $signature || '' === $key ) {
			return false;
		}

		try {
			return sodium_crypto_sign_verify_detached( $signature, $message, $key );
		} catch ( SodiumException ) {
			return false;
		}
	}
}
