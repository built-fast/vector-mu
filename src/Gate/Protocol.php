<?php

declare(strict_types=1);

namespace VectorMU\Gate;

/**
 * The parts of the gate's token and cookie format that tokens and cookies
 * share: names, paths, limits, and the validators.
 *
 * Vector Control signs tokens and the dev gate checks them with the same
 * format, so these must not change; the tests check them against fixed
 * vectors.
 */
final class Protocol {

	public const COOKIE_NAME = '__Host-vector_gate';

	public const AUTH_PATH = '/__vector/auth';

	public const PRIVATE_PATH = '/__vector/private';

	public const TOKEN_PARAM = 't';

	public const REDIRECT_PARAM = 'r';

	public const KIND_USER = 'user';

	public const KIND_SHARE = 'share';

	/** A user token's longest lifetime, in seconds. */
	public const MAX_USER_LIFETIME = 600;

	/** A share token's longest lifetime, in seconds. */
	public const MAX_SHARE_LIFETIME = 30 * 86400;

	/** Seconds a token's iat may be ahead of now, and its exp behind. */
	public const CLOCK_SKEW = 60;

	/** A cookie's longest lifetime, in seconds. */
	public const MAX_COOKIE_LIFETIME = 12 * 3600;

	public const MAX_KEYS = 8;

	public const MAX_TOKEN_LENGTH = 2048;

	public const MAX_COOKIE_LENGTH = 256;

	public const MAX_REDIRECT_LENGTH = 2048;

	private const MAX_HOST_LENGTH = 253;

	/** Matches what ids.Valid accepts: Crockford base32 within 128 bits. */
	private const ULID_PATTERN = '/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/';

	private function __construct() {
	}

	public static function validKind( string $kind ): bool {
		return self::KIND_USER === $kind || self::KIND_SHARE === $kind;
	}

	/**
	 * A kind's longest token lifetime in seconds, 0 for an unknown kind.
	 */
	public static function maxLifetime( string $kind ): int {
		return match ( $kind ) {
			self::KIND_USER  => self::MAX_USER_LIFETIME,
			self::KIND_SHARE => self::MAX_SHARE_LIFETIME,
			default          => 0,
		};
	}

	/**
	 * Whether $kid is 1 to 32 lowercase letters, digits, and dashes.
	 */
	public static function validKeyId( string $kid ): bool {
		return 1 === preg_match( '/\A[a-z0-9-]{1,32}\z/', $kid );
	}

	/**
	 * Whether $id is a ULID as Vector's ids.Valid accepts it.
	 */
	public static function validId( string $id ): bool {
		return 1 === preg_match( self::ULID_PATTERN, $id );
	}

	/**
	 * Whether $host is a lowercase DNS name of at most 253 bytes, with
	 * labels of 1 to 63 letters, digits, and dashes that neither start nor
	 * end with a dash, and no port or trailing dot.
	 */
	public static function validHost( string $host ): bool {
		if ( '' === $host || strlen( $host ) > self::MAX_HOST_LENGTH ) {
			return false;
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( 1 !== preg_match( '/\A[a-z0-9-]{1,63}\z/', $label ) || '-' === $label[0] || '-' === $label[ strlen( $label ) - 1 ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The hostname a Host header names, lowercased with any numeric port
	 * removed, or null when what's left isn't validHost().
	 */
	public static function requestHost( string $header ): ?string {
		$host  = strtolower( $header );
		$colon = strrpos( $host, ':' );

		if ( false !== $colon ) {
			$port = substr( $host, $colon + 1 );

			if ( '' !== $port && ! ctype_digit( $port ) ) {
				return null;
			}

			$host = substr( $host, 0, $colon );
		}

		return self::validHost( $host ) ? $host : null;
	}

	/**
	 * The path /__vector/auth redirects to for its r parameter: r itself
	 * when it's a path on this host, "/" otherwise. Spaces and non-ASCII
	 * bytes are percent-encoded so the Location header stays printable ASCII.
	 */
	public static function safeRedirect( string $r ): string {
		$length = strlen( $r );

		if ( 0 === $length || $length > self::MAX_REDIRECT_LENGTH || '/' !== $r[0] ) {
			return '/';
		}

		if ( $length > 1 && ( '/' === $r[1] || '\\' === $r[1] ) ) {
			return '/';
		}

		$out = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$c = ord( $r[ $i ] );

			if ( 0x5c === $c || $c < 0x20 || 0x7f === $c ) {
				return '/';
			}

			$out .= ( 0x20 === $c || $c >= 0x80 ) ? sprintf( '%%%02X', $c ) : $r[ $i ];
		}

		return $out;
	}

	/**
	 * Whether $url is exactly "https://" and a validHost() name.
	 */
	public static function validControlUrl( string $url ): bool {
		return str_starts_with( $url, 'https://' ) && self::validHost( substr( $url, 8 ) );
	}

	/**
	 * The link to a site's page in Vector Control: base + "/sites/<id>",
	 * base + "/" for an invalid ID, and "" for an invalid base.
	 */
	public static function controlSiteUrl( string $base, string $siteId ): string {
		if ( ! self::validControlUrl( $base ) ) {
			return '';
		}

		return self::validId( $siteId ) ? $base . '/sites/' . $siteId : $base . '/';
	}

	/**
	 * Unpadded base64url, the encoding of tokens and cookie MACs.
	 */
	public static function base64UrlEncode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the token and cookie encoding.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes unpadded base64url strictly: only the URL alphabet, no
	 * padding or whitespace, and the last character's unused bits zero, so
	 * each value has exactly one encoding. Null when $s isn't one.
	 */
	public static function base64UrlDecode( string $s ): ?string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]*\z/', $s ) || 1 === strlen( $s ) % 4 ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the token and cookie encoding.
		$bytes = base64_decode( strtr( $s, '-_', '+/' ), true );

		if ( false === $bytes || self::base64UrlEncode( $bytes ) !== $s ) {
			return null;
		}

		return $bytes;
	}

	/**
	 * Decodes a 32-byte key in standard, padded base64 as the gate document
	 * carries it, refusing anything that doesn't re-encode to itself.
	 */
	public static function decodeKey32( string $s ): ?string {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- the gate document's key encoding.
		$bytes = base64_decode( $s, true );
		$valid = false !== $bytes && 32 === strlen( $bytes ) && base64_encode( $bytes ) === $s;
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions

		return $valid ? $bytes : null;
	}
}
