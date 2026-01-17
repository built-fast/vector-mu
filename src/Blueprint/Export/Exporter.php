<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Export;

use VectorMU\Blueprint\Archive\Archiver;
use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Blueprint\Metadata;

final class Exporter {

	public function __construct(
		private readonly DatabaseExporter $databaseExporter,
		private readonly ContentExporter $contentExporter,
		private readonly Archiver $archiver,
	) {
	}

	public static function create(): self {
		return new self(
			new DatabaseExporter(),
			new ContentExporter(),
			new Archiver(),
		);
	}

	/**
	 * Export a WordPress site to a blueprint archive.
	 *
	 * @param ExportOptions $options Export options
	 * @return string The path to the created archive
	 * @throws BlueprintException
	 */
	public function export( ExportOptions $options ): string {
		$stagingDir = $this->createStagingDirectory();

		try {
			$this->exportDatabase( $stagingDir, $options );
			$this->exportContent( $stagingDir, $options );
			$this->createMetadata( $stagingDir, $options );
			$this->createArchive( $stagingDir, $options->outputPath );

			return $options->outputPath;
		} finally {
			$this->cleanup( $stagingDir );
		}
	}

	private function createStagingDirectory(): string {
		$dir = sys_get_temp_dir() . '/vector-blueprint-' . uniqid();

		if ( ! mkdir( $dir, 0755, true ) ) {
			throw new BlueprintException( 'Failed to create staging directory' );
		}

		return $dir;
	}

	private function exportDatabase( string $stagingDir, ExportOptions $options ): void {
		$sqlPath = $stagingDir . '/database.sql';
		$this->databaseExporter->export( $sqlPath, $options->excludeTables );
	}

	private function exportContent( string $stagingDir, ExportOptions $options ): void {
		if ( ! $options->skipPlugins ) {
			$this->contentExporter->exportPlugins( $stagingDir );
		}

		if ( ! $options->skipThemes ) {
			$this->contentExporter->exportThemes( $stagingDir );
		}

		if ( $options->includeUploads ) {
			$this->contentExporter->exportUploads( $stagingDir, $options->maxUploadSize );
		}
	}

	private function createMetadata( string $stagingDir, ExportOptions $options ): void {
		$metadata = Metadata::create(
			sourceUrl: $options->sourceUrl,
			dbPrefix: $options->dbPrefix,
			hasDatabase: true,
			hasUploads: $options->includeUploads,
			hasPlugins: ! $options->skipPlugins,
			hasThemes: ! $options->skipThemes,
			installedPlugins: $this->contentExporter->getInstalledPlugins(),
			installedThemes: $this->contentExporter->getInstalledThemes(),
		);

		$metadataPath = $stagingDir . '/metadata.json';

		if ( file_put_contents( $metadataPath, $metadata->toJson() ) === false ) {
			throw new BlueprintException( 'Failed to write metadata.json' );
		}
	}

	private function createArchive( string $stagingDir, string $outputPath ): void {
		$this->archiver->create( $stagingDir, $outputPath );
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
