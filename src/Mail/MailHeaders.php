<?php

declare(strict_types=1);

namespace VectorMU\Mail;

use Stringable;

/**
 * The headers argument of wp_mail(), parsed as core parses it: From,
 * Content-Type (with its charset), Cc, Bcc, and Reply-To are taken out, and
 * the rest are kept by name, a later one replacing an earlier one. Lines
 * without a colon are skipped.
 */
final class MailHeaders {

	/** @var array<string, string> Other headers, by name. */
	public array $custom = [];

	/** @var list<string> */
	public array $cc = [];

	/** @var list<string> */
	public array $bcc = [];

	/** @var list<string> */
	public array $replyTo = [];

	public ?string $fromName = null;

	public ?string $fromEmail = null;

	public ?string $contentType = null;

	public ?string $charset = null;

	/**
	 * Parses an array of header lines or a newline-separated string.
	 */
	public static function parse( mixed $raw ): self {
		$parsed = new self();

		if ( empty( $raw ) ) {
			return $parsed;
		}

		$lines = is_array( $raw ) ? $raw : explode( "\n", str_replace( "\r\n", "\n", self::text( $raw ) ) );

		foreach ( $lines as $line ) {
			$line = self::text( $line );

			if ( ! str_contains( $line, ':' ) ) {
				continue;
			}

			[ $name, $content ] = explode( ':', trim( $line ), 2 );
			$parsed->add( trim( $name ), trim( $content ) );
		}

		return $parsed;
	}

	private function add( string $name, string $content ): void {
		switch ( strtolower( $name ) ) {
			case 'from':
				$this->setFrom( $content );
				break;
			case 'content-type':
				$this->setContentType( $content );
				break;
			case 'cc':
				$this->cc = array_merge( $this->cc, explode( ',', $content ) );
				break;
			case 'bcc':
				$this->bcc = array_merge( $this->bcc, explode( ',', $content ) );
				break;
			case 'reply-to':
				$this->replyTo = array_merge( $this->replyTo, explode( ',', $content ) );
				break;
			default:
				$this->custom[ $name ] = $content;
				break;
		}
	}

	/**
	 * "Name <address>" (quotes dropped from the name), "<address>", or a bare
	 * address. An empty value leaves the sender unset.
	 */
	private function setFrom( string $content ): void {
		$bracket = strpos( $content, '<' );

		if ( false === $bracket ) {
			if ( '' !== $content ) {
				$this->fromEmail = $content;
			}

			return;
		}

		if ( $bracket > 0 ) {
			$this->fromName = trim( str_replace( '"', '', substr( $content, 0, $bracket ) ) );
		}

		$this->fromEmail = trim( str_replace( '>', '', substr( $content, $bracket + 1 ) ) );
	}

	/**
	 * A charset parameter sets the charset; a boundary parameter keeps a
	 * multipart type with that boundary and an empty charset; anything after
	 * a second parameter is dropped.
	 */
	private function setContentType( string $content ): void {
		if ( ! str_contains( $content, ';' ) ) {
			if ( '' !== $content ) {
				$this->contentType = $content;
			}

			return;
		}

		[ $type, $params ] = explode( ';', $content );
		$this->contentType = trim( $type );

		if ( false !== stripos( $params, 'charset=' ) ) {
			$this->charset = trim( str_replace( [ 'charset=', '"' ], '', $params ) );
		} elseif ( false !== stripos( $params, 'boundary=' ) ) {
			$boundary      = trim( str_replace( [ 'BOUNDARY=', 'boundary=', '"' ], '', $params ) );
			$this->charset = '';

			if ( 1 === preg_match( '~^multipart/(\S+)~', $this->contentType, $matches ) ) {
				$this->contentType = 'multipart/' . strtolower( $matches[1] ) . '; boundary="' . $boundary . '"';
			}
		}
	}

	private static function text( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
	}
}
