<?php

declare(strict_types=1);

namespace VectorMU\Gate;

/**
 * A verified vg1 token's payload.
 */
final class Claims {

	/**
	 * @param string $kid  The signing key's ID.
	 * @param string $host The hostname the token admits to.
	 * @param string $kind Protocol::KIND_USER or Protocol::KIND_SHARE.
	 * @param string $sub  The user's or share link's ID.
	 * @param int    $iat  Issued at, Unix seconds.
	 * @param int    $exp  Expires at, Unix seconds.
	 */
	public function __construct(
		public readonly string $kid,
		public readonly string $host,
		public readonly string $kind,
		public readonly string $sub,
		public readonly int $iat,
		public readonly int $exp
	) {
	}
}
