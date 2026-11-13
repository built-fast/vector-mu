<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use Stringable;
use VectorMU\Contracts\ModuleInterface;
use VectorMU\Support\OpenBasedir;

/**
 * Lets the site's own HTTP requests through a dev host's access gate.
 *
 * Dev sites sit behind an auth gate, so the site's requests to itself (Site
 * Health's loopback check, cron spawning, async and REST self-calls) need to
 * prove they come from the site. The host keeps a per-site secret in
 * /etc/vector/sites/<user>/loopback, readable by the site's POSIX user, and
 * this module sends it as X-Vector-Loopback on WP HTTP API requests whose
 * host is the site's own (the host of home_url() or site_url()).
 *
 * It keys off the file rather than VECTOR_RUNTIME: WP-CLI and cron on a dev
 * host don't see the FPM pool's environment and resolve to lambda, but they
 * need the header too. Without the file (production) it does nothing.
 *
 * The header is set as Requests sends the request, after the
 * http_request_args and pre_http_request filters, and removed before a
 * redirect to another host is followed and on every later hop, so it never
 * leaves the site's host.
 * The secret is read at most once per request and never logged.
 */
final class LoopbackHeader implements ModuleInterface {

	public const HEADER = 'X-Vector-Loopback';

	public const SECRETS_DIR = '/etc/vector/sites';

	/** Longest file read; a valid secret is 64 bytes plus a newline. */
	private const MAX_FILE_BYTES = 256;

	/** The secret once read, or false when there is none. */
	private string|false|null $secret = null;

	public function __construct(
		private readonly string $secretsDir = self::SECRETS_DIR
	) {
	}

	public function register(): void {
		// Last, so other before_request listeners never see the secret.
		add_action( 'requests-requests.before_request', [ $this, 'beforeRequest' ], PHP_INT_MAX, 5 );
		// First, so nothing hooked to a cross-host redirect sees it either.
		add_action( 'requests-requests.before_redirect', [ $this, 'beforeRedirect' ], PHP_INT_MIN, 2 );
	}

	/**
	 * Sets the header for the site's own host and removes it for any other.
	 *
	 * Runs for the first request and again for each redirect Requests follows.
	 * A redirect only keeps a header the previous hop carried, so once a
	 * chain has left the site's host it never gets the header back.
	 *
	 * @param mixed $url     Request URL.
	 * @param mixed $headers Request headers.
	 * @param mixed $data    Request body (unused).
	 * @param mixed $type    Request method (unused).
	 * @param mixed $options Requests options; `redirected` counts the hops.
	 */
	public function beforeRequest( mixed &$url, mixed &$headers, mixed &$data = null, mixed &$type = null, mixed &$options = null ): void {
		$secret = $this->secret();

		if ( null === $secret || ! is_array( $headers ) ) {
			return;
		}

		$redirected = is_array( $options ) && isset( $options['redirected'] ) && $options['redirected'] > 0;
		$stripped   = self::withoutHeader( $headers );
		$carried    = count( $stripped ) !== count( $headers );
		$headers    = $stripped;

		if ( ( ! $redirected || $carried ) && $this->isOwnHost( $url ) ) {
			$headers[ self::HEADER ] = $secret;
		}
	}

	/**
	 * Removes the header before a redirect to another host is followed.
	 */
	public function beforeRedirect( mixed &$location, mixed &$headers ): void {
		if ( null === $this->secret() || ! is_array( $headers ) || $this->isOwnHost( $location ) ) {
			return;
		}

		$headers = self::withoutHeader( $headers );
	}

	/**
	 * The site's secret, or null when the file is missing, unreadable, or
	 * malformed. Read at most once.
	 */
	private function secret(): ?string {
		if ( null === $this->secret ) {
			$this->secret = $this->readSecret() ?? false;
		}

		return false === $this->secret ? null : $this->secret;
	}

	private function readSecret(): ?string {
		$user = self::effectiveUser();

		if ( null === $user ) {
			return null;
		}

		$path = $this->secretsDir . '/' . $user . '/loopback';

		if ( ! OpenBasedir::allows( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a URL.
		$contents = file_get_contents( $path, false, null, 0, self::MAX_FILE_BYTES );

		if ( false === $contents ) {
			return null;
		}

		$secret = trim( $contents );

		return 1 === preg_match( '/\A[0-9a-f]{64}\z/', $secret ) ? $secret : null;
	}

	/**
	 * The effective POSIX user's name, or null without the posix extension or
	 * for a name that isn't safe in a path.
	 */
	private static function effectiveUser(): ?string {
		if ( ! function_exists( 'posix_geteuid' ) || ! function_exists( 'posix_getpwuid' ) ) {
			return null;
		}

		$entry = posix_getpwuid( posix_geteuid() );

		if ( false === $entry ) {
			return null;
		}

		return 1 === preg_match( '/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/', $entry['name'] ) ? $entry['name'] : null;
	}

	private function isOwnHost( mixed $url ): bool {
		$host = self::host( $url );

		if ( null === $host ) {
			return false;
		}

		foreach ( [ home_url(), site_url() ] as $own ) {
			if ( self::host( $own ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A URL's lowercased host, or null when it has none.
	 */
	private static function host( mixed $url ): ?string {
		if ( $url instanceof Stringable ) {
			$url = (string) $url;
		}

		if ( ! is_string( $url ) ) {
			return null;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host ? strtolower( $host ) : null;
	}

	/**
	 * Headers without X-Vector-Loopback, matched case-insensitively.
	 *
	 * @param array<mixed> $headers Request headers.
	 * @return array<mixed>
	 */
	private static function withoutHeader( array $headers ): array {
		foreach ( array_keys( $headers ) as $name ) {
			if ( is_string( $name ) && 0 === strcasecmp( $name, self::HEADER ) ) {
				unset( $headers[ $name ] );
			}
		}

		return $headers;
	}
}
