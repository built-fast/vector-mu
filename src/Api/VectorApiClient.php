<?php

declare(strict_types=1);

namespace VectorMU\Api;

use WP_Error;

/**
 * HTTP client for Vector API with HMAC signature authentication.
 *
 * Signs requests using the same HMAC pattern expected by Vector's
 * VerifyVectorSiteSignature middleware.
 */
final class VectorApiClient {

	private const BASE_URL = 'https://api.builtfast.com/api/v1/vector/wordpress';

	private const OPTION_HMAC_SECRET = 'vector_hmac_secret';

	private const SIGNATURE_VERSION = 'v1';

	private const DEFAULT_TIMEOUT = 30;

	private string $baseUrl;

	private ?string $hmacSecret = null;

	public function __construct( ?string $baseUrl = null ) {
		$this->baseUrl = $baseUrl ?? self::BASE_URL;
	}

	/**
	 * Make a GET request to the Vector API.
	 *
	 * @param string               $endpoint Endpoint path (appended to base URL).
	 * @param array<string, mixed> $query    Query parameters.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	public function get( string $endpoint, array $query = [] ): array {
		$url = $this->buildUrl( $endpoint, $query );

		return $this->request( 'GET', $url );
	}

	/**
	 * Make a POST request to the Vector API.
	 *
	 * @param string               $endpoint Endpoint path (appended to base URL).
	 * @param array<string, mixed> $data     Request body data.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	public function post( string $endpoint, array $data = [] ): array {
		$url = $this->buildUrl( $endpoint );

		return $this->request( 'POST', $url, $data );
	}

	/**
	 * Make a PUT request to the Vector API.
	 *
	 * @param string               $endpoint Endpoint path (appended to base URL).
	 * @param array<string, mixed> $data     Request body data.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	public function put( string $endpoint, array $data = [] ): array {
		$url = $this->buildUrl( $endpoint );

		return $this->request( 'PUT', $url, $data );
	}

	/**
	 * Make a DELETE request to the Vector API.
	 *
	 * @param string               $endpoint Endpoint path (appended to base URL).
	 * @param array<string, mixed> $data     Request body data.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	public function delete( string $endpoint, array $data = [] ): array {
		$url = $this->buildUrl( $endpoint );

		return $this->request( 'DELETE', $url, $data );
	}

	/**
	 * Execute an HTTP request with HMAC signature.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Full URL.
	 * @param array<string, mixed> $data   Request body data.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	private function request( string $method, string $url, array $data = [] ): array {
		$secret = $this->getHmacSecret();

		if ( null === $secret ) {
			return [
				'success' => false,
				'error'   => 'HMAC secret not configured',
			];
		}

		$payload   = [] === $data ? '' : (string) wp_json_encode( $data );
		$signature = $this->generateSignature( $payload, $secret );

		$args = [
			'method'  => $method,
			'timeout' => self::DEFAULT_TIMEOUT,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-Signature'  => $signature,
			],
		];

		if ( '' !== $payload ) {
			$args['body'] = $payload;
		}

		$response = wp_remote_request( $url, $args );

		return $this->handleResponse( $response );
	}

	/**
	 * Generate HMAC signature header.
	 *
	 * Format: t={timestamp},v1={signature}
	 * Signature: HMAC-SHA256 of "{timestamp}.{payload}"
	 */
	private function generateSignature( string $payload, string $secret ): string {
		$timestamp = time();
		$data      = "{$timestamp}.{$payload}";
		$signature = hash_hmac( 'sha256', $data, $secret );

		return 't=' . $timestamp . ',' . self::SIGNATURE_VERSION . '=' . $signature;
	}

	/**
	 * Build full URL with optional query parameters.
	 *
	 * @param string               $endpoint Endpoint path.
	 * @param array<string, mixed> $query    Query parameters.
	 */
	private function buildUrl( string $endpoint, array $query = [] ): string {
		$url = rtrim( $this->baseUrl, '/' ) . '/' . ltrim( $endpoint, '/' );

		if ( [] !== $query ) {
			$url .= '?' . http_build_query( $query );
		}

		return $url;
	}

	/**
	 * Handle WordPress HTTP API response.
	 *
	 * @param array<string, mixed>|WP_Error $response Response from wp_remote_request.
	 * @return array{success: bool, data?: mixed, error?: string, status_code?: int}
	 */
	private function handleResponse( $response ): array {
		if ( is_wp_error( $response ) ) {
			return [
				'success' => false,
				'error'   => $response->get_error_message(),
			];
		}

		$statusCode = (int) wp_remote_retrieve_response_code( $response );
		$body       = wp_remote_retrieve_body( $response );
		$data       = json_decode( $body, true );

		if ( $statusCode >= 200 && $statusCode < 300 ) {
			return [
				'success'     => true,
				'data'        => $data,
				'status_code' => $statusCode,
			];
		}

		$error = 'Request failed';
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			$error = $data['message'];
		} elseif ( is_array( $data ) && isset( $data['error'] ) ) {
			$error = $data['error'];
		}

		return [
			'success'     => false,
			'error'       => $error,
			'data'        => $data,
			'status_code' => $statusCode,
		];
	}

	/**
	 * Get HMAC secret from WordPress option.
	 */
	private function getHmacSecret(): ?string {
		if ( null === $this->hmacSecret ) {
			$secret = get_option( self::OPTION_HMAC_SECRET );

			$this->hmacSecret = is_string( $secret ) && '' !== $secret ? $secret : null;
		}

		return $this->hmacSecret;
	}

	/**
	 * Check if the client is configured with an HMAC secret.
	 */
	public function isConfigured(): bool {
		return null !== $this->getHmacSecret();
	}
}
