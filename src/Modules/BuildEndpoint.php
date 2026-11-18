<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use Closure;
use Throwable;
use VectorMU\Contracts\ModuleInterface;
use VectorMU\Runtime;

/**
 * Answers GET and HEAD /__vector/build with the production image's build ID.
 *
 * Vector's deploy writes vector-build.php at the WordPress root (ABSPATH) of
 * the image, returning ['build_id' => '<ULID>'], and its post-deploy check
 * requests this path (with a cache-busting query) to prove the new build is
 * the one answering. The response is 200 {"build_id":"<ULID>"} when the file
 * holds a valid ID, 404 {"build_id":null} otherwise, and 405 for any other
 * method, always with Cache-Control: no-store.
 *
 * It responds while vector-mu loads, before regular plugins and the theme,
 * and runs no WordPress hooks, so a site's redirects, login walls, and
 * maintenance plugins can't block it. It's on only in the lambda runtime; on
 * dev hosts the request is left to WordPress (nginx sends /__vector/ to the
 * access gate there anyway).
 */
final class BuildEndpoint implements ModuleInterface {

	public const PATH = '/__vector/build';

	public const FILE = 'vector-build.php';

	public const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, max-age=0, private';

	/** A ULID in Crockford base32. */
	private const BUILD_ID_PATTERN = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/';

	/**
	 * @param string|null  $runtime   The runtime; null reads VECTOR_MU_RUNTIME.
	 * @param string|null  $buildFile The build file; null uses ABSPATH . FILE.
	 * @param Closure|null $emit      Sends a response and ends the request;
	 *                                null uses emit(). Called with the status,
	 *                                headers, and body.
	 */
	public function __construct(
		private readonly ?string $runtime = null,
		private readonly ?string $buildFile = null,
		private readonly ?Closure $emit = null
	) {
	}

	public function register(): void {
		if ( Runtime::LAMBDA !== ( $this->runtime ?? VECTOR_MU_RUNTIME ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- compared exactly, never output.
		$response = $this->respond(
			isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null,
			isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		if ( null === $response ) {
			return;
		}

		( $this->emit ?? self::emit( ... ) )( $response['status'], $response['headers'], $response['body'] );
	}

	/**
	 * The response for a request, or null when the path isn't the endpoint's.
	 * Only the path is matched, exactly; the query string is ignored.
	 *
	 * @return array{status: int, headers: array<string, string>, body: string}|null
	 */
	public function respond( ?string $method, ?string $uri ): ?array {
		if ( null === $uri || self::PATH !== explode( '?', $uri, 2 )[0] ) {
			return null;
		}

		$headers = [
			'Cache-Control' => self::CACHE_CONTROL,
			'Content-Type'  => 'application/json',
		];

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return [
				'status'  => 405,
				'headers' => $headers + [ 'Allow' => 'GET, HEAD' ],
				'body'    => '{"build_id":null}',
			];
		}

		$buildId = $this->buildId();
		$body    = null === $buildId ? '{"build_id":null}' : '{"build_id":"' . $buildId . '"}';

		return [
			'status'  => null === $buildId ? 404 : 200,
			'headers' => $headers,
			'body'    => 'HEAD' === $method ? '' : $body,
		];
	}

	/**
	 * The build ID from the build file, or null when the file is missing or
	 * doesn't hold a valid build ID. Failures are logged without the file's
	 * contents.
	 */
	private function buildId(): ?string {
		$path = $this->buildFile ?? ABSPATH . self::FILE;

		if ( ! is_file( $path ) ) {
			return null;
		}

		if ( ! is_readable( $path ) ) {
			self::log( 'Vector health check could not read vector-build.php' );

			return null;
		}

		$level = ob_get_level();
		ob_start();

		try {
			// An isolated scope: the file sees only $file.
			$build = ( static fn ( string $file ): mixed => include $file )( $path );
		} catch ( Throwable ) {
			// The exception's message can quote the file, so it isn't logged.
			self::log( 'Vector health check could not load vector-build.php' );

			return null;
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		if ( ! is_array( $build ) || ! isset( $build['build_id'] ) || ! is_string( $build['build_id'] )
			|| 1 !== preg_match( self::BUILD_ID_PATTERN, $build['build_id'] ) ) {
			self::log( 'Vector health check ignored vector-build.php: it did not return a valid build ID' );

			return null;
		}

		return $build['build_id'];
	}

	/**
	 * Sends the response and ends the request.
	 *
	 * @param array<string, string> $headers
	 */
	private static function emit( int $status, array $headers, string $body ): never {
		if ( ! headers_sent() ) {
			http_response_code( $status );

			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built from a validated ULID.
		exit;
	}

	private static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}
}
