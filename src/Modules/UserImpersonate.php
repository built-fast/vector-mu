<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_User;
use function class_exists;
use function hash;
use function time;
use function is_array;
use function is_string;
use function is_int;
use function hash_equals;
use function array_values;

final class UserImpersonate implements ModuleInterface {

	private const META_KEY    = 'vector_impersonate_token';
	private const URL_PARAM   = 'vector_token';
	private const DEFAULT_TTL = 120;

	public function register(): void {
		add_action( 'init', [ $this, 'handleToken' ] );

		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'user impersonate', [ $this, 'cliCommand' ] );
		}
	}

	/**
	 * Generate an impersonation token for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : ID, email address, or user login for the user.
	 *
	 * [--ttl=<seconds>]
	 * : Token time-to-live in seconds. Default: 120
	 *
	 * ## EXAMPLES
	 *
	 *     wp user impersonate admin
	 *     wp user impersonate admin --ttl=60
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cliCommand( array $args, array $assoc_args ): void {
		$fetcher = new WP_CLI\Fetchers\User();
		$user    = $fetcher->get_check( $args[0] );
		$ttl     = (int) ( $assoc_args['ttl'] ?? self::DEFAULT_TTL );

		$token = wp_generate_password( 32, false );
		$hash  = hash( 'sha256', $token );

		$tokens   = $this->getTokens( $user->ID );
		$tokens[] = [
			'hash'    => $hash,
			'expires' => time() + $ttl,
		];

		update_user_meta( $user->ID, self::META_KEY, $tokens );

		$login_url = add_query_arg(
			[
				'user_id'       => $user->ID,
				self::URL_PARAM => $token,
			],
			wp_login_url()
		);

		WP_CLI::log( $login_url );
	}

	/**
	 * @return list<array{hash: string, expires: int}>
	 */
	private function getTokens( int $user_id ): array {
		$tokens = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $tokens ) ) {
			return [];
		}

		$now   = time();
		$valid = [];

		foreach ( $tokens as $t ) {
			if ( is_array( $t ) && isset( $t['hash'], $t['expires'] ) && is_string( $t['hash'] ) && is_int( $t['expires'] ) && $t['expires'] > $now ) {
				$valid[] = [
					'hash'    => $t['hash'],
					'expires' => $t['expires'],
				];
			}
		}

		return $valid;
	}

	public function handleToken(): void {
		global $pagenow;

		if ( 'wp-login.php' !== $pagenow ) {
			return;
		}

		if ( empty( $_GET['user_id'] ) || empty( $_GET[ self::URL_PARAM ] ) ) {
			return;
		}

		$user_id = (int) $_GET['user_id'];
		$token   = sanitize_text_field( wp_unslash( $_GET[ self::URL_PARAM ] ) );

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			$this->dieWithError();
		}

		wp_cache_delete( $user->ID, 'user_meta' );

		$tokens  = $this->getTokens( $user->ID );
		$hash    = hash( 'sha256', $token );
		$matched = false;

		foreach ( $tokens as $i => $data ) {
			if ( hash_equals( $data['hash'], $hash ) ) {
				unset( $tokens[ $i ] );
				$matched = true;
				break;
			}
		}

		update_user_meta( $user->ID, self::META_KEY, array_values( $tokens ) );

		if ( ! $matched ) {
			$this->dieWithError();
		}

		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_safe_redirect( admin_url() );
		exit;
	}

	private function dieWithError(): never {
		wp_die(
			esc_html__( 'Invalid or expired token.' ),
			esc_html__( 'Authentication Error' ),
			[ 'response' => 403 ]
		);
	}
}
