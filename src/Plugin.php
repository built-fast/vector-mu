<?php

declare(strict_types=1);

namespace VectorMU;

use VectorMU\Contracts\ModuleInterface;
use VectorMU\Modules\Blueprint;
use VectorMU\Modules\ChangeDomain;
use VectorMU\Modules\PluginBlocker;
use VectorMU\Modules\UploadsSize;
use VectorMU\Modules\UserImpersonate;

final class Plugin {

	public const VERSION = '1.0.0';

	private static bool $booted = false;

	/** @var list<class-string<ModuleInterface>> */
	private const MODULES = [
		Blueprint::class,
		ChangeDomain::class,
		PluginBlocker::class,
		UploadsSize::class,
		UserImpersonate::class,
	];

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		self::defineConstants();
		self::registerModules();
	}

	private static function defineConstants(): void {
		if ( ! defined( 'VECTOR_MU_VERSION' ) ) {
			define( 'VECTOR_MU_VERSION', self::VERSION );
		}

		if ( ! defined( 'VECTOR_MU_PATH' ) ) {
			define( 'VECTOR_MU_PATH', dirname( __DIR__ ) );
		}
	}

	private static function registerModules(): void {
		foreach ( self::MODULES as $moduleClass ) {
			$module = new $moduleClass();
			$module->register();
		}
	}
}
