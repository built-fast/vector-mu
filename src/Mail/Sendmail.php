<?php

declare(strict_types=1);

namespace VectorMU\Mail;

use RuntimeException;
use VectorMU\Support\OpenBasedir;

// phpcs:disable WordPress.WP.AlternativeFunctions -- these are a child process's pipes, not files.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages go into a WP_Error, never printed.

/**
 * Pipes a raw message to a sendmail-compatible binary.
 *
 * The binary runs from an argv array through proc_open, so no shell is
 * involved, with the message on stdin. Delivery succeeds only when the
 * binary reads the whole message and exits 0. Its stdout and stderr are
 * read as it runs, so it can't block on a full pipe, and the first few KB
 * of them go into the error. A binary that runs past the timeout is killed.
 */
final class Sendmail {

	/** Bytes written to stdin per call. */
	private const CHUNK_BYTES = 65536;

	/** Output kept for an error message. */
	private const MAX_OUTPUT_BYTES = 4096;

	/**
	 * @param string $path    Absolute path of the binary.
	 * @param float  $timeout Seconds to wait for it to finish.
	 */
	public function __construct(
		private readonly string $path,
		private readonly float $timeout = 30.0
	) {
	}

	/**
	 * Sends the message.
	 *
	 * @throws RuntimeException When the binary can't be run, doesn't take
	 *                          the whole message, times out, or exits
	 *                          non-zero.
	 */
	public function send( string $message ): void {
		if ( ! function_exists( 'proc_open' ) ) {
			throw new RuntimeException( 'proc_open is disabled' );
		}

		if ( OpenBasedir::allows( $this->path ) && ! is_executable( $this->path ) ) {
			throw new RuntimeException( sprintf( '%s is missing or not executable', $this->path ) );
		}

		$pipes = [];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- an argv array, no shell.
		$process = proc_open(
			[ $this->path, '-t', '-i' ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( sprintf( 'could not run %s', $this->path ) );
		}

		foreach ( $pipes as $pipe ) {
			stream_set_blocking( $pipe, false );
		}

		try {
			[ $written, $output ] = $this->exchange( $pipes, $message );
		} catch ( RuntimeException $e ) {
			proc_terminate( $process, 9 );
			self::closeAll( $pipes );
			proc_close( $process );

			throw $e;
		}

		self::closeAll( $pipes );
		$status = proc_close( $process );

		if ( 0 !== $status ) {
			throw new RuntimeException( self::failure( sprintf( '%s exited %d', $this->path, $status ), $output ) );
		}

		if ( strlen( $message ) !== $written ) {
			throw new RuntimeException( self::failure( sprintf( '%s exited before reading the whole message', $this->path ), $output ) );
		}
	}

	/**
	 * Writes the message to stdin while draining stdout and stderr, until
	 * both reach EOF.
	 *
	 * @param array<int, resource> $pipes The process's stdin, stdout, and stderr.
	 * @return array{int, string} Bytes written, and the output kept.
	 * @throws RuntimeException On a timeout or a failed select.
	 */
	private function exchange( array $pipes, string $message ): array {
		$deadline = microtime( true ) + $this->timeout;
		$length   = strlen( $message );
		$written  = 0;
		$output   = '';
		$stdin    = $pipes[0];
		$open     = [ $pipes[1], $pipes[2] ];

		if ( 0 === $length ) {
			fclose( $stdin );
			$stdin = null;
		}

		while ( null !== $stdin || [] !== $open ) {
			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0 ) {
				throw new RuntimeException( self::failure( sprintf( '%s timed out', $this->path ), $output ) );
			}

			$read   = $open;
			$write  = null !== $stdin ? [ $stdin ] : [];
			$except = null;

			$ready = stream_select( $read, $write, $except, (int) $remaining, (int) ( fmod( $remaining, 1.0 ) * 1e6 ) );

			if ( false === $ready ) {
				throw new RuntimeException( sprintf( 'waiting for %s failed', $this->path ) );
			}

			if ( null !== $stdin && [] !== $write ) {
				// A binary that exits early closes the pipe, and fwrite() then
				// raises a notice; that's reported as a failure below instead.
				set_error_handler( static fn (): bool => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to this write.

				try {
					$count = fwrite( $stdin, substr( $message, $written, self::CHUNK_BYTES ) );
				} finally {
					restore_error_handler();
				}

				if ( false === $count ) {
					fclose( $stdin );
					$stdin = null;
				} else {
					$written += $count;
				}

				if ( null !== $stdin && $written >= $length ) {
					fclose( $stdin );
					$stdin = null;
				}
			}

			foreach ( $read as $stream ) {
				$chunk = fread( $stream, 8192 );

				if ( false === $chunk || ( '' === $chunk && feof( $stream ) ) ) {
					$open = array_values( array_filter( $open, static fn ( $s ): bool => $s !== $stream ) );
					fclose( $stream );
					continue;
				}

				if ( strlen( $output ) < self::MAX_OUTPUT_BYTES ) {
					$output .= substr( $chunk, 0, self::MAX_OUTPUT_BYTES - strlen( $output ) );
				}
			}
		}

		return [ $written, $output ];
	}

	/**
	 * Closes whichever pipes are still open.
	 *
	 * @param array<int, resource> $pipes
	 */
	private static function closeAll( array $pipes ): void {
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
	}

	private static function failure( string $reason, string $output ): string {
		$output = trim( $output );

		return '' === $output ? $reason : $reason . ': ' . $output;
	}
}
