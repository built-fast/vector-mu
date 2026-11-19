<?php

declare(strict_types=1);

namespace VectorMU\Gate;

/**
 * What a gate cookie records: who may visit which host until when.
 */
final class Session {

	/**
	 * @param string $host The hostname the cookie admits to.
	 * @param string $kind The token's kind.
	 * @param string $sub  The token's subject.
	 * @param int    $exp  Expires at, Unix seconds.
	 */
	public function __construct(
		public readonly string $host,
		public readonly string $kind,
		public readonly string $sub,
		public readonly int $exp
	) {
	}

	/**
	 * The session for verified claims at $now: it ends at the earlier of
	 * now plus the cookie's longest lifetime and the token's exp.
	 */
	public static function fromClaims( Claims $claims, int $now ): self {
		return new self( $claims->host, $claims->kind, $claims->sub, min( $now + Protocol::MAX_COOKIE_LIFETIME, $claims->exp ) );
	}

	/**
	 * Whole seconds left at $now, for the cookie's Max-Age; 0 or less means
	 * it has expired.
	 */
	public function maxAge( int $now ): int {
		return $this->exp - $now;
	}
}
