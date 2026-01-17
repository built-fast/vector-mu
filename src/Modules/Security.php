<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class Security implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector security', [ $this, 'checkCommand' ] );
		}
	}

	/**
	 * Run security checks on the WordPress installation.
	 *
	 * Checks for common security issues including file permissions, exposed files,
	 * debug settings, and user configuration.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector security
	 *     wp vector security --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function checkCommand( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$checks = [
			$this->checkDebugSettings(),
			$this->checkFileEditing(),
			$this->checkSslAdmin(),
			$this->checkDefaultUsername(),
			$this->checkTablePrefix(),
			$this->checkExposedFiles(),
			$this->checkDirectoryIndexing(),
			$this->checkFilePermissions(),
			$this->checkSecurityKeys(),
			$this->checkXmlRpc(),
		];

		$passed  = 0;
		$failed  = 0;
		$warning = 0;

		foreach ( $checks as $check ) {
			switch ( $check['status'] ) {
				case 'pass':
					++$passed;
					break;
				case 'fail':
					++$failed;
					break;
				case 'warning':
					++$warning;
					break;
			}
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode(
				[
					'summary' => [
						'passed'  => $passed,
						'failed'  => $failed,
						'warning' => $warning,
					],
					'checks'  => $checks,
				],
				JSON_PRETTY_PRINT
			);
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Security Check Results ===%n' ) );
		WP_CLI::log( '' );

		foreach ( $checks as $check ) {
			switch ( $check['status'] ) {
				case 'pass':
					$icon = WP_CLI::colorize( '%G✓%n' );
					break;
				case 'fail':
					$icon = WP_CLI::colorize( '%R✗%n' );
					break;
				case 'warning':
					$icon = WP_CLI::colorize( '%Y⚠%n' );
					break;
				default:
					$icon = ' ';
					break;
			}

			WP_CLI::log( sprintf( '%s %s', $icon, $check['name'] ) );

			if ( 'pass' !== $check['status'] && ! empty( $check['message'] ) ) {
				WP_CLI::log( sprintf( '  %s', $check['message'] ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Summary: %s passed, %s failed, %s warnings',
				WP_CLI::colorize( "%G{$passed}%n" ),
				WP_CLI::colorize( "%R{$failed}%n" ),
				WP_CLI::colorize( "%Y{$warning}%n" )
			)
		);
		WP_CLI::log( '' );

		if ( $failed > 0 ) {
			WP_CLI::warning( 'Security issues detected. Review and address the failed checks.' );
		}
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkDebugSettings(): array {
		$issues = [];

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$issues[] = 'WP_DEBUG is enabled';
		}

		if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
			$issues[] = 'WP_DEBUG_DISPLAY is enabled';
		}

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$issues[] = 'SCRIPT_DEBUG is enabled';
		}

		if ( empty( $issues ) ) {
			return [
				'name'    => 'Debug settings disabled',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Debug settings',
			'status'  => 'fail',
			'message' => implode( ', ', $issues ),
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkFileEditing(): array {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return [
				'name'    => 'File editing disabled',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'File editing',
			'status'  => 'warning',
			'message' => 'DISALLOW_FILE_EDIT not set. Consider adding to wp-config.php.',
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkSslAdmin(): array {
		if ( is_ssl() || force_ssl_admin() ) {
			return [
				'name'    => 'SSL enabled for admin',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'SSL for admin',
			'status'  => 'warning',
			'message' => 'FORCE_SSL_ADMIN not set. Consider enforcing SSL for admin.',
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkDefaultUsername(): array {
		$user = get_user_by( 'login', 'admin' );

		if ( ! $user ) {
			return [
				'name'    => 'No default "admin" username',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Default username',
			'status'  => 'warning',
			'message' => 'Default "admin" username exists. Consider renaming.',
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkTablePrefix(): array {
		global $wpdb;

		if ( 'wp_' !== $wpdb->prefix ) {
			return [
				'name'    => 'Custom table prefix',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Table prefix',
			'status'  => 'warning',
			'message' => 'Using default "wp_" table prefix.',
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkExposedFiles(): array {
		$exposed = [];

		$sensitive_files = [
			ABSPATH . '.git',
			ABSPATH . '.env',
			ABSPATH . '.env.local',
			ABSPATH . '.env.production',
			ABSPATH . 'wp-config.php.bak',
			ABSPATH . 'wp-config.php.old',
			ABSPATH . 'wp-config.php~',
			WP_CONTENT_DIR . '/debug.log',
			ABSPATH . 'error_log',
			ABSPATH . 'php_errorlog',
		];

		foreach ( $sensitive_files as $file ) {
			if ( file_exists( $file ) ) {
				$exposed[] = str_replace( ABSPATH, '', $file );
			}
		}

		if ( empty( $exposed ) ) {
			return [
				'name'    => 'No exposed sensitive files',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Exposed files',
			'status'  => 'fail',
			'message' => 'Found: ' . implode( ', ', $exposed ),
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkDirectoryIndexing(): array {
		$dirs_to_check = [
			WP_CONTENT_DIR . '/uploads',
			WP_CONTENT_DIR . '/plugins',
			WP_CONTENT_DIR . '/themes',
		];

		$missing_index = [];

		foreach ( $dirs_to_check as $dir ) {
			if ( is_dir( $dir ) ) {
				$index_php  = $dir . '/index.php';
				$index_html = $dir . '/index.html';
				$htaccess   = $dir . '/.htaccess';

				// Check if there's any index file or htaccess.
				if ( ! file_exists( $index_php ) && ! file_exists( $index_html ) && ! file_exists( $htaccess ) ) {
					$missing_index[] = str_replace( ABSPATH, '', $dir );
				}
			}
		}

		if ( empty( $missing_index ) ) {
			return [
				'name'    => 'Directory indexing protected',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Directory indexing',
			'status'  => 'warning',
			'message' => 'No index file in: ' . implode( ', ', $missing_index ),
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkFilePermissions(): array {
		$issues = [];

		// Check wp-config.php permissions.
		$wp_config = ABSPATH . 'wp-config.php';
		if ( file_exists( $wp_config ) ) {
			$perms = fileperms( $wp_config ) & 0777;
			if ( $perms > 0640 ) {
				$issues[] = sprintf( 'wp-config.php permissions too open (%o)', $perms );
			}
		}

		// Check wp-content permissions.
		if ( is_dir( WP_CONTENT_DIR ) ) {
			$perms = fileperms( WP_CONTENT_DIR ) & 0777;
			if ( ( $perms & 0002 ) !== 0 ) {
				$issues[] = sprintf( 'wp-content is world-writable (%o)', $perms );
			}
		}

		if ( empty( $issues ) ) {
			return [
				'name'    => 'File permissions secure',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'File permissions',
			'status'  => 'fail',
			'message' => implode( ', ', $issues ),
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkSecurityKeys(): array {
		$keys = [
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		];

		$weak = [];

		foreach ( $keys as $key ) {
			if ( ! defined( $key ) ) {
				$weak[] = $key;
				continue;
			}

			$value = constant( $key );

			// Check for default placeholder values.
			if ( str_contains( $value, 'put your unique phrase here' ) ) {
				$weak[] = $key;
				continue;
			}

			// Check for weak keys (too short).
			if ( strlen( $value ) < 32 ) {
				$weak[] = $key;
			}
		}

		if ( empty( $weak ) ) {
			return [
				'name'    => 'Security keys configured',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'Security keys',
			'status'  => 'fail',
			'message' => 'Weak or missing: ' . implode( ', ', $weak ),
		];
	}

	/**
	 * @return array{name: string, status: string, message: string}
	 */
	private function checkXmlRpc(): array {
		// Check if XML-RPC is disabled via filter.
		$xmlrpc_enabled = apply_filters( 'xmlrpc_enabled', true );

		if ( ! $xmlrpc_enabled ) {
			return [
				'name'    => 'XML-RPC disabled',
				'status'  => 'pass',
				'message' => '',
			];
		}

		// Check if file exists and is accessible.
		$xmlrpc_file = ABSPATH . 'xmlrpc.php';
		if ( ! file_exists( $xmlrpc_file ) ) {
			return [
				'name'    => 'XML-RPC disabled',
				'status'  => 'pass',
				'message' => '',
			];
		}

		return [
			'name'    => 'XML-RPC',
			'status'  => 'warning',
			'message' => 'XML-RPC is enabled. Consider disabling if not needed.',
		];
	}
}
