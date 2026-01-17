<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Import;

use VectorMU\Blueprint\Archive\Extractor;
use VectorMU\Blueprint\Archive\Validator;
use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Blueprint\Metadata;
use WP_CLI;

final class Importer {

	public function __construct(
		private readonly Extractor $extractor,
		private readonly Validator $validator,
		private readonly DatabaseImporter $databaseImporter,
		private readonly ContentImporter $contentImporter,
	) {
	}

	public static function create(): self {
		return new self(
			new Extractor(),
			new Validator(),
			new DatabaseImporter(),
			new ContentImporter(),
		);
	}

	/**
	 * Import a WordPress site from a blueprint archive.
	 *
	 * @param ImportOptions $options Import options
	 * @return Metadata The imported metadata
	 * @throws BlueprintException
	 */
	public function import( ImportOptions $options ): Metadata {
		$this->validator->validateArchive( $options->archivePath );

		$extractDir = $this->createExtractDirectory();

		try {
			$this->extractor->extract( $options->archivePath, $extractDir );

			$metadata = $this->validator->validate( $extractDir );

			if ( $options->dryRun ) {
				$this->logDryRun( $metadata, $options );

				return $metadata;
			}

			if ( ! $options->skipDatabase && $metadata->hasDatabase ) {
				$this->importDatabase( $extractDir, $metadata, $options );
			}

			if ( ! $options->skipContent ) {
				$this->importContent( $extractDir, $metadata, $options->wipeContent );
			}

			$this->flushCaches();

			return $metadata;
		} finally {
			$this->cleanup( $extractDir );
		}
	}

	/**
	 * Validate an archive without importing.
	 *
	 * @param string $archivePath Path to the archive
	 * @return Metadata The validated metadata
	 * @throws BlueprintException
	 */
	public function validate( string $archivePath ): Metadata {
		$this->validator->validateArchive( $archivePath );

		$extractDir = $this->createExtractDirectory();

		try {
			$this->extractor->extract( $archivePath, $extractDir );

			return $this->validator->validate( $extractDir );
		} finally {
			$this->cleanup( $extractDir );
		}
	}

	private function createExtractDirectory(): string {
		$dir = sys_get_temp_dir() . '/vector-blueprint-import-' . uniqid();

		if ( ! mkdir( $dir, 0755, true ) ) {
			throw new BlueprintException( 'Failed to create extract directory' );
		}

		return $dir;
	}

	private function importDatabase( string $extractDir, Metadata $metadata, ImportOptions $options ): void {
		$sqlPath = $extractDir . '/database.sql';

		$this->databaseImporter->import( $sqlPath );

		if ( ! $options->skipSearchReplace ) {
			$this->databaseImporter->searchReplace(
				$metadata->sourceUrl,
				$options->targetUrl,
				$options->dbPrefix,
				$options->dryRun
			);
		}
	}

	private function importContent( string $extractDir, Metadata $metadata, bool $wipe ): void {
		if ( $metadata->hasPlugins ) {
			if ( $wipe ) {
				$this->contentImporter->wipePlugins();
			}
			$this->contentImporter->importPlugins( $extractDir );
		}

		if ( $metadata->hasThemes ) {
			if ( $wipe ) {
				$this->contentImporter->wipeThemes();
			}
			$this->contentImporter->importThemes( $extractDir );
		}

		if ( $metadata->hasUploads ) {
			if ( $wipe ) {
				$this->contentImporter->wipeUploads();
			}
			$this->contentImporter->importUploads( $extractDir );
		}
	}

	private function flushCaches(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::runcommand( 'cache flush', [ 'launch' => false ] );
			WP_CLI::runcommand( 'rewrite flush', [ 'launch' => false ] );
		}
	}

	private function logDryRun( Metadata $metadata, ImportOptions $options ): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Dry run - no changes will be made.' );
		WP_CLI::log( '' );
		WP_CLI::log( 'Blueprint Information:' );
		WP_CLI::log( "  Version: {$metadata->version}" );
		WP_CLI::log( "  Created: {$metadata->createdAt->format('Y-m-d H:i:s')}" );
		WP_CLI::log( "  Source URL: {$metadata->sourceUrl}" );
		WP_CLI::log( "  Target URL: {$options->targetUrl}" );
		WP_CLI::log( '' );
		WP_CLI::log( 'Contents:' );
		WP_CLI::log( '  Database: ' . ( $metadata->hasDatabase ? 'Yes' : 'No' ) );
		WP_CLI::log( '  Plugins: ' . ( $metadata->hasPlugins ? 'Yes' : 'No' ) );
		WP_CLI::log( '  Themes: ' . ( $metadata->hasThemes ? 'Yes' : 'No' ) );
		WP_CLI::log( '  Uploads: ' . ( $metadata->hasUploads ? 'Yes' : 'No' ) );
	}

	private function cleanup( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$this->removeDirectory( $directory );
	}

	private function removeDirectory( string $directory ): void {
		$files = scandir( $directory );

		if ( false === $files ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$path = $directory . '/' . $file;

			if ( is_dir( $path ) ) {
				$this->removeDirectory( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $directory );
	}
}
