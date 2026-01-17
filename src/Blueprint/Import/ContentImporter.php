<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Import;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VectorMU\Blueprint\Exception\BlueprintException;

final class ContentImporter {

	/**
	 * Wipe the plugins directory.
	 */
	public function wipePlugins(): void {
		$dir = $this->getWpContentDir() . '/plugins';

		if ( is_dir( $dir ) ) {
			$this->removeDirectoryContents( $dir );
		}
	}

	/**
	 * Wipe the themes directory.
	 */
	public function wipeThemes(): void {
		$dir = $this->getWpContentDir() . '/themes';

		if ( is_dir( $dir ) ) {
			$this->removeDirectoryContents( $dir );
		}
	}

	/**
	 * Wipe the uploads directory.
	 */
	public function wipeUploads(): void {
		$dir = $this->getWpContentDir() . '/uploads';

		if ( is_dir( $dir ) ) {
			$this->removeDirectoryContents( $dir );
		}
	}

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

	/**
	 * Remove all contents of a directory without removing the directory itself.
	 */
	private function removeDirectoryContents( string $directory ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$directory,
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
	}
}
