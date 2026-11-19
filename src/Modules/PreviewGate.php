<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use Closure;
use Throwable;
use VectorMU\Contracts\ModuleInterface;
use VectorMU\Gate\Config;
use VectorMU\Gate\Cookie;
use VectorMU\Gate\CurlDocumentFetcher;
use VectorMU\Gate\Document;
use VectorMU\Gate\DocumentFetcher;
use VectorMU\Gate\DocumentStore;
use VectorMU\Gate\GateException;
use VectorMU\Gate\Protocol;
use VectorMU\Gate\Session;
use VectorMU\Gate\Token;
use VectorMU\Runtime;

/**
 * Keeps a production site private except on its live custom domains.
 *
 * On Lambda nothing sits in front of PHP but CloudFront, so this does what
 * the dev gate does for dev sites, with the same vg1 tokens and v1 cookie.
 *
 * - Hosts in VECTOR_PUBLIC_HOSTS are never gated and get no gate headers.
 *   Every other host is gated: the preview hostname, Ymir's vanity domain,
 *   CloudFront's and API Gateway's, and any host a forged X-Forwarded-Host
 *   names (Ymir's runtime takes HTTP_HOST from it). The preview hostname is
 *   gated even if the list names it. A missing or empty list gates every
 *   host; an invalid one is logged and gates every host too.
 * - Tokens and cookies are bound to the preview hostname, so only there are
 *   they accepted. Any other gated host gets the private page.
 * - GET /__vector/auth?t=<token>&r=<path> verifies the token against the
 *   gate document's keys, sets the __Host-vector_gate cookie, and redirects
 *   303 to r when it's a safe relative path, "/" otherwise.
 * - Every other request on the preview hostname needs a valid cookie whose
 *   share link, if any, isn't revoked. A failure gets the private page,
 *   401, with a link to the site in Vector Control and no site content.
 * - Exempt: GET and HEAD /__vector/build (the deploy's check), anything
 *   that isn't an HTTP request (Ymir's console and cron run PHP's CLI), and
 *   requests with the site's X-Vector-Loopback secret.
 * - Every response on a gated host sends X-Robots-Tag: noindex, nofollow
 *   and Cache-Control: private, no-store, exempt or not.
 * - With a gate variable missing or invalid it refuses every non-exempt
 *   request on every gated host, logging which variable (never its value).
 *   Public hosts stay public, so a broken gate config never takes a live
 *   domain down.
 *
 * It runs in the lambda runtime only, as vector-mu's first module, while
 * must-use plugins load: before regular plugins, the theme, and any
 * WordPress hook. Tokens, cookies, and secrets are never logged.
 */
final class PreviewGate implements ModuleInterface {

	public const ROBOTS = 'noindex, nofollow';

	public const CACHE_CONTROL = 'private, no-store';

	public const LOOPBACK_SERVER_KEY = 'HTTP_X_VECTOR_LOOPBACK';

	/** /__vector/auth's longest query: a token, a redirect, and their escaping. */
	private const MAX_QUERY_LENGTH = 3 * ( Protocol::MAX_TOKEN_LENGTH + Protocol::MAX_REDIRECT_LENGTH );

	/** How many gate cookies a request may offer; a browser sends one. */
	private const MAX_COOKIES = 4;

	private const CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

	private const PRIVATE_PAGE_HEAD = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Private preview site</title>
<style>
body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f6f7f9;color:#1d2330;display:flex;min-height:100vh;align-items:center;justify-content:center}
main{max-width:28rem;padding:2rem;margin:1rem;background:#fff;border:1px solid #dde1e8;border-radius:8px}
h1{font-size:1.25rem;margin:0 0 .5rem}
p{margin:.5rem 0 0;color:#4a5263}
a{color:#2457c5}
@media (prefers-color-scheme:dark){body{background:#14171d;color:#e6e9ef}main{background:#1d2129;border-color:#2e3440}p{color:#a9b1c1}a{color:#8fb3ff}}
</style>
</head>
<body>
<main>
<h1>This preview site is private</h1>
<p>Sign in to Vector and open the site from there, or ask its owner for a share link.</p>

HTML;

	private const PRIVATE_PAGE_TAIL = <<<'HTML'
</main>
</body>
</html>

HTML;

	/**
	 * @param string|null                        $runtime     The runtime; null reads VECTOR_MU_RUNTIME.
	 * @param Closure(string): (string|false)|null $env       Reads a variable; null uses getenv().
	 * @param Closure(): int|null                $clock       Now, Unix seconds; null uses time().
	 * @param DocumentFetcher|null               $fetcher     Null uses CurlDocumentFetcher.
	 * @param string|null                        $cacheDir    The document cache's directory; null uses the temp directory.
	 * @param Closure(string): void|null         $log         Writes a log line; null uses error_log().
	 * @param Closure|null                       $emit        Sends a response and ends the request; null uses emit().
	 *                                                        Called with the status, a list of [name, value]
	 *                                                        headers, and the body.
	 * @param Closure|null                       $markPreview Sends a gated host's headers on a response
	 *                                                        WordPress goes on to serve; null uses markPreview().
	 * @param string|null                        $sapi        The SAPI; null uses PHP_SAPI.
	 */
	public function __construct(
		private readonly ?string $runtime = null,
		private readonly ?Closure $env = null,
		private readonly ?Closure $clock = null,
		private readonly ?DocumentFetcher $fetcher = null,
		private readonly ?string $cacheDir = null,
		private readonly ?Closure $log = null,
		private readonly ?Closure $emit = null,
		private readonly ?Closure $markPreview = null,
		private readonly ?string $sapi = null
	) {
	}

	public function register(): void {
		if ( Runtime::LAMBDA !== ( $this->runtime ?? VECTOR_MU_RUNTIME ) ) {
			return;
		}

		try {
			$result = $this->handle( $_SERVER, $this->sapi ?? PHP_SAPI );
		} catch ( Throwable $e ) {
			// Fail closed. The class alone: a message could quote input.
			$this->log( 'Vector MU: preview gate failed, refusing the request: ' . get_class( $e ) );

			$result = [
				'gated'    => true,
				'response' => $this->privateResponse( 'GET', '' ),
			];
		}

		if ( null !== $result['response'] ) {
			( $this->emit ?? self::emit( ... ) )( $result['response']['status'], $result['response']['headers'], $result['response']['body'] );

			return;
		}

		if ( $result['gated'] ) {
			( $this->markPreview ?? self::markPreview( ... ) )();
		}
	}

	/**
	 * Decides a request: whether its host is gated, and the gate's own
	 * response, or null to let WordPress serve it.
	 *
	 * @param array<mixed> $server $_SERVER.
	 * @param string       $sapi   PHP_SAPI.
	 * @return array{gated: bool, response: array{status: int, headers: list<array{0: string, 1: string}>, body: string}|null}
	 */
	public function handle( array $server, string $sapi ): array {
		$pass = [
			'gated'    => false,
			'response' => null,
		];

		// Ymir's console and cron invocations run PHP's CLI; only HTTP
		// requests come through FPM.
		if ( 'cli' === $sapi || 'phpdbg' === $sapi ) {
			return $pass;
		}

		$method = self::serverString( $server, 'REQUEST_METHOD' ) ?? '';
		$uri    = self::serverString( $server, 'REQUEST_URI' ) ?? '';
		$host   = Protocol::requestHost( self::serverString( $server, 'HTTP_HOST' ) ?? '' );
		$parts  = explode( '?', $uri, 2 );
		$path   = $parts[0];
		$query  = $parts[1] ?? '';
		$config = Config::fromEnvironment( $this->env() );

		if ( null !== $host && $this->isPublic( $host ) ) {
			return $pass;
		}

		// Every other host is gated: the preview hostname, Ymir's vanity
		// domain, CloudFront's and API Gateway's, and whatever a forged
		// X-Forwarded-Host names.
		$pass['gated'] = true;

		if ( BuildEndpoint::PATH === $path && ( 'GET' === $method || 'HEAD' === $method ) ) {
			return $pass;
		}

		if ( $this->isLoopback( $server ) ) {
			return $pass;
		}

		if ( ! $config instanceof Config ) {
			$this->log( 'Vector MU: preview gate refused a request: ' . implode( ', ', $config ) . ' missing or invalid' );

			return [
				'gated'    => true,
				'response' => $this->privateResponse( $method, '' ),
			];
		}

		// Tokens and cookies are bound to the preview hostname, so on any
		// other gated host there's nothing to accept: refuse, without a
		// redirect and without fetching the document.
		if ( $host !== $config->previewHost ) {
			return [
				'gated'    => true,
				'response' => $this->privateResponse( $method, Protocol::controlSiteUrl( $config->controlUrl, $config->siteId ) ),
			];
		}

		$store = new DocumentStore(
			$config,
			$this->fetcher ?? new CurlDocumentFetcher(),
			$this->clock ?? time( ... ),
			$this->cacheDir ?? sys_get_temp_dir(),
			$this->log ?? self::errorLog( ... )
		);

		return [
			'gated'    => true,
			'response' => match ( true ) {
				Protocol::AUTH_PATH === $path              => $this->auth( $config, $store, $method, $query ),
				Protocol::PRIVATE_PATH === $path           => $this->privateResponse( $method, $this->link( $config, $store ) ),
				$this->allowed( $config, $store, self::serverString( $server, 'HTTP_COOKIE' ) ?? '' ) => null,
				default                                    => $this->privateResponse( $method, $this->link( $config, $store ) ),
			},
		];
	}

	/**
	 * Whether $host is one of VECTOR_PUBLIC_HOSTS and isn't the preview
	 * hostname, which is always private. An invalid list is logged (never
	 * its value) and counts as empty, so every host is gated.
	 */
	private function isPublic( string $host ): bool {
		$public = Config::publicHosts( $this->env() );

		if ( null === $public ) {
			$this->log( 'Vector MU: preview gate: ' . Config::PUBLIC_HOSTS . ' is invalid (an entry is not a lowercase DNS name); gating every host' );

			return false;
		}

		// Compared with the raw variable, so a broken gate config still
		// can't turn the preview hostname public.
		$preview = $this->env()( Config::PREVIEW_HOST );

		return in_array( $host, $public, true ) && ( ! is_string( $preview ) || strtolower( $preview ) !== $host );
	}

	/**
	 * Whether the request carries the site's loopback secret.
	 *
	 * @param array<mixed> $server
	 */
	private function isLoopback( array $server ): bool {
		$sent = self::serverString( $server, self::LOOPBACK_SERVER_KEY );

		if ( null === $sent ) {
			return false;
		}

		$secret = Config::loopbackSecret( $this->env() );

		return null !== $secret && hash_equals( $secret, $sent );
	}

	/**
	 * Whether one of the request's gate cookies admits it.
	 */
	private function allowed( Config $config, DocumentStore $store, string $cookieHeader ): bool {
		$values = self::gateCookies( $cookieHeader );

		if ( [] === $values ) {
			return false;
		}

		$document = $store->current();

		if ( null === $document ) {
			$this->log( 'Vector MU: preview gate refused a cookie: no gate document' );

			return false;
		}

		$key = Cookie::key( $config->cookieSecret, $document->sessionEpoch );
		$now = $this->now();

		foreach ( $values as $value ) {
			try {
				$session = Cookie::verify( $key, $value, $config->previewHost, $now );
			} catch ( GateException ) {
				continue;
			}

			if ( ! $document->isRevoked( $session->kind, $session->sub ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * /__vector/auth: verify the t parameter's token, set the cookie, and
	 * redirect 303 to the r parameter's path. Any failure is a 401 with the
	 * private page.
	 *
	 * @return array{status: int, headers: list<array{0: string, 1: string}>, body: string}
	 */
	private function auth( Config $config, DocumentStore $store, string $method, string $query ): array {
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $this->response( 405, [ [ 'Allow', 'GET, HEAD' ] ], '' );
		}

		// The token is in this URL; don't hand it to anything the page loads.
		$noReferrer = [ [ 'Referrer-Policy', 'no-referrer' ] ];

		try {
			$signIn = $this->signIn( $config, $store, $query );
		} catch ( GateException $e ) {
			$this->log( 'Vector MU: preview gate refused a sign-in: ' . $e->getMessage() );

			return $this->privateResponse( $method, $this->link( $config, $store ), $noReferrer );
		}

		return $this->response(
			303,
			[
				...$noReferrer,
				[ 'Set-Cookie', Protocol::COOKIE_NAME . '=' . $signIn['cookie'] . '; Path=/; Max-Age=' . $signIn['max_age'] . '; HttpOnly; Secure; SameSite=Lax' ],
				[ 'Location', $signIn['redirect'] ],
			],
			''
		);
	}

	/**
	 * Verifies /__vector/auth's query and returns the cookie to set, its
	 * Max-Age, and where to redirect.
	 *
	 * @return array{cookie: string, max_age: int, redirect: string}
	 * @throws GateException With a reason for logs.
	 */
	private function signIn( Config $config, DocumentStore $store, string $query ): array {
		if ( strlen( $query ) > self::MAX_QUERY_LENGTH ) {
			throw new GateException( 'query too long' );
		}

		$params = self::parseQuery( $query );

		if ( null === $params ) {
			throw new GateException( 'bad query' );
		}

		$tokens = $params[ Protocol::TOKEN_PARAM ] ?? [];

		if ( 1 !== count( $tokens ) ) {
			throw new GateException( 'want exactly one token' );
		}

		$document = $store->current();

		if ( null === $document ) {
			throw new GateException( 'no gate document' );
		}

		$now    = $this->now();
		$claims = Token::verify( $tokens[0], $document->keys, $config->previewHost, $now );

		if ( $document->isRevoked( $claims->kind, $claims->sub ) ) {
			throw new GateException( 'share link revoked' );
		}

		$session = Session::fromClaims( $claims, $now );
		$maxAge  = $session->maxAge( $now );

		if ( $maxAge <= 0 ) {
			throw new GateException( 'token expires now' );
		}

		$cookie    = Cookie::value( Cookie::key( $config->cookieSecret, $document->sessionEpoch ), $session );
		$redirects = $params[ Protocol::REDIRECT_PARAM ] ?? [];

		$this->log( sprintf( 'Vector MU: preview gate signed in %s %s with key %s for %d s', $claims->kind, $claims->sub, $claims->kid, $maxAge ) );

		return [
			'cookie'   => $cookie,
			'max_age'  => $maxAge,
			'redirect' => 1 === count( $redirects ) ? Protocol::safeRedirect( $redirects[0] ) : '/',
		];
	}

	/**
	 * The private page's link: the site in Vector Control, from the gate
	 * document when this request loaded one and from the document URL's
	 * origin otherwise. Never fetches just for the link.
	 */
	private function link( Config $config, DocumentStore $store ): string {
		$document = $store->loaded();

		return Protocol::controlSiteUrl( $document instanceof Document ? $document->controlUrl : $config->controlUrl, $config->siteId );
	}

	/**
	 * The private page as a 401, with a link to $href when it isn't empty
	 * (no body for HEAD).
	 *
	 * @param list<array{0: string, 1: string}> $headers
	 * @return array{status: int, headers: list<array{0: string, 1: string}>, body: string}
	 */
	private function privateResponse( string $method, string $href, array $headers = [] ): array {
		$page = self::PRIVATE_PAGE_HEAD;

		if ( '' !== $href ) {
			$page .= '<p><a href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\" rel=\"noreferrer\">Open in Vector</a></p>\n";
		}

		$page .= self::PRIVATE_PAGE_TAIL;

		return $this->response(
			401,
			[
				...$headers,
				[ 'Content-Type', 'text/html; charset=utf-8' ],
				[ 'Content-Security-Policy', self::CSP ],
				[ 'Content-Length', (string) strlen( $page ) ],
			],
			'HEAD' === $method ? '' : $page
		);
	}

	/**
	 * A gate response, with the headers every one of them sends.
	 *
	 * @param list<array{0: string, 1: string}> $headers
	 * @return array{status: int, headers: list<array{0: string, 1: string}>, body: string}
	 */
	private function response( int $status, array $headers, string $body ): array {
		return [
			'status'  => $status,
			'headers' => [
				[ 'X-Robots-Tag', self::ROBOTS ],
				[ 'Cache-Control', self::CACHE_CONTROL ],
				[ 'X-Content-Type-Options', 'nosniff' ],
				...$headers,
			],
			'body'    => $body,
		];
	}

	/**
	 * The values of up to MAX_COOKIES __Host-vector_gate cookies in a Cookie
	 * header, read raw (PHP's $_COOKIE keeps one value and URL-decodes it).
	 *
	 * @return list<string>
	 */
	private static function gateCookies( string $header ): array {
		$values = [];

		foreach ( explode( ';', $header ) as $pair ) {
			$parts = explode( '=', $pair, 2 );

			if ( 2 !== count( $parts ) || Protocol::COOKIE_NAME !== trim( $parts[0], " \t" ) ) {
				continue;
			}

			$value = trim( $parts[1], " \t" );

			if ( strlen( $value ) >= 2 && '"' === $value[0] && '"' === $value[ strlen( $value ) - 1 ] ) {
				$value = substr( $value, 1, -1 );
			}

			$values[] = $value;

			if ( count( $values ) >= self::MAX_COOKIES ) {
				break;
			}
		}

		return $values;
	}

	/**
	 * Parses a query string as Go's url.ParseQuery does: & separates pairs,
	 * a ; or a bad percent escape fails the whole query, and + is a space.
	 * Unlike PHP's parse_str, names aren't mangled and repeats are kept.
	 *
	 * @return array<string, list<string>>|null
	 */
	private static function parseQuery( string $query ): ?array {
		$params = [];

		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			if ( str_contains( $pair, ';' ) ) {
				return null;
			}

			$parts = explode( '=', $pair, 2 );
			$name  = self::unescape( $parts[0] );
			$value = self::unescape( $parts[1] ?? '' );

			if ( null === $name || null === $value ) {
				return null;
			}

			$params[ $name ][] = $value;
		}

		return $params;
	}

	private static function unescape( string $s ): ?string {
		return 1 === preg_match( '/%(?![0-9A-Fa-f]{2})/', $s ) ? null : urldecode( $s );
	}

	/**
	 * @param array<mixed> $server
	 */
	private static function serverString( array $server, string $key ): ?string {
		return isset( $server[ $key ] ) && is_string( $server[ $key ] ) ? $server[ $key ] : null;
	}

	/**
	 * Reads environment variables.
	 *
	 * @return Closure(string): (string|false)
	 */
	private function env(): Closure {
		return $this->env ?? static fn ( string $name ): string|false => getenv( $name );
	}

	private function now(): int {
		return ( $this->clock ?? time( ... ) )();
	}

	private function log( string $message ): void {
		( $this->log ?? self::errorLog( ... ) )( $message );
	}

	private static function errorLog( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}

	/**
	 * Sends a gated host's headers now and again just before PHP
	 * sends the response, so a later Cache-Control from WordPress or a
	 * plugin can't make CloudFront cache a preview page.
	 */
	private static function markPreview(): void {
		if ( headers_sent() ) {
			return;
		}

		$send = static function (): void {
			header( 'X-Robots-Tag: ' . self::ROBOTS );
			header( 'Cache-Control: ' . self::CACHE_CONTROL );
		};

		$send();
		header_register_callback( $send );
	}

	/**
	 * Sends a gate response and ends the request.
	 *
	 * @param list<array{0: string, 1: string}> $headers
	 */
	private static function emit( int $status, array $headers, string $body ): never {
		if ( ! headers_sent() ) {
			http_response_code( $status );

			foreach ( $headers as [ $name, $value ] ) {
				header( $name . ': ' . $value, 'Set-Cookie' !== $name );
			}
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed page; its one link is escaped.
		exit;
	}
}
