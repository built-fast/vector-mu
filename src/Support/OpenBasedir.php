<?php

declare(strict_types=1);

namespace VectorMU\Support;

/**
 * Checks paths against open_basedir, so probing a path outside it can't warn.
 */
final class OpenBasedir {

	/**
	 * Whether open_basedir permits the path.
	 */
	public static function allows( string $path ): bool {
		$basedir = (string) ini_get( 'open_basedir' );

		if ( '' === $basedir ) {
			return true;
		}

		foreach ( explode( PATH_SEPARATOR, $basedir ) as $allowed ) {
			if ( '' !== $allowed && str_starts_with( $path, $allowed ) ) {
				return true;
			}
		}

		return false;
	}
}
