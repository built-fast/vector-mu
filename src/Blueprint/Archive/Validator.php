<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Archive;

use PharData;
use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Blueprint\Metadata;

final class Validator {

	private const REQUIRED_FILES = [
		'metadata.json',
	];

	/**
	 * Validate an extracted blueprint directory.
	 *
	 * @param string $extractedPath Path to the extracted archive
	 * @return Metadata The validated metadata
	 * @throws BlueprintException
	 */
	public function validate( string $extractedPath ): Metadata {
		if ( ! is_dir( $extractedPath ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new BlueprintException( "Extracted path is not a directory: {$extractedPath}" );
		}

		$this->validateRequiredFiles( $extractedPath );

		$metadata = $this->loadMetadata( $extractedPath );

		$this->validateVersion( $metadata );
		$this->validateContents( $extractedPath, $metadata );

		return $metadata;
	}

	/**
	 * Validate archive structure before extraction.
	 *
	 * @param string $archivePath Path to the .tar.gz file
	 * @throws BlueprintException
	 */
	public function validateArchive( string $archivePath ): void {
		if ( ! file_exists( $archivePath ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new BlueprintException( "Archive does not exist: {$archivePath}" );
		}

		$phar = new PharData( $archivePath );

		$hasMetadata = false;

		foreach ( $phar as $file ) {
			if ( 'metadata.json' === $file->getFilename() ) {
				$hasMetadata = true;
				break;
			}
		}

		if ( ! $hasMetadata ) {
			throw new BlueprintException( 'Archive does not contain metadata.json' );
		}
	}

	private function validateRequiredFiles( string $extractedPath ): void {
		foreach ( self::REQUIRED_FILES as $file ) {
			if ( ! file_exists( $extractedPath . '/' . $file ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new BlueprintException( "Missing required file: {$file}" );
			}
		}
	}

	private function loadMetadata( string $extractedPath ): Metadata {
		$metadataPath = $extractedPath . '/metadata.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $metadataPath );

		if ( false === $contents ) {
			throw new BlueprintException( 'Failed to read metadata.json' );
		}

		return Metadata::fromJson( $contents );
	}

	private function validateVersion( Metadata $metadata ): void {
		if ( version_compare( $metadata->version, Metadata::VERSION, '>' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new BlueprintException(
				"Blueprint version {$metadata->version} is newer than supported version " . Metadata::VERSION
			);
		}
	}

	private function validateContents( string $extractedPath, Metadata $metadata ): void {
		if ( $metadata->hasDatabase ) {
			$databasePath = $extractedPath . '/database.sql';

			if ( ! file_exists( $databasePath ) ) {
				throw new BlueprintException( 'Metadata indicates database included, but database.sql not found' );
			}
		}

		if ( $metadata->hasUploads ) {
			$uploadsPath = $extractedPath . '/wp-content/uploads';

			if ( ! is_dir( $uploadsPath ) ) {
				throw new BlueprintException( 'Metadata indicates uploads included, but wp-content/uploads not found' );
			}
		}
	}
}
