<?php

declare(strict_types=1);

namespace VectorMU\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Stringable;
use WP_PHPMailer;

/**
 * Builds the MIME message core's wp_mail() would send, without sending it.
 *
 * This follows wp_mail() from the point a pre_wp_mail filter can stop it: the
 * same parsing of the to, headers, attachments, and embeds arguments, the
 * same wp_mail_from, wp_mail_from_name, wp_mail_content_type,
 * wp_mail_charset, and wp_mail_embed_args filters, and the phpmailer_init
 * action, on a fresh instance of WordPress's PHPMailer rather than the
 * global one, which a mailer plugin may have replaced. After phpmailer_init
 * the transport is set back to sendmail, so whatever a mailer plugin set
 * there (SMTP, an API) is never used, and the headers include Bcc, as a
 * sendmail -t transport reads them.
 */
final class WpMailMessage {

	/** @var array<string, mixed> The arguments, as wp_mail_succeeded and wp_mail_failed report them. */
	private array $data = [];

	/**
	 * @param array<mixed> $atts The wp_mail() arguments after the wp_mail filter, as pre_wp_mail gets them.
	 */
	public function __construct(
		private readonly array $atts
	) {
	}

	/**
	 * The arguments as core's wp_mail_succeeded and wp_mail_failed report
	 * them; complete once compose() has returned or thrown.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Builds the message and returns it as sendmail would get it on stdin.
	 *
	 * @param string $sendmail The capture binary, set as the transport's path.
	 * @throws PHPMailerException When PHPMailer refuses the sender, or the
	 *                            message can't be built (no recipients, an
	 *                            empty body, a bad encoding).
	 */
	public function compose( string $sendmail ): string {
		$atts        = $this->atts;
		$to          = self::addressList( $atts['to'] ?? [] );
		$subject     = self::stringValue( $atts['subject'] ?? '' );
		$message     = self::stringValue( $atts['message'] ?? '' );
		$attachments = self::fileList( $atts['attachments'] ?? [] );
		$embeds      = self::fileList( $atts['embeds'] ?? [] );
		$parsed      = MailHeaders::parse( $atts['headers'] ?? [] );
		$headers     = $parsed->custom;

		$this->data = compact( 'to', 'subject', 'message', 'headers', 'attachments' );

		$mailer           = self::newMailer();
		$mailer->Encoding = PHPMailer::ENCODING_8BIT;

		$fromName  = $parsed->fromName ?? 'WordPress';
		$fromEmail = $parsed->fromEmail ?? self::defaultFromEmail();

		/** This filter is documented in wp-includes/pluggable.php */
		$fromEmail = self::stringValue( apply_filters( 'wp_mail_from', $fromEmail ) );
		/** This filter is documented in wp-includes/pluggable.php */
		$fromName = self::stringValue( apply_filters( 'wp_mail_from_name', $fromName ) );

		$mailer->setFrom( $fromEmail, $fromName, false );

		$mailer->Subject = $subject;
		$mailer->Body    = $message;

		$recipients = [
			'to'       => $to,
			'cc'       => $parsed->cc,
			'bcc'      => $parsed->bcc,
			'reply_to' => $parsed->replyTo,
		];

		foreach ( $recipients as $kind => $addresses ) {
			foreach ( $addresses as $address ) {
				self::addRecipient( $mailer, $kind, $address );
			}
		}

		$mailer->isMail();

		/** This filter is documented in wp-includes/pluggable.php */
		$contentType = self::stringValue( apply_filters( 'wp_mail_content_type', $parsed->contentType ?? 'text/plain' ) );

		$mailer->ContentType = $contentType;

		if ( 'text/html' === $contentType ) {
			$mailer->isHTML( true );
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$mailer->CharSet = self::stringValue( apply_filters( 'wp_mail_charset', $parsed->charset ?? get_bloginfo( 'charset' ) ) );

		foreach ( $headers as $name => $content ) {
			if ( ! in_array( $name, [ 'MIME-Version', 'X-Mailer' ], true ) ) {
				try {
					$mailer->addCustomHeader( sprintf( '%1$s: %2$s', $name, $content ) );
				} catch ( PHPMailerException ) {
					continue;
				}
			}
		}

		foreach ( $attachments as $filename => $attachment ) {
			try {
				$mailer->addAttachment( $attachment, is_string( $filename ) ? $filename : '' );
			} catch ( PHPMailerException ) {
				continue;
			}
		}

		foreach ( $embeds as $key => $embedPath ) {
			self::addEmbed( $mailer, (string) $key, $embedPath );
		}

		$this->data = compact( 'to', 'subject', 'message', 'headers', 'attachments', 'embeds' );

		/** This action is documented in wp-includes/pluggable.php */
		do_action_ref_array( 'phpmailer_init', [ &$mailer ] );

		// Whatever transport phpmailer_init chose, the message goes to sendmail.
		$mailer->Mailer   = 'sendmail';
		$mailer->Sendmail = $sendmail;

		if ( ! $mailer->preSend() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reported through WP_Error, not printed.
			throw new PHPMailerException( $mailer->ErrorInfo );
		}

		return $mailer->getSentMIMEMessage();
	}

	/**
	 * A new WordPress PHPMailer that throws, with core's address validator.
	 */
	private static function newMailer(): PHPMailer {
		if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			if ( ! class_exists( PHPMailer::class ) ) {
				require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
				require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
				require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
			}

			// WordPress 6.8 and later translate PHPMailer's errors.
			if ( ! class_exists( WP_PHPMailer::class ) && file_exists( ABSPATH . WPINC . '/class-wp-phpmailer.php' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
			}
		}

		$mailer = class_exists( WP_PHPMailer::class ) ? new WP_PHPMailer( true ) : new PHPMailer( true );

		$mailer::$validator = static function ( $email ): bool {
			return (bool) is_email( $email );
		};

		return $mailer;
	}

	/**
	 * Core's default sender: wordpress@ the network home's host, less www.
	 */
	private static function defaultFromEmail(): string {
		$sitename  = wp_parse_url( network_home_url(), PHP_URL_HOST );
		$fromEmail = 'wordpress@';

		if ( is_string( $sitename ) ) {
			if ( str_starts_with( $sitename, 'www.' ) ) {
				$sitename = substr( $sitename, 4 );
			}

			$fromEmail .= $sitename;
		}

		return $fromEmail;
	}

	/**
	 * Adds one address, splitting "Name <address>", and skips one PHPMailer
	 * refuses, as core does.
	 */
	private static function addRecipient( PHPMailer $mailer, string $kind, string $address ): void {
		$name = '';

		if ( 1 === preg_match( '/(.*)<(.+)>/', $address, $matches ) ) {
			$name    = $matches[1];
			$address = $matches[2];
		}

		try {
			match ( $kind ) {
				'to'       => $mailer->addAddress( $address, $name ),
				'cc'       => $mailer->addCC( $address, $name ),
				'bcc'      => $mailer->addBCC( $address, $name ),
				'reply_to' => $mailer->addReplyTo( $address, $name ),
				default    => false,
			};
		} catch ( PHPMailerException ) {
			return;
		}
	}

	private static function addEmbed( PHPMailer $mailer, string $cid, string $path ): void {
		/** This filter is documented in wp-includes/pluggable.php */
		$args = apply_filters(
			'wp_mail_embed_args',
			[
				'path'        => $path,
				'cid'         => $cid,
				'name'        => basename( $path ),
				'encoding'    => 'base64',
				'type'        => '',
				'disposition' => 'inline',
			]
		);

		if ( ! is_array( $args ) ) {
			return;
		}

		try {
			$mailer->addEmbeddedImage(
				self::stringValue( $args['path'] ?? '' ),
				self::stringValue( $args['cid'] ?? '' ),
				self::stringValue( $args['name'] ?? '' ),
				self::stringValue( $args['encoding'] ?? 'base64' ),
				self::stringValue( $args['type'] ?? '' ),
				self::stringValue( $args['disposition'] ?? 'inline' )
			);
		} catch ( PHPMailerException ) {
			return;
		}
	}

	/**
	 * The to argument as a list: an array as is, or a comma-separated string.
	 *
	 * @return list<string>
	 */
	private static function addressList( mixed $value ): array {
		$list = is_array( $value ) ? $value : explode( ',', self::stringValue( $value ) );

		return array_values( array_map( [ self::class, 'stringValue' ], $list ) );
	}

	/**
	 * The attachments or embeds argument: an array as is (keys kept, as they
	 * name the file or the cid), or a newline-separated string.
	 *
	 * @return array<array-key, string>
	 */
	private static function fileList( mixed $value ): array {
		if ( empty( $value ) ) {
			return [];
		}

		$list = is_array( $value ) ? $value : explode( "\n", str_replace( "\r\n", "\n", self::stringValue( $value ) ) );

		return array_map( [ self::class, 'stringValue' ], $list );
	}

	private static function stringValue( mixed $value ): string {
		return is_scalar( $value ) || $value instanceof Stringable ? (string) $value : '';
	}
}
