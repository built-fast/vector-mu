<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class Domain implements ModuleInterface {

	private const TABLES = [
		'options',
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
	];

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector domain rename', [ $this, 'cliCommand' ] );
		}
	}

	/**
	 * Change the site domain, preserving protocol and www prefix.
	 *
	 * ## OPTIONS
	 *
	 * <old-domain>
	 * : The current domain (without protocol), e.g. example.com
	 *
	 * <new-domain>
	 * : The new domain (without protocol), e.g. newsite.com
	 *
	 * [--dry-run]
	 * : Preview changes without modifying the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector domain rename example.com newsite.com
	 *     wp vector domain rename example.com newsite.com --dry-run
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function cliCommand( array $args, array $assoc_args ): void {
		$old_domain = $args[0];
		$new_domain = $args[1];
		$dry_run    = (bool) ( $assoc_args['dry-run'] ?? false );

		$old_escaped = preg_quote( $old_domain, '/' );
		$pattern     = "(https?:)?//(www\\.)?{$old_escaped}";
		$replacement = "\\1//\\2{$new_domain}";

		$tables = $this->getTables();

		WP_CLI::log( "Replacing '{$old_domain}' with '{$new_domain}'..." );
		WP_CLI::log( "Pattern: {$pattern}" );
		WP_CLI::log( 'Tables: ' . implode( ', ', $tables ) );

		if ( $dry_run ) {
			WP_CLI::log( '' );
			WP_CLI::warning( 'Dry run enabled - no changes will be made.' );
		}

		$command_args = [
			$pattern,
			$replacement,
			implode( ' ', $tables ),
			'--regex',
			'--skip-themes',
			'--skip-plugins',
		];

		if ( $dry_run ) {
			$command_args[] = '--dry-run';
		}

		WP_CLI::runcommand(
			'search-replace ' . implode( ' ', $command_args ),
			[
				'launch' => false,
			]
		);

		$this->updateConfigConstants( $old_domain, $new_domain, $dry_run );
	}

	/**
	 * Update WP_HOME and WP_SITEURL in wp-config.php if they exist.
	 */
	private function updateConfigConstants( string $old_domain, string $new_domain, bool $dry_run ): void {
		$constants = [ 'WP_HOME', 'WP_SITEURL' ];

		foreach ( $constants as $const ) {
			// Check if constant exists in wp-config.php.
			/** @var \WP_CLI\ProcessRun $result */
			$result = WP_CLI::launch_self(
				'config get',
				[ $const ],
				[ 'type' => 'constant' ],
				false,
				true
			);

			// Return code 0 means success (found).
			if ( 0 !== $result->return_code ) {
				continue;
			}

			$current_value = trim( $result->stdout );

			$old_escaped = preg_quote( $old_domain, '/' );
			$pattern     = "/(https?:)?\/\/(www\.)?{$old_escaped}/";
			$replacement = "$1//$2{$new_domain}";

			$new_value = preg_replace( $pattern, $replacement, $current_value );

			if ( $current_value !== $new_value && null !== $new_value ) {
				if ( $dry_run ) {
					WP_CLI::log( "Dry run: Would update $const in wp-config.php from '$current_value' to '$new_value'" );
				} else {
					WP_CLI::log( "Updating $const in wp-config.php..." );
					WP_CLI::launch_self(
						'config set',
						[ $const, $new_value ],
						[ 'type' => 'constant' ],
						false,
						true
					);
				}
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function getTables(): array {
		global $wpdb;

		$tables = [];

		foreach ( self::TABLES as $table ) {
			$tables[] = $wpdb->prefix . $table;
		}

		return $tables;
	}
}
