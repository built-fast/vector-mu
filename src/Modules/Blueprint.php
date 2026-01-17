<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Blueprint\Export\Exporter;
use VectorMU\Blueprint\Export\ExportOptions;
use VectorMU\Blueprint\Import\Importer;
use VectorMU\Blueprint\Import\ImportOptions;
use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class Blueprint implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector blueprint export', [ $this, 'exportCommand' ] );
			WP_CLI::add_command( 'vector blueprint import', [ $this, 'importCommand' ] );
			WP_CLI::add_command( 'vector blueprint validate', [ $this, 'validateCommand' ] );
		}
	}

	/**
	 * Export the current site to a blueprint archive.
	 *
	 * ## OPTIONS
	 *
	 * [<output>]
	 * : Output file path. Defaults to blueprint-{sitename}-{date}.tar.gz
	 *
	 * [--include-uploads]
	 * : Include the wp-content/uploads directory in the archive.
	 *
	 * [--max-upload-size=<size>]
	 * : Maximum uploads size (e.g., 100M). Fails if exceeded.
	 *
	 * [--include-plugins=<plugins>]
	 * : Comma-separated list of plugin directories to include.
	 *
	 * [--include-themes=<themes>]
	 * : Comma-separated list of theme directories to include.
	 *
	 * [--exclude-tables=<tables>]
	 * : Comma-separated list of tables to exclude from database export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector blueprint export
	 *     wp vector blueprint export mysite.tar.gz
	 *     wp vector blueprint export --include-uploads --max-upload-size=100M
	 *     wp vector blueprint export --include-plugins=my-plugin,other-plugin
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function exportCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$outputPath     = $args[0] ?? $this->getDefaultOutputPath();
		$includeUploads = (bool) ( $assoc_args['include-uploads'] ?? false );
		$maxUploadSize  = $this->parseSize( $assoc_args['max-upload-size'] ?? null );
		$includePlugins = $this->parseCommaSeparated( $assoc_args['include-plugins'] ?? '' );
		$includeThemes  = $this->parseCommaSeparated( $assoc_args['include-themes'] ?? '' );
		$excludeTables  = $this->parseCommaSeparated( $assoc_args['exclude-tables'] ?? '' );

		if ( ! str_ends_with( $outputPath, '.tar.gz' ) ) {
			$outputPath .= '.tar.gz';
		}

		$options = new ExportOptions(
			outputPath: $outputPath,
			sourceUrl: get_option( 'siteurl' ),
			dbPrefix: $wpdb->prefix,
			includeUploads: $includeUploads,
			maxUploadSize: $maxUploadSize,
			includePlugins: $includePlugins,
			includeThemes: $includeThemes,
			excludeTables: $excludeTables,
		);

		WP_CLI::log( 'Exporting blueprint...' );

		try {
			$exporter = Exporter::create();
			$result   = $exporter->export( $options );

			WP_CLI::success( "Blueprint exported to: {$result}" );
		} catch ( BlueprintException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Import a blueprint archive into the current site.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Path to the blueprint archive (.tar.gz)
	 *
	 * --target-url=<url>
	 * : The target site URL (required)
	 *
	 * [--skip-database]
	 * : Skip importing the database.
	 *
	 * [--skip-content]
	 * : Skip importing wp-content (plugins, themes, uploads).
	 *
	 * [--skip-search-replace]
	 * : Skip URL search-replace in the database.
	 *
	 * [--dry-run]
	 * : Preview what would be imported without making changes.
	 *
	 * [--force]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector blueprint import mysite.tar.gz --target-url=https://newsite.com
	 *     wp vector blueprint import mysite.tar.gz --target-url=https://newsite.com --dry-run
	 *     wp vector blueprint import mysite.tar.gz --target-url=https://newsite.com --skip-database
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function importCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$archivePath = $args[0] ?? '';

		if ( '' === $archivePath ) {
			WP_CLI::error( 'Archive path is required.' );
		}

		$targetUrl = $assoc_args['target-url'] ?? '';

		if ( '' === $targetUrl ) {
			WP_CLI::error( '--target-url is required.' );
		}

		$skipDatabase      = (bool) ( $assoc_args['skip-database'] ?? false );
		$skipContent       = (bool) ( $assoc_args['skip-content'] ?? false );
		$skipSearchReplace = (bool) ( $assoc_args['skip-search-replace'] ?? false );
		$dryRun            = (bool) ( $assoc_args['dry-run'] ?? false );
		$force             = (bool) ( $assoc_args['force'] ?? false );

		if ( ! $dryRun && ! $force ) {
			WP_CLI::confirm( 'This will overwrite the current database. Continue?' );
		}

		$options = new ImportOptions(
			archivePath: $archivePath,
			targetUrl: $targetUrl,
			dbPrefix: $wpdb->prefix,
			skipDatabase: $skipDatabase,
			skipContent: $skipContent,
			skipSearchReplace: $skipSearchReplace,
			dryRun: $dryRun,
		);

		WP_CLI::log( 'Importing blueprint...' );

		try {
			$importer = Importer::create();
			$metadata = $importer->import( $options );

			if ( ! $dryRun ) {
				WP_CLI::success( 'Blueprint imported successfully.' );
				WP_CLI::log( "Source URL: {$metadata->sourceUrl}" );
				WP_CLI::log( "Target URL: {$targetUrl}" );
			}
		} catch ( BlueprintException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Validate a blueprint archive.
	 *
	 * ## OPTIONS
	 *
	 * <archive>
	 * : Path to the blueprint archive (.tar.gz)
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector blueprint validate mysite.tar.gz
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function validateCommand( array $args, array $assoc_args ): void {
		$archivePath = $args[0] ?? '';

		if ( '' === $archivePath ) {
			WP_CLI::error( 'Archive path is required.' );
		}

		WP_CLI::log( 'Validating blueprint...' );

		try {
			$importer = Importer::create();
			$metadata = $importer->validate( $archivePath );

			WP_CLI::success( 'Blueprint is valid.' );
			WP_CLI::log( '' );
			WP_CLI::log( 'Blueprint Information:' );
			WP_CLI::log( "  Version: {$metadata->version}" );
			WP_CLI::log( "  Created: {$metadata->createdAt->format('Y-m-d H:i:s')}" );
			WP_CLI::log( "  Vector MU Version: {$metadata->vectorMuVersion}" );
			WP_CLI::log( '' );
			WP_CLI::log( 'Source Site:' );
			WP_CLI::log( "  URL: {$metadata->sourceUrl}" );
			WP_CLI::log( "  WordPress: {$metadata->wpVersion}" );
			WP_CLI::log( "  PHP: {$metadata->phpVersion}" );
			WP_CLI::log( "  DB Prefix: {$metadata->dbPrefix}" );
			WP_CLI::log( '' );
			WP_CLI::log( 'Contents:' );
			WP_CLI::log( '  Database: ' . ( $metadata->hasDatabase ? 'Yes' : 'No' ) );
			WP_CLI::log( '  Uploads: ' . ( $metadata->hasUploads ? 'Yes' : 'No' ) );

			if ( count( $metadata->plugins ) > 0 ) {
				WP_CLI::log( '  Plugins: ' . implode( ', ', $metadata->plugins ) );
			} else {
				WP_CLI::log( '  Plugins: None' );
			}

			if ( count( $metadata->themes ) > 0 ) {
				WP_CLI::log( '  Themes: ' . implode( ', ', $metadata->themes ) );
			} else {
				WP_CLI::log( '  Themes: None' );
			}
		} catch ( BlueprintException $e ) {
			WP_CLI::error( 'Validation failed: ' . $e->getMessage() );
		}
	}

	private function getDefaultOutputPath(): string {
		$siteName = sanitize_title( get_option( 'blogname', 'site' ) );
		$date     = gmdate( 'Y-m-d' );

		return "blueprint-{$siteName}-{$date}.tar.gz";
	}

	/**
	 * @return list<string>
	 */
	private function parseCommaSeparated( string $value ): array {
		if ( '' === $value ) {
			return [];
		}

		$items = explode( ',', $value );
		$items = array_map( 'trim', $items );

		return array_values( array_filter( $items ) );
	}

	private function parseSize( ?string $size ): ?int {
		if ( null === $size || '' === $size ) {
			return null;
		}

		$size = trim( $size );

		if ( preg_match( '/^(\d+(?:\.\d+)?)\s*(B|K|KB|M|MB|G|GB)?$/i', $size, $matches ) ) {
			$value = (float) $matches[1];
			$unit  = strtoupper( $matches[2] ?? 'B' );

			return (int) match ( $unit ) {
				'K', 'KB' => $value * 1024,
				'M', 'MB' => $value * 1024 * 1024,
				'G', 'GB' => $value * 1024 * 1024 * 1024,
				default   => $value,
			};
		}

		return (int) $size;
	}
}
