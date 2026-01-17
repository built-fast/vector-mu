<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_CLI\Utils;

final class Database implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector db summary', [ $this, 'summaryCommand' ] );
			WP_CLI::add_command( 'vector db tables', [ $this, 'tablesCommand' ] );
			WP_CLI::add_command( 'vector db revisions', [ $this, 'revisionsCommand' ] );
			WP_CLI::add_command( 'vector db transients', [ $this, 'transientsCommand' ] );
			WP_CLI::add_command( 'vector db orphans', [ $this, 'orphansCommand' ] );
		}
	}

	/**
	 * Show database summary and diagnostics.
	 *
	 * Displays overview of database size, table count, and potential issues.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector db summary
	 *     wp vector db summary --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function summaryCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		// Get table stats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		$total_size   = 0;
		$total_rows   = 0;
		$table_count  = 0;
		$wp_tables    = 0;
		$other_tables = 0;

		if ( is_array( $tables ) ) {
			foreach ( $tables as $table ) {
				$size = (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
				$rows = (int) ( $table['Rows'] ?? 0 );

				$total_size += $size;
				$total_rows += $rows;
				++$table_count;

				if ( str_starts_with( $table['Name'], $wpdb->prefix ) ) {
					++$wp_tables;
				} else {
					++$other_tables;
				}
			}
		}

		// Get revisions count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$revisions_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		// Get auto-drafts count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$autodrafts_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'"
		);

		// Get trash count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$trash_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		// Get transients count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$transients = $wpdb->get_row(
			"SELECT
				COUNT(CASE WHEN option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%' THEN 1 END) as total,
				COUNT(CASE WHEN option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP() THEN 1 END) as expired
			FROM {$wpdb->options}",
			ARRAY_A
		);

		$transients_total   = (int) ( $transients['total'] ?? 0 );
		$transients_expired = (int) ( $transients['expired'] ?? 0 );

		$data = [
			'database' => [
				'name'   => DB_NAME,
				'prefix' => $wpdb->prefix,
			],
			'tables'   => [
				'total' => $table_count,
				'wp'    => $wp_tables,
				'other' => $other_tables,
			],
			'size'     => [
				'bytes'     => $total_size,
				'formatted' => $this->formatSize( $total_size ),
			],
			'rows'     => $total_rows,
			'cleanup'  => [
				'revisions'          => $revisions_count,
				'auto_drafts'        => $autodrafts_count,
				'trash'              => $trash_count,
				'transients'         => $transients_total,
				'expired_transients' => $transients_expired,
			],
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Database Overview ===%n' ) );
		WP_CLI::log( sprintf( 'Database:          %s', DB_NAME ) );
		WP_CLI::log( sprintf( 'Table Prefix:      %s', $wpdb->prefix ) );
		WP_CLI::log( sprintf( 'Total Size:        %s', $this->formatSize( $total_size ) ) );
		WP_CLI::log( sprintf( 'Total Rows:        %s', number_format( $total_rows ) ) );
		WP_CLI::log( sprintf( 'Tables:            %d total (%d WP, %d other)', $table_count, $wp_tables, $other_tables ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cleanup Candidates ===%n' ) );
		WP_CLI::log( sprintf( 'Revisions:         %s', number_format( $revisions_count ) ) );
		WP_CLI::log( sprintf( 'Auto-drafts:       %s', number_format( $autodrafts_count ) ) );
		WP_CLI::log( sprintf( 'Trashed Posts:     %s', number_format( $trash_count ) ) );
		WP_CLI::log( sprintf( 'Transients:        %s (%d expired)', number_format( $transients_total ), $transients_expired ) );

		$cleanup_total = $revisions_count + $autodrafts_count + $trash_count + $transients_expired;
		if ( $cleanup_total > 100 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( 'Consider running `wp vector cleanup` to remove stale data.' );
		}

		WP_CLI::log( '' );
	}

	/**
	 * List database tables sorted by size.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of tables to show. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector db tables
	 *     wp vector db tables --limit=50
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function tablesCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = (int) ( $assoc_args['limit'] ?? 20 );
		$format = $assoc_args['format'] ?? 'table';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( empty( $tables ) ) {
			WP_CLI::log( 'No tables found.' );
			return;
		}

		$items = [];
		foreach ( $tables as $table ) {
			$size    = (int) ( $table['Data_length'] ?? 0 ) + (int) ( $table['Index_length'] ?? 0 );
			$items[] = [
				'table'          => $table['Name'],
				'rows'           => number_format( (int) ( $table['Rows'] ?? 0 ) ),
				'size_bytes'     => $size,
				'size_formatted' => $this->formatSize( $size ),
				'engine'         => $table['Engine'] ?? 'Unknown',
			];
		}

		// Sort by size descending.
		usort(
			$items,
			function ( $a, $b ) {
				return $b['size_bytes'] <=> $a['size_bytes'];
			}
		);

		$items = array_slice( $items, 0, $limit );

		if ( 'json' === $format ) {
			$output = wp_json_encode( $items, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		Utils\format_items( $format, $items, [ 'table', 'rows', 'size_formatted', 'engine' ] );
	}

	/**
	 * Show revision statistics by post type.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector db revisions
	 *     wp vector db revisions --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function revisionsCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		// Get revisions grouped by parent post type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			"SELECT
				p.post_type as parent_type,
				COUNT(r.ID) as revision_count,
				COUNT(DISTINCT r.post_parent) as posts_with_revisions,
				AVG(revision_counts.rev_per_post) as avg_revisions_per_post
			FROM {$wpdb->posts} r
			JOIN {$wpdb->posts} p ON r.post_parent = p.ID
			JOIN (
				SELECT post_parent, COUNT(*) as rev_per_post
				FROM {$wpdb->posts}
				WHERE post_type = 'revision'
				GROUP BY post_parent
			) revision_counts ON revision_counts.post_parent = r.post_parent
			WHERE r.post_type = 'revision'
			GROUP BY p.post_type
			ORDER BY revision_count DESC",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		if ( empty( $results ) ) {
			WP_CLI::log( 'No revisions found.' );
			return;
		}

		$items = [];
		foreach ( $results as $row ) {
			$items[] = [
				'post_type'    => $row['parent_type'],
				'revisions'    => number_format( (int) $row['revision_count'] ),
				'posts'        => number_format( (int) $row['posts_with_revisions'] ),
				'avg_per_post' => round( (float) $row['avg_revisions_per_post'], 1 ),
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'total'   => $total,
					'by_type' => $items,
				],
				JSON_PRETTY_PRINT
			);
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( sprintf( 'Total revisions: %s', number_format( $total ) ) );
		WP_CLI::log( '' );

		Utils\format_items( 'table', $items, [ 'post_type', 'revisions', 'posts', 'avg_per_post' ] );
	}

	/**
	 * Show transient statistics.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector db transients
	 *     wp vector db transients --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function transientsCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';
		$now    = time();

		$transient_like      = $wpdb->esc_like( '_transient_' ) . '%';
		$transient_timeout   = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$site_transient_like = $wpdb->esc_like( '_site_transient_' ) . '%';
		$site_timeout_like   = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(CASE WHEN option_name LIKE %s AND option_name NOT LIKE %s THEN 1 END) as total,
					SUM(CASE WHEN option_name LIKE %s AND option_name NOT LIKE %s THEN LENGTH(option_value) END) as total_size,
					COUNT(CASE WHEN option_name LIKE %s AND option_name NOT LIKE %s THEN 1 END) as site_total,
					SUM(CASE WHEN option_name LIKE %s AND option_name NOT LIKE %s THEN LENGTH(option_value) END) as site_total_size
				FROM {$wpdb->options}",
				$transient_like,
				$transient_timeout,
				$transient_like,
				$transient_timeout,
				$site_transient_like,
				$site_timeout_like,
				$site_transient_like,
				$site_timeout_like
			),
			ARRAY_A
		);

		// Count expired transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_value < %d",
				$transient_timeout,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$site_expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_value < %d",
				$site_timeout_like,
				$now
			)
		);

		$data = [
			'transients'      => [
				'total'          => (int) ( $stats['total'] ?? 0 ),
				'expired'        => $expired,
				'size'           => (int) ( $stats['total_size'] ?? 0 ),
				'size_formatted' => $this->formatSize( (int) ( $stats['total_size'] ?? 0 ) ),
			],
			'site_transients' => [
				'total'          => (int) ( $stats['site_total'] ?? 0 ),
				'expired'        => $site_expired,
				'size'           => (int) ( $stats['site_total_size'] ?? 0 ),
				'size_formatted' => $this->formatSize( (int) ( $stats['site_total_size'] ?? 0 ) ),
			],
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Transients ===%n' ) );
		WP_CLI::log( sprintf( 'Total:             %d', $data['transients']['total'] ) );
		WP_CLI::log( sprintf( 'Expired:           %d', $data['transients']['expired'] ) );
		WP_CLI::log( sprintf( 'Size:              %s', $data['transients']['size_formatted'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Site Transients ===%n' ) );
		WP_CLI::log( sprintf( 'Total:             %d', $data['site_transients']['total'] ) );
		WP_CLI::log( sprintf( 'Expired:           %d', $data['site_transients']['expired'] ) );
		WP_CLI::log( sprintf( 'Size:              %s', $data['site_transients']['size_formatted'] ) );

		$total_expired = $expired + $site_expired;
		if ( $total_expired > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( sprintf( '%d expired transient(s). Run `wp vector cleanup transients` to remove them.', $total_expired ) );
		}

		WP_CLI::log( '' );
	}

	/**
	 * Find orphaned metadata.
	 *
	 * Shows counts of postmeta, commentmeta, and termmeta entries with no parent.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector db orphans
	 *     wp vector db orphans --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function orphansCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		// Orphaned postmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_postmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->postmeta} pm
			LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE p.ID IS NULL"
		);

		// Orphaned commentmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_commentmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->commentmeta} cm
			LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
			WHERE c.comment_ID IS NULL"
		);

		// Orphaned termmeta.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_termmeta = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->termmeta} tm
			LEFT JOIN {$wpdb->terms} t ON tm.term_id = t.term_id
			WHERE t.term_id IS NULL"
		);

		// Orphaned term relationships.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orphaned_term_relationships = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->term_relationships} tr
			LEFT JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			WHERE p.ID IS NULL"
		);

		$data = [
			'orphaned_postmeta'           => $orphaned_postmeta,
			'orphaned_commentmeta'        => $orphaned_commentmeta,
			'orphaned_termmeta'           => $orphaned_termmeta,
			'orphaned_term_relationships' => $orphaned_term_relationships,
			'total'                       => $orphaned_postmeta + $orphaned_commentmeta + $orphaned_termmeta + $orphaned_term_relationships,
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Orphaned Data ===%n' ) );
		WP_CLI::log( sprintf( 'Orphaned postmeta:           %s', number_format( $orphaned_postmeta ) ) );
		WP_CLI::log( sprintf( 'Orphaned commentmeta:        %s', number_format( $orphaned_commentmeta ) ) );
		WP_CLI::log( sprintf( 'Orphaned termmeta:           %s', number_format( $orphaned_termmeta ) ) );
		WP_CLI::log( sprintf( 'Orphaned term relationships: %s', number_format( $orphaned_term_relationships ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total orphaned entries:      %s', number_format( $data['total'] ) ) );

		if ( $data['total'] > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( 'Run `wp vector cleanup orphans` to remove orphaned data.' );
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
