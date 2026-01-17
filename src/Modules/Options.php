<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_CLI\Utils;

final class Options implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector options autoload', [ $this, 'autoloadCommand' ] );
			WP_CLI::add_command( 'vector options search', [ $this, 'searchCommand' ] );
			WP_CLI::add_command( 'vector options size', [ $this, 'sizeCommand' ] );
		}
	}

	/**
	 * List autoloaded options sorted by size.
	 *
	 * Shows the largest autoloaded options that may be impacting performance.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of options to show. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector options autoload
	 *     wp vector options autoload --limit=50
	 *     wp vector options autoload --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function autoloadCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = (int) ( $assoc_args['limit'] ?? 20 );
		$format = $assoc_args['format'] ?? 'table';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) as size_bytes
				FROM {$wpdb->options}
				WHERE autoload = 'yes'
				ORDER BY size_bytes DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			WP_CLI::log( 'No autoloaded options found.' );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_size = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'"
		);

		$items = [];
		foreach ( $results as $row ) {
			$items[] = [
				'option_name'    => $row['option_name'],
				'size_bytes'     => (int) $row['size_bytes'],
				'size_formatted' => $this->formatSize( (int) $row['size_bytes'] ),
				'percentage'     => $total_size > 0 ? round( ( (int) $row['size_bytes'] / $total_size ) * 100, 1 ) : 0,
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'total_size_bytes'     => $total_size,
					'total_size_formatted' => $this->formatSize( $total_size ),
					'total_count'          => $total_count,
					'options'              => $items,
				],
				JSON_PRETTY_PRINT
			);
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total autoload size: %s (%d options)', $this->formatSize( $total_size ), $total_count ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $items, [ 'option_name', 'size_formatted', 'percentage' ] );
	}

	/**
	 * Search options by name or value.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : The search term to look for in option names or values.
	 *
	 * [--field=<field>]
	 * : Field to search. Options: name, value, both. Default: both.
	 *
	 * [--limit=<limit>]
	 * : Maximum results to return. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector options search siteurl
	 *     wp vector options search "api_key" --field=name
	 *     wp vector options search "example.com" --field=value
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function searchCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a search term.' );
		}

		$search = $args[0];
		$field  = $assoc_args['field'] ?? 'both';
		$limit  = (int) ( $assoc_args['limit'] ?? 50 );
		$format = $assoc_args['format'] ?? 'table';

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		switch ( $field ) {
			case 'name':
				$where = $wpdb->prepare( 'option_name LIKE %s', $like );
				break;
			case 'value':
				$where = $wpdb->prepare( 'option_value LIKE %s', $like );
				break;
			default:
				$where = $wpdb->prepare( 'option_name LIKE %s OR option_value LIKE %s', $like, $like );
				break;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, autoload, LENGTH(option_value) as size_bytes
				FROM {$wpdb->options}
				WHERE {$where}
				ORDER BY option_name
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $results ) ) {
			WP_CLI::log( 'No options found matching "' . $search . '".' );
			return;
		}

		$items = [];
		foreach ( $results as $row ) {
			$items[] = [
				'option_name'    => $row['option_name'],
				'autoload'       => $row['autoload'],
				'size_formatted' => $this->formatSize( (int) $row['size_bytes'] ),
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $items, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d option(s) matching "%s":', count( $items ), $search ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $items, [ 'option_name', 'autoload', 'size_formatted' ] );
	}

	/**
	 * Show options table size breakdown.
	 *
	 * Displays total options count, size, and breakdown by autoload status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector options size
	 *     wp vector options size --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function sizeCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stats = $wpdb->get_results(
			"SELECT
				autoload,
				COUNT(*) as count,
				SUM(LENGTH(option_value)) as total_size
			FROM {$wpdb->options}
			GROUP BY autoload",
			ARRAY_A
		);

		$autoload_size     = 0;
		$autoload_count    = 0;
		$no_autoload_size  = 0;
		$no_autoload_count = 0;

		foreach ( $stats as $stat ) {
			if ( 'yes' === $stat['autoload'] ) {
				$autoload_size  = (int) $stat['total_size'];
				$autoload_count = (int) $stat['count'];
			} else {
				$no_autoload_size  = (int) $stat['total_size'];
				$no_autoload_count = (int) $stat['count'];
			}
		}

		$total_size  = $autoload_size + $no_autoload_size;
		$total_count = $autoload_count + $no_autoload_count;

		$data = [
			'total'       => [
				'count'          => $total_count,
				'size_bytes'     => $total_size,
				'size_formatted' => $this->formatSize( $total_size ),
			],
			'autoload'    => [
				'count'          => $autoload_count,
				'size_bytes'     => $autoload_size,
				'size_formatted' => $this->formatSize( $autoload_size ),
				'percentage'     => $total_size > 0 ? round( ( $autoload_size / $total_size ) * 100, 1 ) : 0,
			],
			'no_autoload' => [
				'count'          => $no_autoload_count,
				'size_bytes'     => $no_autoload_size,
				'size_formatted' => $this->formatSize( $no_autoload_size ),
				'percentage'     => $total_size > 0 ? round( ( $no_autoload_size / $total_size ) * 100, 1 ) : 0,
			],
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Options Table Summary ===%n' ) );
		WP_CLI::log( sprintf( 'Total Options:     %d', $total_count ) );
		WP_CLI::log( sprintf( 'Total Size:        %s', $this->formatSize( $total_size ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Autoloaded:        %d options (%s, %.1f%%)', $autoload_count, $this->formatSize( $autoload_size ), $data['autoload']['percentage'] ) );
		WP_CLI::log( sprintf( 'Not Autoloaded:    %d options (%s, %.1f%%)', $no_autoload_count, $this->formatSize( $no_autoload_size ), $data['no_autoload']['percentage'] ) );

		if ( $autoload_size > 1000000 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( 'Autoload data exceeds 1MB. Run `wp vector options autoload` to identify large options.' );
		}

		WP_CLI::log( '' );
	}

	private function formatSize( int $bytes ): string {
		if ( 0 === $bytes ) {
			return '0 B';
		}

		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$i     = 0;
		$size  = (float) $bytes;

		while ( $size >= 1024 && $i < 4 ) {
			$size /= 1024;
			++$i;
		}

		return round( $size, 2 ) . ' ' . $units[ $i ];
	}
}
