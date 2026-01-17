<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Import;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VectorMU\Blueprint\Exception\BlueprintException;

final class ContentImporter {

	/**
	 * Import plugins from the extracted archive.
	 *
	 * @param string $extractedPath The path to the extracted archive
	 * @throws BlueprintException
	 */
	public function importPlugins( string $extractedPath ): void {
		$source = $extractedPath . '/wp-content/plugins';

		if ( ! is_dir( $source ) ) {
			return;
		}

		$destination = $this->getWpContentDir() . '/plugins';

		$this->copyDirectory( $source, $destination );
	}

	/**
	 * Import themes from the extracted archive.
	 *
	 * @param string $extractedPath The path to the extracted archive
	 * @throws BlueprintException
	 */
	public function importThemes( string $extractedPath ): void {
		$source = $extractedPath . '/wp-content/themes';

		if ( ! is_dir( $source ) ) {
			return;
		}

		$destination = $this->getWpContentDir() . '/themes';

		$this->copyDirectory( $source, $destination );
	}

	/**
	 * Import uploads from the extracted archive.
	 *
	 * @param string $extractedPath The path to the extracted archive
	 * @throws BlueprintException
	 */
	public function importUploads( string $extractedPath ): void {
		$source = $extractedPath . '/wp-content/uploads';

		if ( ! is_dir( $source ) ) {
			return;
		}

		$destination = $this->getWpContentDir() . '/uploads';

		$this->copyDirectory( $source, $destination );
	}

	private function getWpContentDir(): string {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return WP_CONTENT_DIR;
		}

		throw new BlueprintException( 'WP_CONTENT_DIR is not defined' );
	}

	private function copyDirectory( string $source, string $destination ): void {
		if ( ! is_dir( $destination ) ) {
			mkdir( $destination, 0755, true );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$source,
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			$targetPath = $destination . '/' . $iterator->getSubPathname();

			if ( $file->isDir() ) {
				if ( ! is_dir( $targetPath ) ) {
					mkdir( $targetPath, 0755, true );
				}
			} else {
				$targetDir = dirname( $targetPath );

				if ( ! is_dir( $targetDir ) ) {
					mkdir( $targetDir, 0755, true );
				}

				copy( $file->getPathname(), $targetPath );
			}
		}
	}
}
