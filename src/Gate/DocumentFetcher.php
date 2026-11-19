<?php

declare(strict_types=1);

namespace VectorMU\Gate;

/**
 * Fetches a gate document's body.
 */
interface DocumentFetcher {

	/**
	 * GETs $url with $token as its bearer token and returns the body of a
	 * 200 response.
	 *
	 * @throws GateException With a reason for logs that never holds the
	 *                       token or the body.
	 */
	public function fetch( string $url, string $token ): string;
}
