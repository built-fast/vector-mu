<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use JsonException;

/**
 * A site's gate document from Vector Control:
 *
 *     {"version": 1, "site_id": "<ULID>", "host": "<preview hostname>",
 *      "control_url": "https://<control domain>", "session_epoch": 0,
 *      "keys": [{"kid": "…", "public_key": "<32 bytes, standard base64>"}],
 *      "revoked_share_links": ["<ULID>"], "ttl": 60}
 *
 * Every listed field is required and checked; fields it doesn't know are
 * ignored, so Vector Control can add one without breaking older sites.
 */
final class Document {

	public const VERSION = 1;

	public const MIN_TTL = 30;

	public const MAX_TTL = 300;

	/**
	 * @param string                $controlUrl   Vector Control's base URL.
	 * @param int                   $sessionEpoch The cookie key's epoch.
	 * @param array<string, string> $keys         Raw Ed25519 public keys by key ID.
	 * @param array<string, true>   $revoked      Revoked share link IDs.
	 * @param int                   $ttl          Seconds the document is fresh, clamped.
	 */
	private function __construct(
		public readonly string $controlUrl,
		public readonly int $sessionEpoch,
		public readonly array $keys,
		private readonly array $revoked,
		public readonly int $ttl
	) {
	}

	/**
	 * Parses and validates a document for the site and host the environment
	 * names.
	 *
	 * @throws GateException With a reason for logs, never the body.
	 */
	public static function parse( string $json, string $siteId, string $host ): self {
		try {
			$data = json_decode( $json, true, 8, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new GateException( 'document is not JSON' );
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw new GateException( 'document is not an object' );
		}

		if ( self::VERSION !== ( $data['version'] ?? null ) ) {
			throw new GateException( 'document version is not 1' );
		}

		$documentSite = $data['site_id'] ?? null;
		$documentHost = $data['host'] ?? null;

		if ( $documentSite !== $siteId ) {
			throw new GateException( 'document is for another site' );
		}

		if ( $documentHost !== $host ) {
			throw new GateException( 'document is for another host' );
		}

		$controlUrl = $data['control_url'] ?? null;

		if ( ! is_string( $controlUrl ) || ! Protocol::validControlUrl( $controlUrl ) ) {
			throw new GateException( 'document control_url invalid' );
		}

		$epoch = $data['session_epoch'] ?? null;

		if ( ! is_int( $epoch ) || $epoch < 0 ) {
			throw new GateException( 'document session_epoch invalid' );
		}

		$ttl = $data['ttl'] ?? null;

		if ( ! is_int( $ttl ) ) {
			throw new GateException( 'document ttl invalid' );
		}

		return new self(
			$controlUrl,
			$epoch,
			self::parseKeys( $data['keys'] ?? null ),
			self::parseRevoked( $data['revoked_share_links'] ?? null ),
			max( self::MIN_TTL, min( self::MAX_TTL, $ttl ) )
		);
	}

	/**
	 * Whether a session or claims of $kind for $sub is a revoked share link.
	 */
	public function isRevoked( string $kind, string $sub ): bool {
		return Protocol::KIND_SHARE === $kind && isset( $this->revoked[ $sub ] );
	}

	/**
	 * @return array<string, string>
	 * @throws GateException
	 */
	private static function parseKeys( mixed $entries ): array {
		if ( ! is_array( $entries ) || ! array_is_list( $entries ) || [] === $entries || count( $entries ) > Protocol::MAX_KEYS ) {
			throw new GateException( 'document keys must be 1 to 8 keys' );
		}

		$keys = [];

		foreach ( $entries as $entry ) {
			$kid = is_array( $entry ) ? ( $entry['kid'] ?? null ) : null;
			$pub = is_array( $entry ) ? ( $entry['public_key'] ?? null ) : null;
			$raw = is_string( $pub ) ? Protocol::decodeKey32( $pub ) : null;

			if ( ! is_string( $kid ) || ! Protocol::validKeyId( $kid ) || null === $raw ) {
				throw new GateException( 'document has an invalid key' );
			}

			if ( isset( $keys[ $kid ] ) ) {
				throw new GateException( 'document repeats a key ID' );
			}

			$keys[ $kid ] = $raw;
		}

		return $keys;
	}

	/**
	 * @return array<string, true>
	 * @throws GateException
	 */
	private static function parseRevoked( mixed $ids ): array {
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			throw new GateException( 'document revoked_share_links is not a list' );
		}

		$revoked = [];

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! Protocol::validId( $id ) ) {
				throw new GateException( 'document has an invalid revoked share link' );
			}

			$revoked[ $id ] = true;
		}

		return $revoked;
	}
}
