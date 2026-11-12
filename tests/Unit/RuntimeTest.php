<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Runtime;

beforeEach( function (): void {
	$this->previousEnv = getenv( Runtime::ENV );
	$this->logFile     = (string) tempnam( sys_get_temp_dir(), 'vector-mu-log' );
	$this->previousLog = (string) ini_set( 'error_log', $this->logFile );

	Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
} );

afterEach( function (): void {
	putenv( false === $this->previousEnv ? Runtime::ENV : Runtime::ENV . '=' . $this->previousEnv );
	ini_set( 'error_log', $this->previousLog );
	unlink( $this->logFile );
} );

it( 'resolves runtime values', function ( string|false $value, string $expected ): void {
	expect( Runtime::resolve( $value ) )->toBe( $expected );
} )->with( [
	'unset'          => [ false, Runtime::LAMBDA ],
	'empty'          => [ '', Runtime::LAMBDA ],
	'lambda'         => [ 'lambda', Runtime::LAMBDA ],
	'dev'            => [ 'dev', Runtime::DEV ],
	'unknown'        => [ 'staging', Runtime::DEV ],
	'wrong case'     => [ 'Lambda', Runtime::DEV ],
	'padded'         => [ ' lambda', Runtime::DEV ],
] );

it( 'knows which values are valid', function (): void {
	expect( Runtime::isKnown( false ) )->toBeTrue()
		->and( Runtime::isKnown( '' ) )->toBeTrue()
		->and( Runtime::isKnown( 'lambda' ) )->toBeTrue()
		->and( Runtime::isKnown( 'dev' ) )->toBeTrue()
		->and( Runtime::isKnown( 'DEV' ) )->toBeFalse();
} );

it( 'defaults to lambda when VECTOR_RUNTIME is unset', function (): void {
	putenv( Runtime::ENV );

	expect( Runtime::fromEnvironment() )->toBe( Runtime::LAMBDA )
		->and( (string) file_get_contents( $this->logFile ) )->toBe( '' );
} );

it( 'reads dev from VECTOR_RUNTIME', function (): void {
	putenv( Runtime::ENV . '=dev' );

	expect( Runtime::fromEnvironment() )->toBe( Runtime::DEV )
		->and( (string) file_get_contents( $this->logFile ) )->toBe( '' );
} );

it( 'logs an unknown VECTOR_RUNTIME and falls back to dev', function (): void {
	putenv( Runtime::ENV . '=prod' );

	expect( Runtime::fromEnvironment() )->toBe( Runtime::DEV )
		->and( (string) file_get_contents( $this->logFile ) )->toContain( 'unknown VECTOR_RUNTIME value "prod", using "dev"' );
} );
