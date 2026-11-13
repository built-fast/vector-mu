<?php

declare(strict_types=1);

namespace VectorMU\Modules;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use RuntimeException;
use VectorMU\Contracts\ModuleInterface;
use VectorMU\Mail\Sendmail;
use VectorMU\Mail\WpMailMessage;
use VectorMU\Runtime;
use VectorMU\Support\OpenBasedir;
use WP_Error;

/**
 * Captures a dev site's wp_mail() instead of sending it.
 *
 * On a dev host, mail goes to the host's capture binary instead of anyone's
 * inbox. PHP's sendmail_path already points there, which catches mail()
 * and core's default transport, but a mailer plugin (SMTP or an API) would
 * still send. So this module stops wp_mail() first, in pre_wp_mail at the
 * earliest priority: it builds the message as core would (WpMailMessage)
 * and pipes it to the capture binary (Sendmail). wp_mail() returns true
 * once the binary accepts it, and false otherwise, after wp_mail_failed;
 * nothing is ever sent through the mailer plugin's transport.
 *
 * It's on in the dev runtime, and wherever the capture binary exists, since
 * WP-CLI and cron on a dev host don't see the FPM pool's environment and
 * resolve to lambda. In production neither holds and it does nothing.
 */
final class DevMailCapture implements ModuleInterface {

	public const SENDMAIL = '/opt/vector/bin/vector-sendmail';

	/**
	 * @param string|null $runtime  The runtime; null reads VECTOR_MU_RUNTIME.
	 * @param string      $sendmail The capture binary.
	 */
	public function __construct(
		private readonly ?string $runtime = null,
		private readonly string $sendmail = self::SENDMAIL
	) {
	}

	public function register(): void {
		if ( ! self::isActive( $this->runtime ?? VECTOR_MU_RUNTIME, $this->sendmail ) ) {
			return;
		}

		// First, so no mailer plugin's pre_wp_mail sees the message before it's captured.
		add_filter( 'pre_wp_mail', [ $this, 'capture' ], PHP_INT_MIN, 2 );
	}

	/**
	 * Whether to capture: in the dev runtime, or where the capture binary
	 * exists.
	 */
	public static function isActive( string $runtime, string $sendmail ): bool {
		return Runtime::DEV === $runtime
			|| ( OpenBasedir::allows( $sendmail ) && is_file( $sendmail ) && is_executable( $sendmail ) );
	}

	/**
	 * The pre_wp_mail filter: captures the message and returns whether the
	 * capture binary accepted it. A value an earlier filter returned is
	 * passed through.
	 *
	 * @param mixed $result Null, or an earlier filter's result.
	 * @param mixed $atts   The wp_mail() arguments.
	 */
	public function capture( mixed $result, mixed $atts ): mixed {
		if ( null !== $result ) {
			return $result;
		}

		$message = new WpMailMessage( is_array( $atts ) ? $atts : [] );

		try {
			( new Sendmail( $this->sendmail ) )->send( $message->compose( $this->sendmail ) );
		} catch ( PHPMailerException $e ) {
			return self::failed( $message->data() + [ 'phpmailer_exception_code' => $e->getCode() ], $e->getMessage() );
		} catch ( RuntimeException $e ) {
			return self::failed( $message->data(), 'Could not capture mail: ' . $e->getMessage() );
		}

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $message->data() );

		return true;
	}

	/**
	 * Fires wp_mail_failed as core does, and returns false for wp_mail().
	 *
	 * @param array<string, mixed> $data The arguments, as core reports them.
	 */
	private static function failed( array $data, string $reason ): bool {
		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $reason, $data ) );

		return false;
	}
}
