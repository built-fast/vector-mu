<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use VectorMU\Contracts\ModuleInterface;

final class PluginBlocker implements ModuleInterface {

	private const BLOCKED_PLUGINS = [
		'wp-rocket/wp-rocket.php' => 'WP Rocket is incompatible with this hosting environment.',
	];

	/** @var list<string> */
	private array $deactivated = [];

	public function register(): void {
		add_filter( 'option_active_plugins', [ $this, 'filterActivePlugins' ], PHP_INT_MAX );
		add_action( 'admin_notices', [ $this, 'renderAdminNotice' ] );
	}

	/**
	 * @param mixed $plugins
	 * @return mixed
	 */
	public function filterActivePlugins( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		foreach ( $plugins as $key => $plugin ) {
			if ( isset( self::BLOCKED_PLUGINS[ $plugin ] ) ) {
				unset( $plugins[ $key ] );
				$this->deactivated[] = $plugin;
			}
		}

		return array_values( $plugins );
	}

	public function renderAdminNotice(): void {
		if ( empty( $this->deactivated ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$items = '';
		foreach ( $this->deactivated as $plugin ) {
			$items .= sprintf( '<li>%s</li>', esc_html( self::BLOCKED_PLUGINS[ $plugin ] ) );
		}

		printf(
			'<div class="notice notice-error"><p><strong>The following plugins are not supported on this hosting environment and have been disabled:</strong></p><ul>%s</ul></div>',
			wp_kses_post( $items )
		);
	}
}
