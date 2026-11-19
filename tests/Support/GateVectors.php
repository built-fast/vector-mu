<?php

declare(strict_types=1);

namespace VectorMU\Tests\Support;

use RuntimeException;

/**
 * The preview gate's fixed test vectors (tests/fixtures/gate-vectors.json):
 * keys, tokens, cookies, and expected results for the token and cookie format.
 */
final class GateVectors {

	public const CONTROL_URL = 'https://control.example.test';

	public const DOCUMENT_TOKEN = '3Sbj1NMLfeNNH_HKBee_FbUUViRBsawucl6GW--K__w';

	public const LOOPBACK_SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** @var array<string, mixed>|null */
	private static ?array $vectors = null;

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$vectors ) {
			$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/gate-vectors.json' );
			$data = is_string( $json ) ? json_decode( $json, true, 16, JSON_THROW_ON_ERROR ) : null;

			if ( ! is_array( $data ) ) {
				throw new RuntimeException( 'gate-vectors.json is unreadable' );
			}

			self::$vectors = $data;
		}

		return self::$vectors;
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ];
	}

	/**
	 * A token case by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function token( string $name ): array {
		foreach ( self::all()['tokens'] as $case ) {
			if ( $case['name'] === $name ) {
				return $case;
			}
		}

		throw new RuntimeException( 'no token vector ' . $name );
	}

	/**
	 * A cookie case by name.
	 *
	 * @return array<string, mixed>
	 */
	public static function cookie( string $name ): array {
		foreach ( self::all()['cookies'] as $case ) {
			if ( $case['name'] === $name ) {
				return $case;
			}
		}

		throw new RuntimeException( 'no cookie vector ' . $name );
	}

	/**
	 * A raw public key by key ID.
	 */
	public static function publicKey( string $kid ): string {
		return (string) base64_decode( self::all()['keys'][ $kid ]['public_key'], true );
	}

	/**
	 * The gate document URL for the vectors' site.
	 */
	public static function documentUrl(): string {
		return self::CONTROL_URL . '/api/v1/gate/previews/' . self::get( 'site_id' );
	}

	/**
	 * The five gate variables, valid.
	 *
	 * @return array<string, string>
	 */
	public static function environment(): array {
		return [
			'VECTOR_PREVIEW_HOST'        => self::get( 'preview_host' ),
			'VECTOR_GATE_DOCUMENT_URL'   => self::documentUrl(),
			'VECTOR_GATE_DOCUMENT_TOKEN' => self::DOCUMENT_TOKEN,
			'VECTOR_GATE_COOKIE_SECRET'  => self::get( 'cookie_secret_b64' ),
			'VECTOR_LOOPBACK_SECRET'     => self::LOOPBACK_SECRET,
		];
	}

	/**
	 * A gate document for the vectors' site: key k1 only, epoch 7, the
	 * revoked share link revoked, with $overrides applied.
	 *
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	public static function document( array $overrides = [] ): array {
		return array_merge(
			[
				'version'             => 1,
				'site_id'             => self::get( 'site_id' ),
				'host'                => self::get( 'preview_host' ),
				'control_url'         => self::CONTROL_URL,
				'session_epoch'       => 7,
				'keys'                => [
					[
						'kid'        => 'k1',
						'public_key' => self::all()['keys']['k1']['public_key'],
					],
				],
				'revoked_share_links' => [ self::get( 'revoked_share' ) ],
				'ttl'                 => 60,
			],
			$overrides
		);
	}
}
