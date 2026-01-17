<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST API endpoints for Vector platform communication.
 *
 * Provides authenticated endpoints that Vector can call to interact
 * with the WordPress site. Uses HMAC signature verification.
 */
final class VectorApi implements ModuleInterface {

	private const NAMESPACE = 'vector/v1';

	private const OPTION_HMAC_SECRET = 'vector_hmac_secret';

	private const SIGNATURE_VERSION = 'v1';

	/**
	 * Timestamp tolerance in seconds (5 minutes).
	 */
	private const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Future tolerance in seconds (1 minute).
	 */
	private const FUTURE_TOLERANCE = 60;

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ping',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handlePing' ],
				'permission_callback' => [ $this, 'verifySignature' ],
			]
		);
	}

	/**
	 * Handle the ping endpoint.
	 */
	public function handlePing(): WP_REST_Response {
		return new WP_REST_Response( 'pong', 200 );
	}

	/**
	 * Verify HMAC signature from X-Signature header.
	 *
	 * @param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function verifySignature( WP_REST_Request $request ) {
		$secret = get_option( self::OPTION_HMAC_SECRET );

		if ( ! is_string( $secret ) || '' === $secret ) {
			return new WP_Error(
				'vector_not_configured',
				'HMAC secret not configured',
				[ 'status' => 403 ]
			);
		}

		$signatureHeader = $request->get_header( 'X-Signature' );

		if ( null === $signatureHeader || '' === $signatureHeader ) {
			return new WP_Error(
				'vector_missing_signature',
				'Missing signature header',
				[ 'status' => 401 ]
			);
		}

		$parsed = $this->parseSignatureHeader( $signatureHeader );

		if ( null === $parsed ) {
			return new WP_Error(
				'vector_invalid_signature',
				'Invalid signature header format',
				[ 'status' => 401 ]
			);
		}

		[ $timestamp, $signature ] = $parsed;

		// Validate timestamp.
		$now = time();

		if ( $now - $timestamp > self::TIMESTAMP_TOLERANCE ) {
			return new WP_Error(
				'vector_expired_signature',
				'Signature timestamp too old',
				[ 'status' => 401 ]
			);
		}

		if ( $timestamp > $now + self::FUTURE_TOLERANCE ) {
			return new WP_Error(
				'vector_future_signature',
				'Signature timestamp too far in future',
				[ 'status' => 401 ]
			);
		}

		// Verify signature.
		$payload           = $request->get_body();
		$expectedSignature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

		if ( ! hash_equals( $expectedSignature, $signature ) ) {
			return new WP_Error(
				'vector_signature_mismatch',
				'Signature verification failed',
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Parse signature header into timestamp and signature.
	 *
	 * Expected format: t={timestamp},v1={signature}
	 *
	 * @return array{int, string}|null
	 */
	private function parseSignatureHeader( string $header ): ?array {
		$parts     = explode( ',', $header );
		$timestamp = null;
		$signature = null;

		foreach ( $parts as $part ) {
			$elements = explode( '=', $part, 2 );

			if ( 2 !== count( $elements ) ) {
				continue;
			}

			[ $key, $value ] = $elements;

			if ( 't' === $key && null === $timestamp ) {
				if ( ! ctype_digit( $value ) ) {
					return null;
				}
				$timestamp = (int) $value;
			} elseif ( self::SIGNATURE_VERSION === $key && null === $signature ) {
				$signature = $value;
			}
		}

		if ( null === $timestamp || null === $signature ) {
			return null;
		}

		return [ $timestamp, $signature ];
	}
}
