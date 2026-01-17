<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Api\VectorApiClient;

beforeEach(
	function () {
		$this->client = new VectorApiClient( 'https://api.example.com/api/v1/vector/wordpress' );
	}
);

describe(
	'VectorApiClient',
	function () {
		it(
			'can be instantiated',
			function () {
				expect( $this->client )->toBeInstanceOf( VectorApiClient::class );
			}
		);

		it(
			'uses default base URL when none provided',
			function () {
				$client = new VectorApiClient();

				expect( $client )->toBeInstanceOf( VectorApiClient::class );
			}
		);
	}
);

describe(
	'isConfigured',
	function () {
		it(
			'returns false when HMAC secret is not set',
			function () {
				Functions\when( 'get_option' )->justReturn( false );

				expect( $this->client->isConfigured() )->toBeFalse();
			}
		);

		it(
			'returns false when HMAC secret is empty string',
			function () {
				Functions\when( 'get_option' )->justReturn( '' );

				expect( $this->client->isConfigured() )->toBeFalse();
			}
		);

		it(
			'returns true when HMAC secret is configured',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret-key' );

				expect( $this->client->isConfigured() )->toBeTrue();
			}
		);
	}
);

describe(
	'generateSignature',
	function () {
		it(
			'generates signature in correct format',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'generateSignature' );
				$method->setAccessible( true );

				$signature = $method->invoke( $this->client, '{"test":"data"}', 'secret-key' );

				expect( $signature )->toMatch( '/^t=\d+,v1=[a-f0-9]{64}$/' );
			}
		);

		it(
			'generates valid HMAC-SHA256 signature',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'generateSignature' );
				$method->setAccessible( true );

				$payload = '{"action":"test"}';
				$secret  = 'my-secret-key';

				$signature = $method->invoke( $this->client, $payload, $secret );

				// Extract timestamp and signature from header.
				preg_match( '/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $matches );

				expect( $matches )->toHaveCount( 3 );

				$timestamp         = $matches[1];
				$extractedSig      = $matches[2];
				$expectedSignature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

				expect( $extractedSig )->toBe( $expectedSignature );
			}
		);

		it(
			'handles empty payload',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'generateSignature' );
				$method->setAccessible( true );

				$signature = $method->invoke( $this->client, '', 'secret-key' );

				expect( $signature )->toMatch( '/^t=\d+,v1=[a-f0-9]{64}$/' );
			}
		);
	}
);

describe(
	'buildUrl',
	function () {
		it(
			'builds URL with endpoint',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'buildUrl' );
				$method->setAccessible( true );

				$url = $method->invoke( $this->client, 'sites/123/sync' );

				expect( $url )->toBe( 'https://api.example.com/api/v1/vector/wordpress/sites/123/sync' );
			}
		);

		it(
			'handles leading slash in endpoint',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'buildUrl' );
				$method->setAccessible( true );

				$url = $method->invoke( $this->client, '/sites/123/sync' );

				expect( $url )->toBe( 'https://api.example.com/api/v1/vector/wordpress/sites/123/sync' );
			}
		);

		it(
			'builds URL with query parameters',
			function () {
				$method = new ReflectionMethod( VectorApiClient::class, 'buildUrl' );
				$method->setAccessible( true );

				$url = $method->invoke(
					$this->client,
					'sites/123',
					[
						'status' => 'active',
						'page'   => 1,
					]
				);

				expect( $url )->toBe( 'https://api.example.com/api/v1/vector/wordpress/sites/123?status=active&page=1' );
			}
		);
	}
);

describe(
	'request methods',
	function () {
		it(
			'returns error when HMAC secret not configured',
			function () {
				Functions\when( 'get_option' )->justReturn( false );

				$response = $this->client->get( 'sites/123' );

				expect( $response['success'] )->toBeFalse();
				expect( $response['error'] )->toBe( 'HMAC secret not configured' );
			}
		);

		it(
			'makes GET request with signature header',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

				$capturedArgs = null;
				Functions\when( 'wp_remote_request' )->alias(
					function ( $url, $args ) use ( &$capturedArgs ) {
						$capturedArgs = $args;
						return [
							'response' => [ 'code' => 200 ],
							'body'     => '{"data":"test"}',
						];
					}
				);

				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":"test"}' );

				$this->client->get( 'sites/123' );

				expect( $capturedArgs['method'] )->toBe( 'GET' );
				expect( $capturedArgs['headers'] )->toHaveKey( 'X-Signature' );
				expect( $capturedArgs['headers']['X-Signature'] )->toMatch( '/^t=\d+,v1=[a-f0-9]{64}$/' );
				expect( $capturedArgs['headers']['Content-Type'] )->toBe( 'application/json' );
			}
		);

		it(
			'makes POST request with body and signature',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

				$capturedArgs = null;
				Functions\when( 'wp_remote_request' )->alias(
					function ( $url, $args ) use ( &$capturedArgs ) {
						$capturedArgs = $args;
						return [
							'response' => [ 'code' => 200 ],
							'body'     => '{"success":true}',
						];
					}
				);

				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

				$this->client->post( 'sites/123/sync', [ 'action' => 'full' ] );

				expect( $capturedArgs['method'] )->toBe( 'POST' );
				expect( $capturedArgs['body'] )->toBe( '{"action":"full"}' );
				expect( $capturedArgs['headers'] )->toHaveKey( 'X-Signature' );
			}
		);

		it(
			'makes PUT request',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

				$capturedArgs = null;
				Functions\when( 'wp_remote_request' )->alias(
					function ( $url, $args ) use ( &$capturedArgs ) {
						$capturedArgs = $args;
						return [
							'response' => [ 'code' => 200 ],
							'body'     => '{}',
						];
					}
				);

				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

				$this->client->put( 'sites/123', [ 'name' => 'updated' ] );

				expect( $capturedArgs['method'] )->toBe( 'PUT' );
			}
		);

		it(
			'makes DELETE request',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

				$capturedArgs = null;
				Functions\when( 'wp_remote_request' )->alias(
					function ( $url, $args ) use ( &$capturedArgs ) {
						$capturedArgs = $args;
						return [
							'response' => [ 'code' => 200 ],
							'body'     => '{}',
						];
					}
				);

				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

				$this->client->delete( 'sites/123' );

				expect( $capturedArgs['method'] )->toBe( 'DELETE' );
			}
		);
	}
);

describe(
	'handleResponse',
	function () {
		it(
			'returns success for 2xx responses',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
				Functions\when( 'wp_remote_request' )->justReturn( [] );
				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"data":"test"}' );

				$response = $this->client->get( 'sites/123' );

				expect( $response['success'] )->toBeTrue();
				expect( $response['data'] )->toBe( [ 'data' => 'test' ] );
				expect( $response['status_code'] )->toBe( 200 );
			}
		);

		it(
			'returns success for 201 created response',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
				Functions\when( 'wp_remote_request' )->justReturn( [] );
				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 201 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"id":456}' );

				$response = $this->client->post( 'sites', [ 'name' => 'new-site' ] );

				expect( $response['success'] )->toBeTrue();
				expect( $response['status_code'] )->toBe( 201 );
			}
		);

		it(
			'returns error for WP_Error response',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

				$wpError = Mockery::mock( 'WP_Error' );
				$wpError->shouldReceive( 'get_error_message' )->andReturn( 'Connection timeout' );

				Functions\when( 'wp_remote_request' )->justReturn( $wpError );
				Functions\when( 'is_wp_error' )->justReturn( true );

				$response = $this->client->get( 'sites/123' );

				expect( $response['success'] )->toBeFalse();
				expect( $response['error'] )->toBe( 'Connection timeout' );
			}
		);

		it(
			'returns error for 4xx responses with message',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
				Functions\when( 'wp_remote_request' )->justReturn( [] );
				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"message":"Signature verification failed"}' );

				$response = $this->client->get( 'sites/123' );

				expect( $response['success'] )->toBeFalse();
				expect( $response['error'] )->toBe( 'Signature verification failed' );
				expect( $response['status_code'] )->toBe( 403 );
			}
		);

		it(
			'returns error for 4xx responses with error field',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
				Functions\when( 'wp_remote_request' )->justReturn( [] );
				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"Site not found"}' );

				$response = $this->client->get( 'sites/999' );

				expect( $response['success'] )->toBeFalse();
				expect( $response['error'] )->toBe( 'Site not found' );
			}
		);

		it(
			'returns generic error for responses without message',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );
				Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
				Functions\when( 'wp_remote_request' )->justReturn( [] );
				Functions\when( 'is_wp_error' )->justReturn( false );
				Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
				Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

				$response = $this->client->get( 'sites/123' );

				expect( $response['success'] )->toBeFalse();
				expect( $response['error'] )->toBe( 'Request failed' );
				expect( $response['status_code'] )->toBe( 500 );
			}
		);
	}
);

describe(
	'HMAC secret caching',
	function () {
		it(
			'caches HMAC secret after first retrieval',
			function () {
				$callCount = 0;
				Functions\when( 'get_option' )->alias(
					function () use ( &$callCount ) {
						++$callCount;
						return 'test-secret';
					}
				);

				// Call isConfigured twice.
				$this->client->isConfigured();
				$this->client->isConfigured();

				// get_option should only be called once due to caching.
				expect( $callCount )->toBe( 1 );
			}
		);
	}
);
