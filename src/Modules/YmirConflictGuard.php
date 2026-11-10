<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;

/**
 * Keeps the upstream Ymir plugin from loading beside the bundled fork.
 *
 * The fork keeps Ymir's class names, so loading both fatals on a redeclared
 * Ymir\Plugin\Autoloader. Ymir is filtered out of the active plugin lists at
 * read time (nothing is written to the database) and admins get a notice.
 */
final class YmirConflictGuard implements ModuleInterface {

	private const PLUGIN_FILE = 'ymir.php';

	/** @var array<string, true> Plugin basenames removed during this request. */
	private array $blocked = [];

	public function register(): void {
		add_filter( 'option_active_plugins', [ $this, 'filterActivePlugins' ] );
		add_filter( 'site_option_active_sitewide_plugins', [ $this, 'filterNetworkActivePlugins' ] );
		add_action( 'admin_notices', [ $this, 'renderNotice' ] );
		add_action( 'network_admin_notices', [ $this, 'renderNotice' ] );
	}

	/**
	 * Filters the per-site active plugin list (a list of basenames).
	 */
	public function filterActivePlugins( mixed $plugins ): mixed {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		$filtered = [];

		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && $this->isYmir( $plugin ) ) {
				$this->blocked[ $plugin ] = true;
				continue;
			}

			$filtered[] = $plugin;
		}

		return $filtered;
	}

	/**
	 * Filters the network-activated plugin map (basename => activation time).
	 */
	public function filterNetworkActivePlugins( mixed $plugins ): mixed {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		foreach ( array_keys( $plugins ) as $plugin ) {
			if ( is_string( $plugin ) && $this->isYmir( $plugin ) ) {
				$this->blocked[ $plugin ] = true;
				unset( $plugins[ $plugin ] );
			}
		}

		return $plugins;
	}

	public function renderNotice(): void {
		if ( [] === $this->blocked ) {
			return;
		}

		$capability = is_network_admin() ? 'manage_network_plugins' : 'activate_plugins';

		if ( ! current_user_can( $capability ) ) {
			return;
		}

		foreach ( array_keys( $this->blocked ) as $plugin ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				sprintf(
					/* translators: %s: plugin basename, e.g. ymir/ymir.php */
					esc_html__( 'Vector: the Ymir plugin (%s) was not loaded because Vector already provides its features. Please delete it.', 'vector-mu' ),
					'<code>' . esc_html( $plugin ) . '</code>'
				)
			);
		}
	}

	private function isYmir( string $plugin ): bool {
		return self::PLUGIN_FILE === basename( $plugin );
	}
}
