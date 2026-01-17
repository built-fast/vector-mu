<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_CLI\Utils;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Exception;

final class Media implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector media', [ $this, 'summaryCommand' ] );
			WP_CLI::add_command( 'vector media orphaned', [ $this, 'orphanedCommand' ] );
			WP_CLI::add_command( 'vector media missing', [ $this, 'missingCommand' ] );
			WP_CLI::add_command( 'vector media duplicates', [ $this, 'duplicatesCommand' ] );
		}
	}

	/**
	 * Show media library summary.
	 *
	 * Displays overview of media library including counts by type and storage usage.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector media
	 *     wp vector media --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function summaryCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		// Get attachment counts by mime type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mime_counts = $wpdb->get_results(
			"SELECT
				SUBSTRING_INDEX(post_mime_type, '/', 1) as mime_group,
				COUNT(*) as count
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			GROUP BY mime_group
			ORDER BY count DESC",
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
		);

		// Get unattached count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$unattached_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0"
		);

		// Get uploads directory size.
		$uploads_dir  = wp_upload_dir();
		$uploads_size = $this->getDirectorySize( $uploads_dir['basedir'] );

		$data = [
			'total_attachments' => $total_count,
			'unattached'        => $unattached_count,
			'uploads_size'      => [
				'bytes'     => $uploads_size,
				'formatted' => $this->formatSize( $uploads_size ),
			],
			'by_type'           => [],
		];

		foreach ( $mime_counts as $row ) {
			$data['by_type'][ $row['mime_group'] ] = (int) $row['count'];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Media Library Summary ===%n' ) );
		WP_CLI::log( sprintf( 'Total Attachments: %d', $total_count ) );
		WP_CLI::log( sprintf( 'Unattached:        %d', $unattached_count ) );
		WP_CLI::log( sprintf( 'Uploads Size:      %s', $this->formatSize( $uploads_size ) ) );

		if ( ! empty( $mime_counts ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B=== By Type ===%n' ) );
			foreach ( $mime_counts as $row ) {
				WP_CLI::log( sprintf( '%-18s %d', $row['mime_group'] . ':', (int) $row['count'] ) );
			}
		}

		WP_CLI::log( '' );
	}

	/**
	 * Find unattached media files.
	 *
	 * Lists media files that are not attached to any post.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum results to show. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector media orphaned
	 *     wp vector media orphaned --limit=100
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function orphanedCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = (int) ( $assoc_args['limit'] ?? 50 );
		$format = $assoc_args['format'] ?? 'table';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_parent = 0"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_mime_type, post_date, guid
				FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_parent = 0
				ORDER BY post_date DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			WP_CLI::success( 'No unattached media found.' );
			return;
		}

		$items = [];
		foreach ( $results as $row ) {
			$file_path = get_attached_file( (int) $row['ID'] );
			$file_size = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;

			$items[] = [
				'ID'        => (int) $row['ID'],
				'title'     => $row['post_title'] ? $row['post_title'] : '(no title)',
				'mime_type' => $row['post_mime_type'],
				'date'      => $row['post_date'],
				'size'      => $this->formatSize( $file_size ? $file_size : 0 ),
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'total'   => $total,
					'showing' => count( $items ),
					'items'   => $items,
				],
				JSON_PRETTY_PRINT
			);
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d unattached media file(s) (showing %d):', $total, count( $items ) ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $items, [ 'ID', 'title', 'mime_type', 'date', 'size' ] );
	}

	/**
	 * Find attachments with missing files.
	 *
	 * Lists media library entries where the file no longer exists on disk.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum results to show. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector media missing
	 *     wp vector media missing --limit=100
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function missingCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = (int) ( $assoc_args['limit'] ?? 50 );
		$format = $assoc_args['format'] ?? 'table';

		// Get all attachments with their file paths.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attachments = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_mime_type, pm.meta_value as file_path
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment'
			ORDER BY p.ID DESC",
			ARRAY_A
		);

		$uploads_dir = wp_upload_dir();
		$base_dir    = $uploads_dir['basedir'];
		$missing     = [];

		foreach ( $attachments as $attachment ) {
			if ( empty( $attachment['file_path'] ) ) {
				continue;
			}

			$full_path = $base_dir . '/' . $attachment['file_path'];

			if ( ! file_exists( $full_path ) ) {
				$missing[] = [
					'ID'        => (int) $attachment['ID'],
					'title'     => $attachment['post_title'] ? $attachment['post_title'] : '(no title)',
					'mime_type' => $attachment['post_mime_type'],
					'file_path' => $attachment['file_path'],
				];

				if ( count( $missing ) >= $limit ) {
					break;
				}
			}
		}

		if ( empty( $missing ) ) {
			WP_CLI::success( 'No attachments with missing files found.' );
			return;
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $missing, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d attachment(s) with missing files:', count( $missing ) ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $missing, [ 'ID', 'title', 'mime_type', 'file_path' ] );

		WP_CLI::log( '' );
		WP_CLI::warning( 'These attachments reference files that no longer exist on disk.' );
	}

	/**
	 * Find potential duplicate uploads.
	 *
	 * Identifies files with identical names (potential duplicates from re-uploads).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum duplicate groups to show. Default: 20.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector media duplicates
	 *     wp vector media duplicates --limit=50
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function duplicatesCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = (int) ( $assoc_args['limit'] ?? 20 );
		$format = $assoc_args['format'] ?? 'table';

		// Find attachments with similar base filenames (excluding numbered suffixes).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					REGEXP_REPLACE(
						SUBSTRING_INDEX(meta_value, '/', -1),
						'-[0-9]+(\.[a-zA-Z]+)$',
						'\\\\1'
					) as base_name,
					COUNT(*) as count,
					GROUP_CONCAT(post_id ORDER BY post_id) as post_ids
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				GROUP BY base_name
				HAVING count > 1
				ORDER BY count DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			WP_CLI::success( 'No potential duplicates found.' );
			return;
		}

		$total_duplicates = 0;
		$items            = [];

		foreach ( $results as $row ) {
			$post_ids          = explode( ',', $row['post_ids'] );
			$total_duplicates += (int) $row['count'] - 1; // -1 because one is the original

			$items[] = [
				'filename' => $row['base_name'],
				'count'    => (int) $row['count'],
				'ids'      => $row['post_ids'],
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'duplicate_groups' => count( $items ),
					'total_duplicates' => $total_duplicates,
					'groups'           => $items,
				],
				JSON_PRETTY_PRINT
			);
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d filename group(s) with potential duplicates:', count( $items ) ) );
		WP_CLI::log( '' );

		Utils\format_items( 'table', $items, [ 'filename', 'count', 'ids' ] );

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total potential duplicate files: %d', $total_duplicates ) );
		WP_CLI::log( '' );
	}

	private function getDirectorySize( string $path ): int {
		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$size  = 0;
		$flags = FilesystemIterator::SKIP_DOTS;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, $flags ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$size += $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			return 0;
		}

		return $size;
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
