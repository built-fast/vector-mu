<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class UploadsSize implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector uploads-size', [ $this, 'cliCommand' ] );
		}
	}

	/**
	 * Display the size of the wp-content/uploads directory.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector uploads-size
	 *     wp vector uploads-size --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cliCommand( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$sizeBytes = $this->getUploadsSize();

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'bytes'     => $sizeBytes,
					'formatted' => $this->formatSize( $sizeBytes ),
				]
			);

			WP_CLI::log( false !== $output ? $output : '{}' );

			return;
		}

		WP_CLI::log( 'Uploads directory size: ' . $this->formatSize( $sizeBytes ) );
		WP_CLI::log( 'Bytes: ' . $sizeBytes );
	}

	private function getUploadsSize(): int {
		$uploadsDir = defined( 'WP_CONTENT_DIR' )
			? WP_CONTENT_DIR . '/uploads'
			: ABSPATH . 'wp-content/uploads';

		if ( ! is_dir( $uploadsDir ) ) {
			return 0;
		}

		$size = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$uploadsDir,
				FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	private function formatSize( int $bytes ): string {
		if ( 0 === $bytes ) {
			return '0 B';
		}

		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$i     = 0;
		$size  = (float) $bytes;

		while ( $size >= 1024 && $i < 4 ) {
			$size /= 1024;
			++$i;
		}

		return round( $size, 2 ) . ' ' . $units[ $i ];
	}
}
