<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Archive;

use PharData;
use VectorMU\Blueprint\Exception\BlueprintException;

final class Extractor {

	/**
	 * Extract a tar.gz archive to a destination directory.
	 *
	 * @param string $archivePath The path to the .tar.gz file
	 * @param string $destination The destination directory
	 * @throws BlueprintException
	 */
	public function extract( string $archivePath, string $destination ): void {
		if ( ! file_exists( $archivePath ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new BlueprintException( "Archive does not exist: {$archivePath}" );
		}

		if ( ! is_dir( $destination ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! mkdir( $destination, 0755, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new BlueprintException( "Failed to create destination directory: {$destination}" );
			}
		}

		$phar = new PharData( $archivePath );

		$this->validatePaths( $phar, $destination );

		$phar->extractTo( $destination, null, true );
	}

	/**
	 * Validate archive paths to prevent path traversal attacks.
	 *
	 * @throws BlueprintException
	 */
	private function validatePaths( PharData $phar, string $destination ): void {
		$realDestination = realpath( $destination );

		if ( false === $realDestination ) {
			throw new BlueprintException( 'Invalid destination directory' );
		}

		foreach ( $phar as $file ) {
			$path = $file->getPathname();

			if ( str_contains( $path, '..' ) ) {
				throw new BlueprintException( 'Archive contains invalid path with directory traversal' );
			}

			if ( str_starts_with( $path, '/' ) ) {
				throw new BlueprintException( 'Archive contains absolute path' );
			}
		}
	}
}
