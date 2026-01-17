<?php

declare(strict_types=1);

namespace VectorMU;

use VectorMU\Contracts\ModuleInterface;
use VectorMU\Modules\Blueprint;
use VectorMU\Modules\ChangeDomain;
use VectorMU\Modules\Cleanup;
use VectorMU\Modules\Cron;
use VectorMU\Modules\Database;
use VectorMU\Modules\Environment;
use VectorMU\Modules\Health;
use VectorMU\Modules\Media;
use VectorMU\Modules\Options;
use VectorMU\Modules\PluginBlocker;
use VectorMU\Modules\Security;
use VectorMU\Modules\UploadsSize;
use VectorMU\Modules\UserImpersonate;
use VectorMU\Modules\Users;
use VectorMU\Modules\VectorApi;

final class Plugin {

	public const VERSION = '1.0.0';

	private static bool $booted = false;

	/** @var list<class-string<ModuleInterface>> */
	private const MODULES = [
		Blueprint::class,
		ChangeDomain::class,
		Cleanup::class,
		Cron::class,
		Database::class,
		Environment::class,
		Health::class,
		Media::class,
		Options::class,
		PluginBlocker::class,
		Security::class,
		UploadsSize::class,
		UserImpersonate::class,
		Users::class,
		VectorApi::class,
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
