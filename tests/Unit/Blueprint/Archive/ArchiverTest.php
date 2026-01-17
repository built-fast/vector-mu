<?php

declare(strict_types=1);

use VectorMU\Blueprint\Archive\Archiver;
use VectorMU\Blueprint\Exception\BlueprintException;

beforeEach( function () {
	$this->archiver = new Archiver();
} );

describe( 'create', function () {
	it( 'throws on non-existent staging directory', function () {
		$outputFile = sys_get_temp_dir() . '/archiver-test-' . uniqid() . '.tar.gz';

		expect( fn() => $this->archiver->create( '/nonexistent/path', $outputFile ) )
			->toThrow( BlueprintException::class, 'Staging directory does not exist' );
	} );
} );

describe( 'getTarPath', function () {
	it( 'strips .gz from .tar.gz paths', function () {
		$method = new ReflectionMethod( Archiver::class, 'getTarPath' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->archiver, '/path/to/archive.tar.gz' );

		expect( $result )->toBe( '/path/to/archive.tar' );
	} );

	it( 'appends .tar to paths without .tar.gz', function () {
		$method = new ReflectionMethod( Archiver::class, 'getTarPath' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->archiver, '/path/to/archive' );

		expect( $result )->toBe( '/path/to/archive.tar' );
	} );

	it( 'appends .tar to paths ending with .gz only', function () {
		$method = new ReflectionMethod( Archiver::class, 'getTarPath' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->archiver, '/path/to/archive.gz' );

		expect( $result )->toBe( '/path/to/archive.gz.tar' );
	} );
} );
