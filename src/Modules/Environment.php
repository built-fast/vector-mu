<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class Environment implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector env', [ $this, 'cliCommand' ] );
		}
	}

	/**
	 * Display environment information snapshot.
	 *
	 * Shows PHP version, extensions, memory, WordPress configuration,
	 * server details, and active constants.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector env
	 *     wp vector env --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cliCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$format = $assoc_args['format'] ?? 'table';

		$data = [
			'php'       => $this->getPhpInfo(),
			'wordpress' => $this->getWordPressInfo(),
			'database'  => $this->getDatabaseInfo( $wpdb ),
			'server'    => $this->getServerInfo(),
			'constants' => $this->getConstants(),
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		$this->displayTable( $data );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getPhpInfo(): array {
		$extensions = get_loaded_extensions();
		sort( $extensions );

		$important_extensions = [
			'curl',
			'gd',
			'imagick',
			'intl',
			'json',
			'mbstring',
			'mysqli',
			'openssl',
			'pcre',
			'xml',
			'zip',
		];

		$missing_extensions = array_diff( $important_extensions, $extensions );

		$memory_limit       = ini_get( 'memory_limit' );
		$max_execution_time = ini_get( 'max_execution_time' );
		$upload_max_size    = ini_get( 'upload_max_filesize' );
		$post_max_size      = ini_get( 'post_max_size' );
		$max_input_vars     = ini_get( 'max_input_vars' );

		return [
			'version'            => PHP_VERSION,
			'sapi'               => PHP_SAPI,
			'memory_limit'       => $memory_limit ? $memory_limit : 'Unknown',
			'max_execution_time' => $max_execution_time ? $max_execution_time : 'Unknown',
			'upload_max_size'    => $upload_max_size ? $upload_max_size : 'Unknown',
			'post_max_size'      => $post_max_size ? $post_max_size : 'Unknown',
			'max_input_vars'     => $max_input_vars ? $max_input_vars : 'Unknown',
			'opcache_enabled'    => function_exists( 'opcache_get_status' ) && opcache_get_status() !== false,
			'extensions_count'   => count( $extensions ),
			'missing_extensions' => $missing_extensions,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getWordPressInfo(): array {
		return [
			'version'              => get_bloginfo( 'version' ),
			'site_url'             => get_site_url(),
			'home_url'             => get_home_url(),
			'multisite'            => is_multisite(),
			'ssl'                  => is_ssl(),
			'language'             => get_locale(),
			'timezone'             => wp_timezone_string(),
			'permalink_structure'  => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'Plain',
			'active_theme'         => wp_get_theme()->get( 'Name' ),
			'active_plugins_count' => count( get_option( 'active_plugins', [] ) ),
			'content_dir'          => WP_CONTENT_DIR,
			'plugin_dir'           => WP_PLUGIN_DIR,
			'uploads_dir'          => wp_upload_dir()['basedir'],
		];
	}

	/**
	 * @param \wpdb $wpdb
	 * @return array<string, mixed>
	 */
	private function getDatabaseInfo( $wpdb ): array {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_get_client_info
		$client_version = mysqli_get_client_info();

		return [
			'server_version' => $wpdb->db_version(),
			'client_version' => $client_version,
			// @phpstan-ignore-next-line constant.notFound
			'database_name'  => DB_NAME,
			'table_prefix'   => $wpdb->prefix,
			'charset'        => $wpdb->charset,
			'collate'        => $wpdb->collate,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getServerInfo(): array {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown';
		$server_addr     = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : 'Unknown';
		$document_root   = isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : ABSPATH;
		// phpcs:enable

		return [
			'software'      => $server_software,
			'hostname'      => gethostname() ? gethostname() : 'Unknown',
			'os'            => PHP_OS,
			'os_family'     => PHP_OS_FAMILY,
			'architecture'  => php_uname( 'm' ),
			'server_ip'     => $server_addr,
			'document_root' => $document_root,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getConstants(): array {
		// We intentionally read these constants directly rather than using the wrapper
		// functions (force_ssl_admin(), wp_is_file_mod_allowed(), wp_revisions_to_keep())
		// because we want to display the actual constant values for diagnostic purposes.
		$constants = [
			'WP_DEBUG'                   => defined( 'WP_DEBUG' ) ? WP_DEBUG : null,
			'WP_DEBUG_LOG'               => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null,
			'WP_DEBUG_DISPLAY'           => defined( 'WP_DEBUG_DISPLAY' ) ? WP_DEBUG_DISPLAY : null,
			'SCRIPT_DEBUG'               => defined( 'SCRIPT_DEBUG' ) ? SCRIPT_DEBUG : null,
			'SAVEQUERIES'                => defined( 'SAVEQUERIES' ) ? SAVEQUERIES : null,
			'DISALLOW_FILE_EDIT'         => defined( 'DISALLOW_FILE_EDIT' ) ? DISALLOW_FILE_EDIT : null,
			'DISALLOW_FILE_MODS'         => defined( 'DISALLOW_FILE_MODS' ) ? DISALLOW_FILE_MODS : null, // @phpstan-ignore phpstanWP.wpConstant.fetch
			'FORCE_SSL_ADMIN'            => defined( 'FORCE_SSL_ADMIN' ) ? FORCE_SSL_ADMIN : null, // @phpstan-ignore phpstanWP.wpConstant.fetch
			'WP_CACHE'                   => defined( 'WP_CACHE' ) ? WP_CACHE : null,
			'COMPRESS_CSS'               => defined( 'COMPRESS_CSS' ) ? COMPRESS_CSS : null,
			'COMPRESS_SCRIPTS'           => defined( 'COMPRESS_SCRIPTS' ) ? COMPRESS_SCRIPTS : null,
			'CONCATENATE_SCRIPTS'        => defined( 'CONCATENATE_SCRIPTS' ) ? CONCATENATE_SCRIPTS : null,
			'DISABLE_WP_CRON'            => defined( 'DISABLE_WP_CRON' ) ? DISABLE_WP_CRON : null,
			'ALTERNATE_WP_CRON'          => defined( 'ALTERNATE_WP_CRON' ) ? ALTERNATE_WP_CRON : null,
			'WP_CRON_LOCK_TIMEOUT'       => defined( 'WP_CRON_LOCK_TIMEOUT' ) ? WP_CRON_LOCK_TIMEOUT : null,
			'WP_MEMORY_LIMIT'            => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
			'WP_MAX_MEMORY_LIMIT'        => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,
			'AUTOMATIC_UPDATER_DISABLED' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) ? AUTOMATIC_UPDATER_DISABLED : null,
			'WP_AUTO_UPDATE_CORE'        => defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
			'EMPTY_TRASH_DAYS'           => defined( 'EMPTY_TRASH_DAYS' ) ? EMPTY_TRASH_DAYS : null,
			'WP_POST_REVISIONS'          => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : null, // @phpstan-ignore phpstanWP.wpConstant.fetch
			'AUTOSAVE_INTERVAL'          => defined( 'AUTOSAVE_INTERVAL' ) ? AUTOSAVE_INTERVAL : null,
		];

		// Filter out undefined constants.
		return array_filter(
			$constants,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function displayTable( array $data ): void {
		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== PHP ===%n' ) );
		WP_CLI::log( sprintf( 'Version:           %s (%s)', $data['php']['version'], $data['php']['sapi'] ) );
		WP_CLI::log( sprintf( 'Memory Limit:      %s', $data['php']['memory_limit'] ) );
		WP_CLI::log( sprintf( 'Max Execution:     %ss', $data['php']['max_execution_time'] ) );
		WP_CLI::log( sprintf( 'Upload Max Size:   %s', $data['php']['upload_max_size'] ) );
		WP_CLI::log( sprintf( 'Post Max Size:     %s', $data['php']['post_max_size'] ) );
		WP_CLI::log( sprintf( 'Max Input Vars:    %s', $data['php']['max_input_vars'] ) );
		WP_CLI::log( sprintf( 'OPcache:           %s', $data['php']['opcache_enabled'] ? 'Enabled' : 'Disabled' ) );
		WP_CLI::log( sprintf( 'Extensions:        %d loaded', $data['php']['extensions_count'] ) );

		if ( ! empty( $data['php']['missing_extensions'] ) ) {
			WP_CLI::log( sprintf( 'Missing:           %s', implode( ', ', $data['php']['missing_extensions'] ) ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== WordPress ===%n' ) );
		WP_CLI::log( sprintf( 'Version:           %s', $data['wordpress']['version'] ) );
		WP_CLI::log( sprintf( 'Site URL:          %s', $data['wordpress']['site_url'] ) );
		WP_CLI::log( sprintf( 'Home URL:          %s', $data['wordpress']['home_url'] ) );
		WP_CLI::log( sprintf( 'Multisite:         %s', $data['wordpress']['multisite'] ? 'Yes' : 'No' ) );
		WP_CLI::log( sprintf( 'SSL:               %s', $data['wordpress']['ssl'] ? 'Yes' : 'No' ) );
		WP_CLI::log( sprintf( 'Language:          %s', $data['wordpress']['language'] ) );
		WP_CLI::log( sprintf( 'Timezone:          %s', $data['wordpress']['timezone'] ) );
		WP_CLI::log( sprintf( 'Permalinks:        %s', $data['wordpress']['permalink_structure'] ) );
		WP_CLI::log( sprintf( 'Active Theme:      %s', $data['wordpress']['active_theme'] ) );
		WP_CLI::log( sprintf( 'Active Plugins:    %d', $data['wordpress']['active_plugins_count'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Database ===%n' ) );
		WP_CLI::log( sprintf( 'Server Version:    %s', $data['database']['server_version'] ) );
		WP_CLI::log( sprintf( 'Client Version:    %s', $data['database']['client_version'] ) );
		WP_CLI::log( sprintf( 'Database:          %s', $data['database']['database_name'] ) );
		WP_CLI::log( sprintf( 'Table Prefix:      %s', $data['database']['table_prefix'] ) );
		WP_CLI::log( sprintf( 'Charset:           %s', $data['database']['charset'] ) );
		WP_CLI::log( sprintf( 'Collation:         %s', $data['database']['collate'] ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Server ===%n' ) );
		WP_CLI::log( sprintf( 'Software:          %s', $data['server']['software'] ) );
		WP_CLI::log( sprintf( 'Hostname:          %s', $data['server']['hostname'] ) );
		WP_CLI::log( sprintf( 'OS:                %s (%s)', $data['server']['os'], $data['server']['os_family'] ) );
		WP_CLI::log( sprintf( 'Architecture:      %s', $data['server']['architecture'] ) );

		if ( ! empty( $data['constants'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B=== Active Constants ===%n' ) );

			foreach ( $data['constants'] as $name => $value ) {
				if ( is_bool( $value ) ) {
					$display_value = $value ? 'true' : 'false';
				} elseif ( is_int( $value ) ) {
					$display_value = (string) $value;
				} elseif ( is_string( $value ) ) {
					$display_value = $value;
				} else {
					$display_value = wp_json_encode( $value );
				}

				WP_CLI::log( sprintf( '%-28s %s', $name . ':', $display_value ) );
			}
		}

		WP_CLI::log( '' );
	}
}
