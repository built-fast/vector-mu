<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use Closure;
use JsonException;

/**
 * The site's gate document, cached in a file and refetched when stale.
 *
 * - The cache is one JSON file per site in the temp directory (/tmp on
 *   Lambda), mode 0600 and written atomically, so a warm container's
 *   requests share it. A cached document is re-validated on every load.
 * - It's fresh for its ttl (clamped to 30-300 s) after it was fetched.
 *   After that the next gated request refetches.
 * - If the refetch fails, the last good document is used until 1 hour past
 *   its fetch, then nothing is: the gate closes. With no document at all (a
 *   cold container) it's closed until a fetch succeeds.
 * - A failed fetch isn't retried for RETRY_AFTER seconds, so an outage
 *   costs one fetch timeout per container every few seconds rather than one
 *   per request.
 *
 * Nothing here logs the bearer token or the document body.
 */
final class DocumentStore {

	/** How long past its fetch a document outlives failed refetches. */
	public const STALE_GRACE = 3600;

	/** Seconds after a failed fetch before the next is tried. */
	public const RETRY_AFTER = 10;

	/** The cache file's version, so a later format is never misread. */
	private const CACHE_VERSION = 1;

	/** Longest cache file read: a 64 KiB body, JSON-escaped, and the envelope. */
	private const MAX_CACHE_BYTES = 6 * CurlDocumentFetcher::MAX_BODY_BYTES + 1024;

	/** The document this request loaded, so it's read at most once. */
	private ?Document $document = null;

	private bool $loaded = false;

	/**
	 * @param Config                $config  The validated environment.
	 * @param DocumentFetcher       $fetcher Fetches the document.
	 * @param Closure(): int        $clock   Now, Unix seconds.
	 * @param string                $dir     The cache directory.
	 * @param Closure(string): void $log     Writes one log line.
	 */
	public function __construct(
		private readonly Config $config,
		private readonly DocumentFetcher $fetcher,
		private readonly Closure $clock,
		private readonly string $dir,
		private readonly Closure $log
	) {
	}

	/**
	 * The document to check this request against, or null when the gate
	 * must refuse everyone.
	 */
	public function current(): ?Document {
		if ( ! $this->loaded ) {
			$this->loaded   = true;
			$this->document = $this->load();
		}

		return $this->document;
	}

	/**
	 * The document current() returned this request, without loading one.
	 */
	public function loaded(): ?Document {
		return $this->document;
	}

	/**
	 * The cache file's path.
	 */
	public function path(): string {
		return $this->dir . '/vector-mu-gate-' . strtolower( $this->config->siteId ) . '.json';
	}

	private function load(): ?Document {
		$now    = ( $this->clock )();
		$cached = $this->readCache( $now );

		if ( null !== $cached['document'] && $now < $cached['fetched_at'] + $cached['document']->ttl ) {
			return $cached['document'];
		}

		if ( null !== $cached['failed_at'] && $now < $cached['failed_at'] + self::RETRY_AFTER ) {
			return $this->withinGrace( $cached, $now );
		}

		try {
			$body     = $this->fetcher->fetch( $this->config->documentUrl, $this->config->documentToken );
			$document = Document::parse( $body, $this->config->siteId, $this->config->previewHost );
		} catch ( GateException $e ) {
			( $this->log )( 'Vector MU: preview gate: ' . $e->getMessage() );
			$this->writeCache( $cached['body'], $cached['fetched_at'], $now );

			return $this->withinGrace( $cached, $now );
		}

		$this->writeCache( $body, $now, null );

		return $document;
	}

	/**
	 * The cached document while it's within the stale grace, else null.
	 *
	 * @param array{document: ?Document, body: ?string, fetched_at: int, failed_at: ?int} $cached
	 */
	private function withinGrace( array $cached, int $now ): ?Document {
		if ( null !== $cached['document'] && $now < $cached['fetched_at'] + self::STALE_GRACE ) {
			return $cached['document'];
		}

		return null;
	}

	/**
	 * Reads the cache file. Anything missing, malformed, not a regular file
	 * owned by this user with no group or other access, from the future, or
	 * no longer valid for this environment counts as no document.
	 *
	 * @return array{document: ?Document, body: ?string, fetched_at: int, failed_at: ?int}
	 */
	private function readCache( int $now ): array {
		$none = [
			'document'   => null,
			'body'       => null,
			'fetched_at' => 0,
			'failed_at'  => null,
		];
		$path = $this->path();

		clearstatcache( true, $path );

		if ( is_link( $path ) || ! is_file( $path ) ) {
			return $none;
		}

		$perms = fileperms( $path );
		$size  = filesize( $path );

		if ( false === $perms || 0 !== ( $perms & 0077 ) || false === $size || $size > self::MAX_CACHE_BYTES
			|| ( function_exists( 'posix_geteuid' ) && fileowner( $path ) !== posix_geteuid() ) ) {
			return $none;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a URL.
		$contents = file_get_contents( $path, false, null, 0, self::MAX_CACHE_BYTES );

		try {
			$data = is_string( $contents ) ? json_decode( $contents, true, 4, JSON_THROW_ON_ERROR ) : null;
		} catch ( JsonException ) {
			$data = null;
		}

		if ( ! is_array( $data ) || self::CACHE_VERSION !== ( $data['v'] ?? null ) ) {
			return $none;
		}

		$failedAt = $data['failed_at'] ?? null;
		$failedAt = is_int( $failedAt ) && $failedAt <= $now ? $failedAt : null;
		$fetched  = $data['fetched_at'] ?? null;
		$body     = $data['body'] ?? null;

		if ( ! is_int( $fetched ) || ! is_string( $body ) || $fetched > $now + Protocol::CLOCK_SKEW ) {
			return [
				'document'   => null,
				'body'       => null,
				'fetched_at' => 0,
				'failed_at'  => $failedAt,
			];
		}

		try {
			$document = Document::parse( $body, $this->config->siteId, $this->config->previewHost );
		} catch ( GateException ) {
			return [
				'document'   => null,
				'body'       => null,
				'fetched_at' => 0,
				'failed_at'  => $failedAt,
			];
		}

		return [
			'document'   => $document,
			'body'       => $body,
			'fetched_at' => $fetched,
			'failed_at'  => $failedAt,
		];
	}

	/**
	 * Replaces the cache file atomically: a new 0600 file in the same
	 * directory, renamed over the old one. A failure is logged and
	 * otherwise ignored; the gate works without the cache, just slower.
	 */
	private function writeCache( ?string $body, int $fetchedAt, ?int $failedAt ): void {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs before WordPress filters matter; needs JSON_THROW_ON_ERROR.
			$json = json_encode(
				[
					'v'          => self::CACHE_VERSION,
					'fetched_at' => $fetchedAt,
					'failed_at'  => $failedAt,
					'body'       => $body,
				],
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
			);
		} catch ( JsonException ) {
			( $this->log )( 'Vector MU: preview gate: could not encode the document cache' );

			return;
		}

		$path = $this->path();
		$tmp  = $this->dir . '/.vector-mu-gate-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$mask = umask( 0077 );

		try {
			// phpcs:disable WordPress.WP.AlternativeFunctions -- a private temp file, not WordPress's filesystem.
			$handle = fopen( $tmp, 'xb' );
			$ok     = false !== $handle
				&& chmod( $tmp, 0600 )
				&& strlen( $json ) === fwrite( $handle, $json );

			if ( false !== $handle ) {
				$ok = fclose( $handle ) && $ok;
			}

			$ok = $ok && rename( $tmp, $path );
			// phpcs:enable WordPress.WP.AlternativeFunctions
		} finally {
			umask( $mask );
		}

		if ( ! $ok ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- our own temp file.
			}

			( $this->log )( 'Vector MU: preview gate: could not write the document cache' );
		}
	}
}
