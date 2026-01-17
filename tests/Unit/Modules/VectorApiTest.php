<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\VectorApi;
use VectorMU\Contracts\ModuleInterface;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Stub for WP_REST_Response.
	 */
	class WP_REST_Response {
		private mixed $data;
		private int $status;

		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Stub for WP_REST_Request.
	 */
	class WP_REST_Request {
		public function get_header( string $header ): ?string {
			return null;
		}

		public function get_body(): string {
			return '';
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub for WP_Error.
	 */
	class WP_Error {
		private string $code;
		private string $message;
		/** @var array<string, mixed> */
		private array $data;

		/**
		 * @param array<string, mixed> $data
		 */
		public function __construct( string $code = '', string $message = '', array $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

// phpcs:enable

beforeEach(
	function () {
		$this->vectorApi = new VectorApi();
	}
);

describe(
	'VectorApi',
	function () {
		it(
			'can be instantiated',
			function () {
				expect( $this->vectorApi )->toBeInstanceOf( VectorApi::class );
			}
		);

		it(
			'implements ModuleInterface',
			function () {
				expect( $this->vectorApi )->toBeInstanceOf( ModuleInterface::class );
			}
		);
	}
);

describe(
	'register',
	function () {
		it(
			'hooks into rest_api_init',
			function () {
				$hooked = false;
				Functions\when( 'add_action' )->alias(
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					function ( $hook, $callback ) use ( &$hooked ) {
						if ( 'rest_api_init' === $hook ) {
							$hooked = true;
						}
					}
				);

				$this->vectorApi->register();

				expect( $hooked )->toBeTrue();
			}
		);
	}
);

describe(
	'handlePing',
	function () {
		it(
			'returns pong with 200 status',
			function () {
				$response = $this->vectorApi->handlePing();

				expect( $response )->toBeInstanceOf( WP_REST_Response::class );
				expect( $response->get_data() )->toBe( 'pong' );
				expect( $response->get_status() )->toBe( 200 );
			}
		);
	}
);

describe(
	'verifySignature',
	function () {
		it(
			'returns error when HMAC secret not configured',
			function () {
				Functions\when( 'get_option' )->justReturn( false );

				$request = Mockery::mock( WP_REST_Request::class );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_not_configured' );
			}
		);

		it(
			'returns error when signature header is missing',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( null );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_missing_signature' );
			}
		);

		it(
			'returns error when signature header is empty',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( '' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_missing_signature' );
			}
		);

		it(
			'returns error for malformed signature header',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( 'invalid-format' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_invalid_signature' );
			}
		);

		it(
			'returns error for missing timestamp',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( 'v1=abc123' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_invalid_signature' );
			}
		);

		it(
			'returns error for missing signature',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( 't=12345' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_invalid_signature' );
			}
		);

		it(
			'returns error for non-numeric timestamp',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( 't=abc,v1=def' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_invalid_signature' );
			}
		);

		it(
			'returns error for expired timestamp',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$oldTimestamp = time() - 600; // 10 minutes ago.
				$signature    = hash_hmac( 'sha256', "{$oldTimestamp}.", 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$oldTimestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( '' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_expired_signature' );
			}
		);

		it(
			'returns error for future timestamp',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$futureTimestamp = time() + 300; // 5 minutes in future.
				$signature       = hash_hmac( 'sha256', "{$futureTimestamp}.", 'test-secret' );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$futureTimestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( '' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_future_signature' );
			}
		);

		it(
			'returns error for invalid signature',
			function () {
				Functions\when( 'get_option' )->justReturn( 'test-secret' );

				$timestamp = time();

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$timestamp},v1=invalid-signature" );
				$request->shouldReceive( 'get_body' )->andReturn( '' );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeInstanceOf( WP_Error::class );
				expect( $result->get_error_code() )->toBe( 'vector_signature_mismatch' );
			}
		);

		it(
			'returns true for valid signature with empty body',
			function () {
				$secret    = 'test-secret-key';
				$timestamp = time();
				$payload   = '';
				$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

				Functions\when( 'get_option' )->justReturn( $secret );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$timestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( $payload );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeTrue();
			}
		);

		it(
			'returns true for valid signature with JSON body',
			function () {
				$secret    = 'test-secret-key';
				$timestamp = time();
				$payload   = '{"action":"sync","data":{"key":"value"}}';
				$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

				Functions\when( 'get_option' )->justReturn( $secret );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$timestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( $payload );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeTrue();
			}
		);

		it(
			'accepts timestamp within tolerance window',
			function () {
				$secret    = 'test-secret-key';
				$timestamp = time() - 120; // 2 minutes ago, within 5 minute tolerance.
				$payload   = '';
				$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

				Functions\when( 'get_option' )->justReturn( $secret );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$timestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( $payload );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeTrue();
			}
		);

		it(
			'accepts timestamp slightly in future',
			function () {
				$secret    = 'test-secret-key';
				$timestamp = time() + 30; // 30 seconds in future, within 1 minute tolerance.
				$payload   = '';
				$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload}", $secret );

				Functions\when( 'get_option' )->justReturn( $secret );

				$request = Mockery::mock( WP_REST_Request::class );
				$request->shouldReceive( 'get_header' )->with( 'X-Signature' )->andReturn( "t={$timestamp},v1={$signature}" );
				$request->shouldReceive( 'get_body' )->andReturn( $payload );

				$result = $this->vectorApi->verifySignature( $request );

				expect( $result )->toBeTrue();
			}
		);
	}
);

describe(
	'parseSignatureHeader',
	function () {
		it(
			'parses valid signature header',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 't=1234567890,v1=abc123def456' );

				expect( $result )->toBe( [ 1234567890, 'abc123def456' ] );
			}
		);

		it(
			'handles reversed order',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 'v1=abc123def456,t=1234567890' );

				expect( $result )->toBe( [ 1234567890, 'abc123def456' ] );
			}
		);

		it(
			'ignores extra parts',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 't=1234567890,v1=abc123,extra=ignored' );

				expect( $result )->toBe( [ 1234567890, 'abc123' ] );
			}
		);

		it(
			'returns null for missing timestamp',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 'v1=abc123' );

				expect( $result )->toBeNull();
			}
		);

		it(
			'returns null for missing signature',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 't=1234567890' );

				expect( $result )->toBeNull();
			}
		);

		it(
			'returns null for non-numeric timestamp',
			function () {
				$method = new ReflectionMethod( VectorApi::class, 'parseSignatureHeader' );
				$method->setAccessible( true );

				$result = $method->invoke( $this->vectorApi, 't=notanumber,v1=abc123' );

				expect( $result )->toBeNull();
			}
		);
	}
);
