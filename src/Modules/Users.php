<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_CLI\Utils;
use WP_User;

final class Users implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector users admins', [ $this, 'adminsCommand' ] );
			WP_CLI::add_command( 'vector users inactive', [ $this, 'inactiveCommand' ] );
			WP_CLI::add_command( 'vector users capabilities', [ $this, 'capabilitiesCommand' ] );
		}
	}

	/**
	 * List all users with administrator capabilities.
	 *
	 * Shows users with 'administrator' role or 'manage_options' capability.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector users admins
	 *     wp vector users admins --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function adminsCommand( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';

		$users = get_users(
			[
				'role__in' => [ 'administrator' ],
				'orderby'  => 'registered',
				'order'    => 'ASC',
			]
		);

		// Also check for users with manage_options who might not be administrators.
		$all_users = get_users(
			[
				'capability' => 'manage_options',
				'orderby'    => 'registered',
				'order'      => 'ASC',
			]
		);

		// Merge and deduplicate.
		$user_ids = [];
		$merged   = [];

		foreach ( array_merge( $users, $all_users ) as $user ) {
			if ( ! isset( $user_ids[ $user->ID ] ) ) {
				$user_ids[ $user->ID ] = true;
				$merged[]              = $user;
			}
		}

		if ( empty( $merged ) ) {
			WP_CLI::log( 'No administrator users found.' );
			return;
		}

		$items = [];
		foreach ( $merged as $user ) {
			$last_login = get_user_meta( $user->ID, 'last_login', true );

			$items[] = [
				'ID'           => $user->ID,
				'user_login'   => $user->user_login,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => implode( ', ', $user->roles ),
				'registered'   => $user->user_registered,
				'last_login'   => $last_login ? $last_login : 'Never/Unknown',
			];
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $items, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d user(s) with admin capabilities:', count( $items ) ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $items, [ 'ID', 'user_login', 'user_email', 'roles', 'registered' ] );

		// Warn about default admin username.
		foreach ( $items as $item ) {
			if ( 'admin' === strtolower( $item['user_login'] ) ) {
				WP_CLI::log( '' );
				WP_CLI::warning( 'Default "admin" username exists. Consider renaming for security.' );
				break;
			}
		}
	}

	/**
	 * Find users who haven't logged in recently.
	 *
	 * Requires a plugin that tracks last login in user meta (key: last_login).
	 * Falls back to registration date if last_login is not available.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Days of inactivity. Default: 90.
	 *
	 * [--role=<role>]
	 * : Filter by user role.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector users inactive
	 *     wp vector users inactive --days=180
	 *     wp vector users inactive --role=subscriber
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function inactiveCommand( array $args, array $assoc_args ): void {
		global $wpdb;

		$days   = (int) ( $assoc_args['days'] ?? 90 );
		$role   = $assoc_args['role'] ?? '';
		$format = $assoc_args['format'] ?? 'table';

		$cutoff_timestamp = (int) strtotime( "-{$days} days" );

		$user_args = [
			'orderby' => 'registered',
			'order'   => 'ASC',
		];

		if ( $role ) {
			$user_args['role'] = $role;
		}

		$users = get_users( $user_args );
		$items = [];

		foreach ( $users as $user ) {
			$last_login = get_user_meta( $user->ID, 'last_login', true );

			// Determine inactivity based on last_login meta or registration date.
			$is_inactive    = false;
			$inactive_since = '';

			if ( $last_login ) {
				$last_login_time = is_numeric( $last_login ) ? (int) $last_login : strtotime( $last_login );
				if ( $last_login_time && $last_login_time < $cutoff_timestamp ) {
					$is_inactive    = true;
					$inactive_since = wp_date( 'Y-m-d H:i:s', $last_login_time );
				}
			} else {
				// No last_login meta, use registration date.
				$registered_time = strtotime( $user->user_registered );
				if ( $registered_time && $registered_time < $cutoff_timestamp ) {
					$is_inactive    = true;
					$inactive_since = $user->user_registered . ' (registered)';
				}
			}

			if ( $is_inactive ) {
				$items[] = [
					'ID'            => $user->ID,
					'user_login'    => $user->user_login,
					'user_email'    => $user->user_email,
					'roles'         => implode( ', ', $user->roles ),
					'registered'    => $user->user_registered,
					'last_activity' => $inactive_since,
				];
			}
		}

		if ( empty( $items ) ) {
			WP_CLI::success( sprintf( 'No users inactive for more than %d days.', $days ) );
			return;
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $items, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d user(s) inactive for more than %d days:', count( $items ), $days ) );
		WP_CLI::log( '' );

		Utils\format_items( $format, $items, [ 'ID', 'user_login', 'user_email', 'roles', 'last_activity' ] );
	}

	/**
	 * Show effective capabilities for a user.
	 *
	 * Displays all capabilities a user has from their roles and any direct grants.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, list. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector users capabilities admin
	 *     wp vector users capabilities 1 --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function capabilitiesCommand( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Please provide a user ID, login, or email.' );
			return; // WP_CLI::error exits, but this helps static analysis.
		}

		$format = $assoc_args['format'] ?? 'table';

		$user = $this->getUser( $args[0] );

		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
			return; // WP_CLI::error exits, but this helps static analysis.
		}

		$all_caps = $user->allcaps;
		ksort( $all_caps );

		// Separate into granted and denied.
		$granted = [];
		$denied  = [];

		foreach ( $all_caps as $cap => $has ) {
			if ( $has ) {
				$granted[] = $cap;
			} else {
				$denied[] = $cap;
			}
		}

		$data = [
			'user_id'    => $user->ID,
			'user_login' => $user->user_login,
			'roles'      => $user->roles,
			'granted'    => $granted,
			'denied'     => $denied,
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'User: %s (ID: %d)', $user->user_login, $user->ID ) );
		WP_CLI::log( sprintf( 'Roles: %s', implode( ', ', $user->roles ) ) );
		WP_CLI::log( '' );

		if ( 'list' === $format ) {
			WP_CLI::log( WP_CLI::colorize( '%B=== Granted Capabilities ===%n' ) );
			foreach ( $granted as $cap ) {
				WP_CLI::log( '  ' . $cap );
			}

			if ( ! empty( $denied ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( WP_CLI::colorize( '%Y=== Denied Capabilities ===%n' ) );
				foreach ( $denied as $cap ) {
					WP_CLI::log( '  ' . $cap );
				}
			}
		} else {
			$items = [];
			foreach ( $granted as $cap ) {
				$items[] = [
					'capability' => $cap,
					'status'     => 'granted',
				];
			}
			foreach ( $denied as $cap ) {
				$items[] = [
					'capability' => $cap,
					'status'     => 'denied',
				];
			}

			Utils\format_items( 'table', $items, [ 'capability', 'status' ] );
		}

		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total: %d granted, %d denied', count( $granted ), count( $denied ) ) );
		WP_CLI::log( '' );
	}

	private function getUser( string $identifier ): ?WP_User {
		// Try by ID.
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
			if ( $user ) {
				return $user;
			}
		}

		// Try by login.
		$user = get_user_by( 'login', $identifier );
		if ( $user ) {
			return $user;
		}

		// Try by email.
		$user = get_user_by( 'email', $identifier );
		if ( $user ) {
			return $user;
		}

		return null;
	}
}
