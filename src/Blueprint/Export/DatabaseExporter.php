<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Export;

use VectorMU\Blueprint\Exception\BlueprintException;
use WP_CLI;

final class DatabaseExporter {

	/**
	 * Export the database to a SQL file.
	 *
	 * @param string        $outputPath    The path to write the SQL file
	 * @param list<string>  $excludeTables Tables to exclude from the export
	 * @throws BlueprintException
	 */
	public function export( string $outputPath, array $excludeTables = [] ): void {
		if ( ! class_exists( WP_CLI::class ) ) {
			throw new BlueprintException( 'WP-CLI is required for database export' );
		}

		$command = $this->buildCommand( $outputPath, $excludeTables );

		WP_CLI::runcommand( $command, [ 'launch' => false ] );
	}

	/**
	 * @param list<string> $excludeTables
	 */
	private function buildCommand( string $outputPath, array $excludeTables ): string {
		$args = [ 'db', 'export', $outputPath ];

		if ( count( $excludeTables ) > 0 ) {
			$args[] = '--exclude_tables=' . implode( ',', $excludeTables );
		}

		return implode( ' ', $args );
	}
}
