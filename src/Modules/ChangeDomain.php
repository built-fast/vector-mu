<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;

final class ChangeDomain implements ModuleInterface {

	private const TABLES = [
		'options',
		'posts',
		'postmeta',
		'comments',
		'commentmeta',
	];

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'change-domain', [ $this, 'cliCommand' ] );
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
	 *     wp change-domain example.com newsite.com
	 *     wp change-domain example.com newsite.com --dry-run
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
		WP_CLI::log( "Tables: " . implode( ', ', $tables ) );

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

		WP_CLI::runcommand( 'search-replace ' . implode( ' ', $command_args ), [
			'launch' => false,
		] );
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
