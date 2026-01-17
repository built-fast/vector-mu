<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Export;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VectorMU\Blueprint\Exception\BlueprintException;

final class ContentExporter {

	/**
	 * Copy plugins to the staging directory.
	 *
	 * @param string        $stagingDir The staging directory
	 * @param list<string>  $plugins    Plugin directory names to include
	 * @throws BlueprintException
	 */
	public function exportPlugins( string $stagingDir, array $plugins ): void {
		$pluginsDir = $this->getWpContentDir() . '/plugins';
		$targetDir  = $stagingDir . '/wp-content/plugins';

		foreach ( $plugins as $plugin ) {
			$source = $pluginsDir . '/' . $plugin;

			if ( ! is_dir( $source ) ) {
				throw new BlueprintException( "Plugin directory not found: {$plugin}" );
			}

			$this->copyDirectory( $source, $targetDir . '/' . $plugin );
		}
	}

	/**
	 * Copy themes to the staging directory.
	 *
	 * @param string        $stagingDir The staging directory
	 * @param list<string>  $themes     Theme directory names to include
	 * @throws BlueprintException
	 */
	public function exportThemes( string $stagingDir, array $themes ): void {
		$themesDir = $this->getWpContentDir() . '/themes';
		$targetDir = $stagingDir . '/wp-content/themes';

		foreach ( $themes as $theme ) {
			$source = $themesDir . '/' . $theme;

			if ( ! is_dir( $source ) ) {
				throw new BlueprintException( "Theme directory not found: {$theme}" );
			}

			$this->copyDirectory( $source, $targetDir . '/' . $theme );
		}
	}

	/**
	 * Copy uploads to the staging directory.
	 *
	 * @param string   $stagingDir    The staging directory
	 * @param int|null $maxSize       Maximum size in bytes (null for no limit)
	 * @throws BlueprintException
	 */
	public function exportUploads( string $stagingDir, ?int $maxSize = null ): void {
		$uploadsDir = $this->getWpContentDir() . '/uploads';

		if ( ! is_dir( $uploadsDir ) ) {
			return;
		}

		if ( null !== $maxSize ) {
			$currentSize = $this->calculateDirectorySize( $uploadsDir );

			if ( $currentSize > $maxSize ) {
				throw new BlueprintException(
					"Uploads directory size ({$this->formatSize($currentSize)}) exceeds maximum ({$this->formatSize($maxSize)})"
				);
			}
		}

		$this->copyDirectory( $uploadsDir, $stagingDir . '/wp-content/uploads' );
	}

	/**
	 * Calculate the size of the uploads directory.
	 */
	public function getUploadsSize(): int {
		$uploadsDir = $this->getWpContentDir() . '/uploads';

		if ( ! is_dir( $uploadsDir ) ) {
			return 0;
		}

		return $this->calculateDirectorySize( $uploadsDir );
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

	private function calculateDirectorySize( string $directory ): int {
		$size = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$directory,
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
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$i     = 0;
		$size  = (float) $bytes;

		while ( $size >= 1024 && $i < 3 ) {
			$size /= 1024;
			++$i;
		}

		return round( $size, 2 ) . ' ' . $units[ $i ];
	}
}
