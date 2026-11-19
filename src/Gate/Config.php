<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use Closure;

/**
 * The preview gate's five environment values, validated.
 *
 * Vector's deploy sets them as Ymir secrets on every deploy:
 *
 * - VECTOR_PREVIEW_HOST: the preview hostname, a lowercase DNS name.
 * - VECTOR_GATE_DOCUMENT_URL: https://<control domain>/api/v1/gate/previews/<site ID>.
 * - VECTOR_GATE_DOCUMENT_TOKEN: 32 bytes, unpadded base64url (43 characters).
 * - VECTOR_GATE_COOKIE_SECRET: 32 bytes, standard base64.
 * - VECTOR_LOOPBACK_SECRET: 32 bytes, lowercase hex.
 *
 * The site ID the document must name is the document URL's last segment,
 * and the private page's fallback link is the URL's origin.
 */
final class Config {

	public const PREVIEW_HOST = 'VECTOR_PREVIEW_HOST';

	public const DOCUMENT_URL = 'VECTOR_GATE_DOCUMENT_URL';

	public const DOCUMENT_TOKEN = 'VECTOR_GATE_DOCUMENT_TOKEN';

	public const COOKIE_SECRET = 'VECTOR_GATE_COOKIE_SECRET';

	public const LOOPBACK_SECRET = 'VECTOR_LOOPBACK_SECRET';

	/** The site's live custom domains, never gated; set at go-live. */
	public const PUBLIC_HOSTS = 'VECTOR_PUBLIC_HOSTS';

	private const DOCUMENT_PATH = '/api/v1/gate/previews/';

	/**
	 * @param string $previewHost    The gated hostname.
	 * @param string $documentUrl    Where the gate document is fetched from.
	 * @param string $controlUrl     The document URL's origin, https://<host>.
	 * @param string $siteId         The site's ID, from the document URL.
	 * @param string $documentToken  The document's bearer token.
	 * @param string $cookieSecret   The raw 32-byte cookie secret.
	 * @param string $loopbackSecret The loopback secret, as hex.
	 */
	private function __construct(
		public readonly string $previewHost,
		public readonly string $documentUrl,
		public readonly string $controlUrl,
		public readonly string $siteId,
		public readonly string $documentToken,
		public readonly string $cookieSecret,
		public readonly string $loopbackSecret
	) {
	}

	/**
	 * Reads and validates the five values.
	 *
	 * @param Closure(string): (string|false) $env Reads one variable, like getenv().
	 * @return self|list<string> The config, or the names of the variables
	 *                           that are missing or invalid (never their values).
	 */
	public static function fromEnvironment( Closure $env ): self|array {
		$read = static function ( string $name ) use ( $env ): string {
			$value = $env( $name );

			return is_string( $value ) ? $value : '';
		};

		$problems = [];

		$previewHost = $read( self::PREVIEW_HOST );

		if ( ! Protocol::validHost( $previewHost ) ) {
			$problems[] = self::PREVIEW_HOST;
		}

		$documentUrl = $read( self::DOCUMENT_URL );
		$parsed      = self::parseDocumentUrl( $documentUrl );

		if ( null === $parsed ) {
			$problems[] = self::DOCUMENT_URL;
		}

		$documentToken = $read( self::DOCUMENT_TOKEN );

		if ( 1 !== preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $documentToken ) ) {
			$problems[] = self::DOCUMENT_TOKEN;
		}

		$cookieSecret = Protocol::decodeKey32( $read( self::COOKIE_SECRET ) );

		if ( null === $cookieSecret ) {
			$problems[] = self::COOKIE_SECRET;
		}

		$loopbackSecret = self::loopbackSecret( $env );

		if ( null === $loopbackSecret ) {
			$problems[] = self::LOOPBACK_SECRET;
		}

		if ( [] !== $problems || null === $parsed || null === $cookieSecret || null === $loopbackSecret ) {
			return $problems;
		}

		return new self( $previewHost, $documentUrl, $parsed[0], $parsed[1], $documentToken, $cookieSecret, $loopbackSecret );
	}

	/**
	 * VECTOR_LOOPBACK_SECRET when it's 64 lowercase hex characters, else null.
	 *
	 * @param Closure(string): (string|false) $env Reads one variable, like getenv().
	 */
	public static function loopbackSecret( Closure $env ): ?string {
		$value = $env( self::LOOPBACK_SECRET );

		return is_string( $value ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $value ) ? $value : null;
	}

	/**
	 * VECTOR_PUBLIC_HOSTS: a comma-separated list of lowercase DNS names
	 * that are never gated. Missing or empty is an empty list. Null when any
	 * entry isn't a valid lowercase DNS name (no spaces, no empty entries),
	 * which the caller must treat as no public hosts.
	 *
	 * @param Closure(string): (string|false) $env Reads one variable, like getenv().
	 * @return list<string>|null
	 */
	public static function publicHosts( Closure $env ): ?array {
		$value = $env( self::PUBLIC_HOSTS );

		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}

		$hosts = explode( ',', $value );

		foreach ( $hosts as $host ) {
			if ( ! Protocol::validHost( $host ) ) {
				return null;
			}
		}

		return $hosts;
	}

	/**
	 * The origin and site ID of a document URL, which must be exactly
	 * https://<host>/api/v1/gate/previews/<ULID>: no port, userinfo, query,
	 * or fragment.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function parseDocumentUrl( string $url ): ?array {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return null;
		}

		$rest  = substr( $url, 8 );
		$slash = strpos( $rest, '/' );

		if ( false === $slash ) {
			return null;
		}

		$host = substr( $rest, 0, $slash );
		$path = substr( $rest, $slash );

		if ( ! Protocol::validHost( $host ) || ! str_starts_with( $path, self::DOCUMENT_PATH ) ) {
			return null;
		}

		$siteId = substr( $path, strlen( self::DOCUMENT_PATH ) );

		return Protocol::validId( $siteId ) ? [ 'https://' . $host, $siteId ] : null;
	}
}
