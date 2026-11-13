<?php

declare(strict_types=1);

use VectorMU\Mail\Sendmail;

beforeEach( function (): void {
	$this->dir = sys_get_temp_dir() . '/vector-mu-sendmail-' . bin2hex( random_bytes( 6 ) );
	mkdir( $this->dir, 0700 );

	$this->script = function ( string $body ): string {
		$path = $this->dir . '/sendmail-' . bin2hex( random_bytes( 4 ) );
		file_put_contents( $path, "#!/bin/sh\n" . $body . "\n" );
		chmod( $path, 0700 );

		return $path;
	};
} );

afterEach( function (): void {
	foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
		unlink( (string) $file );
	}

	rmdir( $this->dir );
} );

it( 'writes a large message whole while the binary writes output', function (): void {
	// Output both before and after reading stdin, more than a pipe buffer
	// holds, so a writer that doesn't drain stdout and stderr would block.
	$out     = $this->dir . '/out.eml';
	$path    = ( $this->script )( 'head -c 200000 /dev/zero >&2; cat > "' . $out . '"; head -c 200000 /dev/zero' );
	$message = str_repeat( "0123456789abcdef\n", 300000 );

	( new Sendmail( $path ) )->send( $message );

	expect( file_get_contents( $out ) )->toBe( $message );
} );

it( 'fails when the binary exits non-zero, with its output', function (): void {
	$path = ( $this->script )( 'cat >/dev/null; echo "agent refused: too_large" >&2; exit 1' );

	expect( fn () => ( new Sendmail( $path ) )->send( 'message' ) )
		->toThrow( RuntimeException::class, $path . ' exited 1: agent refused: too_large' );
} );

it( 'fails when the binary exits 0 without reading the whole message', function (): void {
	$path = ( $this->script )( 'exit 0' );

	expect( fn () => ( new Sendmail( $path ) )->send( str_repeat( 'x', 4 << 20 ) ) )
		->toThrow( RuntimeException::class, 'exited before reading the whole message' );
} );

it( 'kills a binary that runs past the timeout', function (): void {
	$path    = ( $this->script )( 'exec sleep 30' );
	$started = microtime( true );

	expect( fn () => ( new Sendmail( $path, 0.5 ) )->send( 'message' ) )
		->toThrow( RuntimeException::class, 'timed out' )
		->and( microtime( true ) - $started )->toBeLessThan( 5.0 );
} );

it( 'fails when the binary is missing or not executable', function (): void {
	$path = $this->dir . '/plain';
	file_put_contents( $path, '' );

	expect( fn () => ( new Sendmail( $path ) )->send( 'message' ) )
		->toThrow( RuntimeException::class, 'missing or not executable' )
		->and( fn () => ( new Sendmail( $this->dir . '/missing' ) )->send( 'message' ) )
		->toThrow( RuntimeException::class, 'missing or not executable' );
} );

it( 'never runs the path through a shell', function (): void {
	$marker = $this->dir . '/pwned';

	expect( fn () => ( new Sendmail( $this->dir . '/x; touch ' . $marker ) )->send( 'message' ) )
		->toThrow( RuntimeException::class )
		->and( file_exists( $marker ) )->toBeFalse();
} );
