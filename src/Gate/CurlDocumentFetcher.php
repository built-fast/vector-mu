<?php

declare(strict_types=1);

namespace VectorMU\Gate;

use CurlHandle;
use VectorMU\Plugin;

/**
 * Fetches the gate document with PHP's curl extension directly.
 *
 * Not WordPress's HTTP API: it runs filters (http_request_args,
 * pre_http_request, https_ssl_verify, http_request_timeout,
 * http_request_redirection_count) and Requests hooks that any must-use
 * plugin loaded before vector-mu, or a drop-in, could use to turn off TLS
 * verification, follow redirects, or read the bearer token, and it honours
 * WP_PROXY_* constants. Not PHP streams either: their timeout is per read,
 * not for the whole request. curl gives a total deadline, HTTPS only, no
 * redirects, verified TLS, no proxy, and a body cap, all set here and
 * nowhere else. Ymir's PHP runtime ships the curl extension; without it,
 * every fetch fails and the gate closes.
 */
final class CurlDocumentFetcher implements DocumentFetcher {

	/** The whole request's deadline, connect included. */
	public const TIMEOUT_MS = 3000;

	/** The longest body read; more is a failed fetch. */
	public const MAX_BODY_BYTES = 65536;

	// phpcs:disable WordPress.WP.AlternativeFunctions -- curl on purpose; see the class comment.
	public function fetch( string $url, string $token ): string {
		if ( '' === $url ) {
			throw new GateException( 'document URL empty' );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			throw new GateException( 'document fetch needs the curl extension' );
		}

		$handle = curl_init();

		if ( ! $handle instanceof CurlHandle ) {
			throw new GateException( 'document fetch could not start curl' );
		}

		$body     = '';
		$tooLarge = false;

		$options = [
			CURLOPT_URL               => $url,
			CURLOPT_HTTPGET           => true,
			CURLOPT_HTTPHEADER        => [ 'Authorization: Bearer ' . $token, 'Accept: application/json' ],
			CURLOPT_USERAGENT         => 'vector-mu/' . Plugin::VERSION,
			CURLOPT_PROTOCOLS         => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION    => false,
			CURLOPT_SSL_VERIFYPEER    => true,
			CURLOPT_SSL_VERIFYHOST    => 2,
			CURLOPT_PROXY             => '',
			CURLOPT_NOSIGNAL          => true,
			CURLOPT_CONNECTTIMEOUT_MS => self::TIMEOUT_MS,
			CURLOPT_TIMEOUT_MS        => self::TIMEOUT_MS,
			CURLOPT_WRITEFUNCTION     => static function ( CurlHandle $handle, string $chunk ) use ( &$body, &$tooLarge ): int {
				if ( strlen( $body ) + strlen( $chunk ) > self::MAX_BODY_BYTES ) {
					$tooLarge = true;

					return 0;
				}

				$body .= $chunk;

				return strlen( $chunk );
			},
		];

		if ( ! curl_setopt_array( $handle, $options ) ) {
			throw new GateException( 'document fetch could not set curl options' );
		}

		$ok     = curl_exec( $handle );
		$errno  = curl_errno( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

		if ( $tooLarge ) {
			throw new GateException( 'document larger than 64 KiB' );
		}

		if ( false === $ok || 0 !== $errno ) {
			// curl_strerror names the failure without the URL or headers.
			throw new GateException( 'document fetch failed: curl error ' . $errno . ' (' . curl_strerror( $errno ) . ')' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a log reason, never output.
		}

		if ( 200 !== $status ) {
			throw new GateException( 'document fetch answered ' . $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a log reason, never output.
		}

		return $body;
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions
}
