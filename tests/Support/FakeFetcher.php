<?php

declare(strict_types=1);

namespace VectorMU\Tests\Support;

use Closure;
use VectorMU\Gate\DocumentFetcher;
use VectorMU\Gate\GateException;

/**
 * A DocumentFetcher that answers from a closure and records its calls.
 */
final class FakeFetcher implements DocumentFetcher {

	/** @var list<array{url: string, token: string}> */
	public array $calls = [];

	/**
	 * @param Closure(): string $answer Returns the body or throws GateException.
	 */
	public function __construct( public Closure $answer ) {
	}

	/**
	 * Answers with a document, or fails when $document is null.
	 *
	 * @param array<string, mixed>|null $document
	 */
	public static function with( ?array $document ): self {
		return new self( self::answer( $document ) );
	}

	/**
	 * @param array<string, mixed>|null $document
	 * @return Closure(): string
	 */
	public static function answer( ?array $document ): Closure {
		return static function () use ( $document ): string {
			if ( null === $document ) {
				throw new GateException( 'document fetch failed: curl error 28 (Timeout was reached)' );
			}

			return (string) json_encode( $document );
		};
	}

	public function fetch( string $url, string $token ): string {
		$this->calls[] = [ 'url' => $url, 'token' => $token ];

		return ( $this->answer )();
	}
}
