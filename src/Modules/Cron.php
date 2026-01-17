<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;
use WP_CLI;
use WP_CLI\Utils;

final class Cron implements ModuleInterface {

	public function register(): void {
		if ( class_exists( WP_CLI::class ) ) {
			WP_CLI::add_command( 'vector cron', [ $this, 'listCommand' ] );
			WP_CLI::add_command( 'vector cron list', [ $this, 'listCommand' ] );
			WP_CLI::add_command( 'vector cron status', [ $this, 'statusCommand' ] );
		}
	}

	/**
	 * List scheduled cron events.
	 *
	 * Shows all scheduled cron events with next run time and schedule interval.
	 *
	 * ## OPTIONS
	 *
	 * [--overdue]
	 * : Only show overdue events.
	 *
	 * [--hook=<hook>]
	 * : Filter by hook name (partial match).
	 *
	 * [--limit=<limit>]
	 * : Maximum events to show. Default: 50.
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cron
	 *     wp vector cron --overdue
	 *     wp vector cron --hook=woocommerce
	 *     wp vector cron --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function listCommand( array $args, array $assoc_args ): void {
		$crons        = _get_cron_array();
		$only_overdue = isset( $assoc_args['overdue'] );
		$hook_filter  = $assoc_args['hook'] ?? '';
		$limit        = (int) ( $assoc_args['limit'] ?? 50 );
		$format       = $assoc_args['format'] ?? 'table';
		$now          = time();

		if ( ! $crons ) {
			WP_CLI::log( 'No cron events scheduled.' );
			return;
		}

		$items = [];
		$count = 0;

		foreach ( $crons as $timestamp => $hooks ) {
			if ( $count >= $limit ) {
				break;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( $count >= $limit ) {
					break;
				}

				if ( $hook_filter && false === stripos( $hook, $hook_filter ) ) {
					continue;
				}

				$is_overdue = $timestamp < $now;

				if ( $only_overdue && ! $is_overdue ) {
					continue;
				}

				foreach ( $events as $key => $event ) {
					if ( $count >= $limit ) {
						break;
					}

					$schedule = $event['schedule'] ?? 'single';
					$interval = isset( $event['interval'] ) ? $this->formatInterval( (int) $event['interval'] ) : '-';

					$items[] = [
						'hook'      => $hook,
						'next_run'  => wp_date( 'Y-m-d H:i:s', $timestamp ),
						'schedule'  => $schedule,
						'interval'  => $interval,
						'overdue'   => $is_overdue ? 'Yes' : 'No',
						'timestamp' => $timestamp,
					];

					++$count;
				}
			}
		}

		if ( empty( $items ) ) {
			$message = $only_overdue ? 'No overdue cron events.' : 'No cron events found.';
			WP_CLI::log( $message );
			return;
		}

		if ( 'json' === $format ) {
			$output = wp_json_encode( $items, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '[]' );
			return;
		}

		Utils\format_items( $format, $items, [ 'hook', 'next_run', 'schedule', 'interval', 'overdue' ] );
	}

	/**
	 * Show cron system status and statistics.
	 *
	 * Displays overview of cron health including overdue events, schedules, and hook frequency.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vector cron status
	 *     wp vector cron status --format=json
	 *
	 * @param list<string>         $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function statusCommand( array $args, array $assoc_args ): void {
		$crons  = _get_cron_array();
		$format = $assoc_args['format'] ?? 'table';
		$now    = time();

		$total_events   = 0;
		$overdue_events = 0;
		$schedules      = [];
		$hooks          = [];
		$oldest_overdue = null;

		if ( $crons ) {
			foreach ( $crons as $timestamp => $cron_hooks ) {
				foreach ( $cron_hooks as $hook => $events ) {
					$event_count   = count( $events );
					$total_events += $event_count;

					if ( $timestamp < $now ) {
						$overdue_events += $event_count;
						if ( null === $oldest_overdue || $timestamp < $oldest_overdue ) {
							$oldest_overdue = (int) $timestamp;
						}
					}

					foreach ( $events as $event ) {
						$schedule               = $event['schedule'] ?? 'single';
						$schedules[ $schedule ] = ( $schedules[ $schedule ] ?? 0 ) + 1;
					}

					$hooks[ $hook ] = ( $hooks[ $hook ] ?? 0 ) + $event_count;
				}
			}
		}

		// Sort hooks by count.
		arsort( $hooks );
		$top_hooks = array_slice( $hooks, 0, 10, true );

		// Check for DISABLE_WP_CRON.
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		// Check for ALTERNATE_WP_CRON.
		$alternate_cron = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;

		$data = [
			'total_events'     => $total_events,
			'overdue_events'   => $overdue_events,
			'unique_hooks'     => count( $hooks ),
			'oldest_overdue'   => $oldest_overdue ? wp_date( 'Y-m-d H:i:s', $oldest_overdue ) : null,
			'overdue_age'      => $oldest_overdue ? $this->formatInterval( $now - $oldest_overdue ) : null,
			'wp_cron_disabled' => $wp_cron_disabled,
			'alternate_cron'   => $alternate_cron,
			'schedules'        => $schedules,
			'top_hooks'        => $top_hooks,
		];

		if ( 'json' === $format ) {
			$output = wp_json_encode( $data, JSON_PRETTY_PRINT );
			WP_CLI::log( false !== $output ? $output : '{}' );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cron Status ===%n' ) );
		WP_CLI::log( sprintf( 'Total Events:      %d', $total_events ) );
		WP_CLI::log( sprintf( 'Unique Hooks:      %d', count( $hooks ) ) );
		WP_CLI::log( sprintf( 'Overdue Events:    %d', $overdue_events ) );

		if ( $oldest_overdue ) {
			WP_CLI::log( sprintf( 'Oldest Overdue:    %s (%s ago)', $data['oldest_overdue'], $data['overdue_age'] ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%B=== Cron Configuration ===%n' ) );
		WP_CLI::log( sprintf( 'DISABLE_WP_CRON:   %s', $wp_cron_disabled ? 'Yes' : 'No' ) );
		WP_CLI::log( sprintf( 'ALTERNATE_WP_CRON: %s', $alternate_cron ? 'Yes' : 'No' ) );

		if ( ! empty( $schedules ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B=== Schedule Breakdown ===%n' ) );
			foreach ( $schedules as $schedule => $count ) {
				WP_CLI::log( sprintf( '%-18s %d events', $schedule . ':', $count ) );
			}
		}

		if ( ! empty( $top_hooks ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%B=== Top 10 Hooks ===%n' ) );
			foreach ( $top_hooks as $hook => $count ) {
				WP_CLI::log( sprintf( '%-40s %d', $hook, $count ) );
			}
		}

		if ( $overdue_events > 0 ) {
			WP_CLI::log( '' );
			WP_CLI::warning( sprintf( '%d overdue cron event(s). Run `wp cron event run --due-now` to process them.', $overdue_events ) );
		}

		WP_CLI::log( '' );
	}

	private function formatInterval( int $seconds ): string {
		if ( $seconds < 60 ) {
			return $seconds . 's';
		}

		if ( $seconds < 3600 ) {
			return round( $seconds / 60 ) . 'm';
		}

		if ( $seconds < 86400 ) {
			return round( $seconds / 3600, 1 ) . 'h';
		}

		return round( $seconds / 86400, 1 ) . 'd';
	}
}
