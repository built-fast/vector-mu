<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Import;

use VectorMU\Blueprint\Exception\BlueprintException;
use WP_CLI;

final class DatabaseImporter {

	private const SEARCH_REPLACE_TABLES = [
		'options',
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
	];

	/**
	 * Import a SQL file into the database.
	 *
	 * @param string $sqlPath The path to the SQL file
	 * @throws BlueprintException
	 */
	public function import( string $sqlPath ): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			throw new BlueprintException( 'WP-CLI is required for database import' );
		}

		if ( ! file_exists( $sqlPath ) ) {
			throw new BlueprintException( "SQL file does not exist: {$sqlPath}" );
		}

		WP_CLI::runcommand( "db import {$sqlPath}", [ 'launch' => false ] );
	}

	/**
	 * Perform search-replace to update URLs from source to target.
	 *
	 * @param string $sourceUrl The original site URL
	 * @param string $targetUrl The new site URL
	 * @param string $dbPrefix  The database table prefix
	 * @param bool   $dryRun    If true, preview changes without applying
	 * @throws BlueprintException
	 */
	public function searchReplace( string $sourceUrl, string $targetUrl, string $dbPrefix, bool $dryRun = false ): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			throw new BlueprintException( 'WP-CLI is required for search-replace' );
		}

		$sourceDomain = $this->extractDomain( $sourceUrl );
		$targetDomain = $this->extractDomain( $targetUrl );

		$sourceEscaped = preg_quote( $sourceDomain, '/' );
		$pattern       = "(https?:)?//(www\\.)?{$sourceEscaped}";
		$replacement   = "\\1//\\2{$targetDomain}";

		$tables = $this->getTables( $dbPrefix );

		$commandArgs = [
			$pattern,
			$replacement,
			implode( ' ', $tables ),
			'--regex',
			'--skip-themes',
			'--skip-plugins',
		];

		if ( $dryRun ) {
			$commandArgs[] = '--dry-run';
		}

		WP_CLI::runcommand(
			'search-replace ' . implode( ' ', $commandArgs ),
			[ 'launch' => false ]
		);
	}

	private function extractDomain( string $url ): string {
		$parsed = parse_url( $url );

		return $parsed['host'] ?? $url;
	}

	/**
	 * @return list<string>
	 */
	private function getTables( string $prefix ): array {
		$tables = [];

		foreach ( self::SEARCH_REPLACE_TABLES as $table ) {
			$tables[] = $prefix . $table;
		}

		return $tables;
	}
}
