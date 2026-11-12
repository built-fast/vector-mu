<?php

declare(strict_types=1);

namespace VectorMU;

/**
 * Resolves which runtime the site runs on from VECTOR_RUNTIME.
 *
 * Production runs on Lambda; dev hosts set VECTOR_RUNTIME=dev in the site's
 * FPM pool. Unset or empty means lambda: WP-CLI on a dev host doesn't see the
 * pool's environment, and without YMIR_ENVIRONMENT the bundled fork stays
 * inert anyway. Any other value is logged and treated as dev, so a typo turns
 * the Lambda-only parts off rather than on.
 */
final class Runtime {

	public const ENV = 'VECTOR_RUNTIME';

	public const LAMBDA = 'lambda';

	public const DEV = 'dev';

	/**
	 * Reads VECTOR_RUNTIME and resolves it, logging an unknown value.
	 */
	public static function fromEnvironment(): string {
		$value   = getenv( self::ENV );
		$runtime = self::resolve( $value );

		if ( ! self::isKnown( $value ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'Vector MU: unknown %s value %s, using "%s"',
					self::ENV,
					wp_json_encode( $value ),
					$runtime
				)
			);
		}

		return $runtime;
	}

	/**
	 * Maps a raw VECTOR_RUNTIME value (false when unset) to a runtime.
	 */
	public static function resolve( string|false $value ): string {
		if ( false === $value || '' === $value || self::LAMBDA === $value ) {
			return self::LAMBDA;
		}

		return self::DEV;
	}

	/**
	 * Whether a raw value is unset, empty, or a known runtime.
	 */
	public static function isKnown( string|false $value ): bool {
		return in_array( $value, [ false, '', self::LAMBDA, self::DEV ], true );
	}
}
