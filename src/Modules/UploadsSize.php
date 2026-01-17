<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Blueprint\Export\ContentExporter;
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

		$contentExporter = new ContentExporter();
		$sizeBytes       = $contentExporter->getUploadsSize();

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
