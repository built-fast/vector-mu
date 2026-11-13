<?php

declare(strict_types=1);

namespace VectorMU;

use VectorMU\Contracts\ModuleInterface;
use VectorMU\Modules\LoopbackHeader;
use VectorMU\Modules\VendorPlugins;
use VectorMU\Modules\YmirConflictGuard;

final class Plugin {

	public const VERSION = '0.1.0';

	private static bool $booted = false;

	/** @var list<class-string<ModuleInterface>> */
	private const MODULES = [
		YmirConflictGuard::class,
		VendorPlugins::class,
		LoopbackHeader::class,
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

		if ( ! defined( 'VECTOR_MU_RUNTIME' ) ) {
			define( 'VECTOR_MU_RUNTIME', Runtime::fromEnvironment() );
		}
	}

	private static function registerModules(): void {
		foreach ( self::MODULES as $moduleClass ) {
			$module = new $moduleClass();
			$module->register();
		}
	}
}
