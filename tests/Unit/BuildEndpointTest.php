<?php

declare(strict_types=1);

use VectorMU\Modules\BuildEndpoint;
use VectorMU\Runtime;

const BUILD_ID = '01JD3V7Q8M2X4Y6Z9ABCDEFGHK';

const BUILD_HEADERS = [
	'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
	'Content-Type'  => 'application/json',
];

beforeEach( function (): void {
	$this->dir         = sys_get_temp_dir() . '/vector-mu-build-' . bin2hex( random_bytes( 6 ) );
	$this->file        = $this->dir . '/vector-build.php';
	$this->logFile     = (string) tempnam( sys_get_temp_dir(), 'vector-mu-log' );
	$this->previousLog = (string) ini_set( 'error_log', $this->logFile );
	$this->server      = $_SERVER;
	$this->emitted     = null;
	$this->emit        = function ( int $status, array $headers, string $body ): void {
		$this->emitted = [ 'status' => $status, 'headers' => $headers, 'body' => $body ];
	};
	$this->log         = fn (): string => (string) file_get_contents( $this->logFile );
	mkdir( $this->dir, 0700 );
} );

afterEach( function (): void {
	$_SERVER = $this->server;
	ini_set( 'error_log', $this->previousLog );
	unlink( $this->logFile );

	if ( is_dir( $this->file ) ) {
		rmdir( $this->file );
	} elseif ( file_exists( $this->file ) ) {
		chmod( $this->file, 0600 );
		unlink( $this->file );
	}

	rmdir( $this->dir );
} );

it( 'answers GET with the build ID', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' ) )->toBe( [
		'status'  => 200,
		'headers' => BUILD_HEADERS,
		'body'    => '{"build_id":"' . BUILD_ID . '"}',
	] )->and( ( $this->log )() )->toBe( '' );
} );

it( 'answers HEAD without a body', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );

	$endpoint = new BuildEndpoint( Runtime::LAMBDA, $this->file );

	expect( $endpoint->respond( 'HEAD', '/__vector/build' ) )->toBe( [ 'status' => 200, 'headers' => BUILD_HEADERS, 'body' => '' ] );

	unlink( $this->file );

	expect( $endpoint->respond( 'HEAD', '/__vector/build' ) )->toBe( [ 'status' => 404, 'headers' => BUILD_HEADERS, 'body' => '' ] );
} );

it( 'ignores the query string', function ( string $uri ): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', $uri )['status'] ?? null )->toBe( 200 );
} )->with( [
	'cache-busting query' => '/__vector/build?vector_check=3f9a2c7e',
	'empty query'         => '/__vector/build?',
	'query with a path'   => '/__vector/build?x=/__vector/other',
] );

it( 'answers 404 when the build file is missing', function (): void {
	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' ) )->toBe( [
		'status'  => 404,
		'headers' => BUILD_HEADERS,
		'body'    => '{"build_id":null}',
	] )->and( ( $this->log )() )->toBe( '' );
} );

it( 'answers 404 when the build path is a directory', function (): void {
	mkdir( $this->file );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' )['status'] ?? null )->toBe( 404 );
} );

it( 'answers 404 and logs when the build file is unreadable', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );
	chmod( $this->file, 0000 );

	if ( is_readable( $this->file ) ) {
		$this->markTestSkipped( 'running as root' );
	}

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' )['status'] ?? null )->toBe( 404 )
		->and( ( $this->log )() )->toContain( 'Vector health check could not read vector-build.php' );
} );

it( 'answers 404 and logs for an invalid build file', function ( string $contents ): void {
	file_put_contents( $this->file, $contents );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' ) )->toBe( [
		'status'  => 404,
		'headers' => BUILD_HEADERS,
		'body'    => '{"build_id":null}',
	] )->and( ( $this->log )() )->toContain( 'Vector health check ignored vector-build.php: it did not return a valid build ID' );
} )->with( [
	'not an array'           => "<?php return '" . BUILD_ID . "';",
	'no build_id'            => "<?php return ['id' => '" . BUILD_ID . "'];",
	'build_id not a string'  => "<?php return ['build_id' => 1234];",
	'lowercase build_id'     => "<?php return ['build_id' => '" . strtolower( BUILD_ID ) . "'];",
	'excluded letter'        => "<?php return ['build_id' => '01JD3V7Q8M2X4Y6Z9ABCDEFGHU'];",
	'too short'              => "<?php return ['build_id' => '" . substr( BUILD_ID, 1 ) . "'];",
	'trailing newline'       => "<?php return ['build_id' => \"" . BUILD_ID . '\n"];',
	'no return'              => '<?php',
	'empty file'             => '',
] );

it( 'answers 404 and logs without the contents when the build file fails to load', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => 'secret-content'" );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' )['status'] ?? null )->toBe( 404 )
		->and( ( $this->log )() )->toContain( 'Vector health check could not load vector-build.php' )
		->and( ( $this->log )() )->not->toContain( 'secret-content' );
} );

it( 'discards output from the build file', function (): void {
	file_put_contents( $this->file, "stray<?php echo 'more'; return ['build_id' => '" . BUILD_ID . "'];" );

	$this->expectOutputString( '' );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', '/__vector/build' )['body'] ?? null )
		->toBe( '{"build_id":"' . BUILD_ID . '"}' );
} );

it( 'answers 405 for other methods', function ( string|null $method ): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( $method, '/__vector/build' ) )->toBe( [
		'status'  => 405,
		'headers' => BUILD_HEADERS + [ 'Allow' => 'GET, HEAD' ],
		'body'    => '{"build_id":null}',
	] );
} )->with( [ 'POST', 'PUT', 'DELETE', 'OPTIONS', 'get', null ] );

it( 'leaves other paths alone', function ( string|null $uri ): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );

	expect( ( new BuildEndpoint( Runtime::LAMBDA, $this->file ) )->respond( 'GET', $uri ) )->toBeNull();
} )->with( [
	'trailing slash'      => '/__vector/build/',
	'subpath'             => '/__vector/build/x',
	'prefix'              => '/__vector/builds',
	'different case'      => '/__vector/Build',
	'encoded'             => '/__vector/%62uild',
	'subdirectory'        => '/blog/__vector/build',
	'query only'          => '/?p=/__vector/build',
	'double slash'        => '//__vector/build',
	'missing request uri' => null,
] );

it( 'emits the response when registered in the lambda runtime', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/__vector/build?vector_check=1';

	( new BuildEndpoint( Runtime::LAMBDA, $this->file, $this->emit ) )->register();

	expect( $this->emitted )->toBe( [ 'status' => 200, 'headers' => BUILD_HEADERS, 'body' => '{"build_id":"' . BUILD_ID . '"}' ] );
} );

it( 'leaves the request to WordPress in the dev runtime', function (): void {
	file_put_contents( $this->file, "<?php return ['build_id' => '" . BUILD_ID . "'];" );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/__vector/build';

	( new BuildEndpoint( Runtime::DEV, $this->file, $this->emit ) )->register();

	expect( $this->emitted )->toBeNull();
} );

it( 'leaves other requests to WordPress in the lambda runtime', function (): void {
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI']    = '/hello-world/';

	( new BuildEndpoint( Runtime::LAMBDA, $this->file, $this->emit ) )->register();

	expect( $this->emitted )->toBeNull();
} );
