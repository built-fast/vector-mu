<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Exception;

final class Health implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector health', [ $this, 'cliCommand' ] );
		}
	}

	/**
	 * Display a quick site health summary.
	 *
	 * Shows WordPress/PHP versions, memory limits, debug mode status,
	 * plugin/theme counts, database size, uploads size, and cron status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector health
	 *     wp vector health --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cliCommand( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$health = $this->collectHealthData();

		if ( 'json' === $format ) {
			$output = wp_json_encode( $health, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		$this->displayTable( $health );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function collectHealthData(): array {
		global $wpdb;

		$plugins        = get_plugins();
		$active_plugins = get_option( 'active_plugins', [] );
		$themes         = wp_get_themes();
		$active_theme   = wp_get_theme();

		// Database size.
		$db_size = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		if ( is_array( $tables ) ) {
			foreach ( $tables as $table ) {
				$db_size += (int) ( $table['Data_length'] ?? 0 );
				$db_size += (int) ( $table['Index_length'] ?? 0 );
			}
		}

		// Autoload size.
		// WordPress 6.1+ uses 'on'/'off'/'auto'/'auto-on'/'auto-off', older uses 'yes'/'no'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$autoload_size = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes', 'on', 'auto-on')"
		);

		// Uploads size.
		$uploads_dir  = wp_upload_dir();
		$uploads_size = $this->getDirectorySize( $uploads_dir['basedir'] );

		// Cron status.
		$crons         = _get_cron_array();
		$cron_count    = 0;
		$overdue_crons = 0;
		$now           = time();

		if ( $crons ) {
			foreach ( $crons as $timestamp => $hooks ) {
				$cron_count += count( $hooks );
				if ( $timestamp < $now ) {
					$overdue_crons += count( $hooks );
				}
			}
		}

		// Check for common issues.
		$issues = [];

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$issues[] = 'WP_DEBUG is enabled';
		}

		if ( $autoload_size > 1000000 ) {
			$issues[] = 'Autoload data exceeds 1MB (' . $this->formatSize( $autoload_size ) . ')';
		}

		if ( $overdue_crons > 0 ) {
			$issues[] = $overdue_crons . ' overdue cron job(s)';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Simple check, not file operation.
		$wp_content_writable = is_writable( WP_CONTENT_DIR );
		if ( ! $wp_content_writable ) {
			$issues[] = 'wp-content directory is not writable';
		}

		return [
			'environment'  => [
				'wp_version'    => get_bloginfo( 'version' ),
				'php_version'   => PHP_VERSION,
				'mysql_version' => $wpdb->db_version(),
				'memory_limit'  => ini_get( 'memory_limit' ) ? ini_get( 'memory_limit' ) : 'Unknown',
				'max_execution' => ini_get( 'max_execution_time' ) ? ini_get( 'max_execution_time' ) : 'Unknown',
				'wp_debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'multisite'     => is_multisite(),
			],
			'plugins'      => [
				'total'    => count( $plugins ),
				'active'   => count( $active_plugins ),
				'inactive' => count( $plugins ) - count( $active_plugins ),
			],
			'themes'       => [
				'total'  => count( $themes ),
				'active' => $active_theme->get( 'Name' ),
			],
			'database'     => [
				'size_bytes'     => $db_size,
				'size_formatted' => $this->formatSize( $db_size ),
				'prefix'         => $wpdb->prefix,
			],
			'autoload'     => [
				'size_bytes'     => $autoload_size,
				'size_formatted' => $this->formatSize( $autoload_size ),
			],
			'uploads'      => [
				'size_bytes'     => $uploads_size,
				'size_formatted' => $this->formatSize( $uploads_size ),
			],
			'cron'         => [
				'scheduled' => $cron_count,
				'overdue'   => $overdue_crons,
			],
			'issues'       => $issues,
			'issues_count' => count( $issues ),
		];
	}

	/**
	 * @param array<string, mixed> $health
	 */
	private function displayTable( array $health ): void {
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Environment ===%n' ) );
		WP_CLI::log( sprintf( 'WordPress:       %s', $health['environment']['wp_version'] ) );
		WP_CLI::log( sprintf( 'PHP:             %s', $health['environment']['php_version'] ) );
		WP_CLI::log( sprintf( 'MySQL:           %s', $health['environment']['mysql_version'] ) );
		WP_CLI::log( sprintf( 'Memory Limit:    %s', $health['environment']['memory_limit'] ) );
		WP_CLI::log( sprintf( 'Max Execution:   %ss', $health['environment']['max_execution'] ) );
		WP_CLI::log( sprintf( 'WP_DEBUG:        %s', $health['environment']['wp_debug'] ? 'Enabled' : 'Disabled' ) );
		WP_CLI::log( sprintf( 'Multisite:       %s', $health['environment']['multisite'] ? 'Yes' : 'No' ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Plugins & Themes ===%n' ) );
		WP_CLI::log( sprintf( 'Plugins:         %d active, %d inactive (%d total)', $health['plugins']['active'], $health['plugins']['inactive'], $health['plugins']['total'] ) );
		WP_CLI::log( sprintf( 'Active Theme:    %s', $health['themes']['active'] ) );
		WP_CLI::log( sprintf( 'Themes:          %d installed', $health['themes']['total'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Storage ===%n' ) );
		WP_CLI::log( sprintf( 'Database:        %s', $health['database']['size_formatted'] ) );
		WP_CLI::log( sprintf( 'Autoload Data:   %s', $health['autoload']['size_formatted'] ) );
		WP_CLI::log( sprintf( 'Uploads:         %s', $health['uploads']['size_formatted'] ) );
		WP_CLI::log( sprintf( 'DB Prefix:       %s', $health['database']['prefix'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cron ===%n' ) );
		WP_CLI::log( sprintf( 'Scheduled:       %d events', $health['cron']['scheduled'] ) );
		WP_CLI::log( sprintf( 'Overdue:         %d events', $health['cron']['overdue'] ) );

		if ( count( $health['issues'] ) > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%Y=== Issues Detected ===%n' ) );
			foreach ( $health['issues'] as $issue ) {
				WP_CLI::log( WP_CLI::colorize( '%Y⚠%n ' ) . $issue );
			}
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%G✓ No issues detected%n' ) );
		}

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
