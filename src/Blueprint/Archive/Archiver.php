<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Archive;

use FilesystemIterator;
use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use VectorMU\Blueprint\Exception\BlueprintException;

final class Archiver {

	/**
	 * Create a tar.gz archive from a staging directory.
	 *
	 * @param string $stagingDir The directory containing files to archive
	 * @param string $outputPath The output path for the .tar.gz file
	 * @throws BlueprintException
	 */
	public function create( string $stagingDir, string $outputPath ): void {
		if ( ! is_dir( $stagingDir ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new BlueprintException( "Staging directory does not exist: {$stagingDir}" );
		}

		$tarPath = $this->getTarPath( $outputPath );

		$this->cleanupExisting( $tarPath, $outputPath );

		$phar = new PharData( $tarPath );

		$this->addDirectory( $phar, $stagingDir );

		$phar->compress( Phar::GZ );

		$this->cleanupTar( $tarPath );
	}

	/**
	 * Add a single file to the archive.
	 *
	 * @param PharData $phar         The archive
	 * @param string   $filePath     The file path on disk
	 * @param string   $archivePath  The path within the archive
	 */
	public function addFile( PharData $phar, string $filePath, string $archivePath ): void {
		$phar->addFile( $filePath, $archivePath );
	}

	private function addDirectory( PharData $phar, string $directory ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(
				$directory,
				FilesystemIterator::SKIP_DOTS
			),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$baseLength = strlen( $directory ) + 1;

		foreach ( $iterator as $file ) {
			$localPath = substr( $file->getPathname(), $baseLength );

			if ( $file->isDir() ) {
				$phar->addEmptyDir( $localPath );
			} else {
				$phar->addFile( $file->getPathname(), $localPath );
			}
		}
	}

	private function getTarPath( string $outputPath ): string {
		if ( str_ends_with( $outputPath, '.tar.gz' ) ) {
			return substr( $outputPath, 0, -3 );
		}

		return $outputPath . '.tar';
	}

	private function cleanupExisting( string $tarPath, string $outputPath ): void {
		if ( file_exists( $tarPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tarPath );
		}

		if ( file_exists( $outputPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $outputPath );
		}
	}

	private function cleanupTar( string $tarPath ): void {
		if ( file_exists( $tarPath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tarPath );
		}
	}
}
