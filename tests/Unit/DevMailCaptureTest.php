<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPMailer\PHPMailer\PHPMailer;
use VectorMU\Modules\DevMailCapture;
use VectorMU\Runtime;

beforeEach( function (): void {
	$this->dir = sys_get_temp_dir() . '/vector-mu-mail-' . bin2hex( random_bytes( 6 ) );
	mkdir( $this->dir, 0700 );

	// A capture binary that records its argv and stdin, then exits with the
	// code in its .exit file (0 without one), after printing its .stderr file.
	$this->sendmail = $this->dir . '/vector-sendmail';
	file_put_contents(
		$this->sendmail,
		"#!/bin/sh\n" .
		"printf '%s\\n' \"\$@\" > \"\$0.args\"\n" .
		"cat > \"\$0.eml\"\n" .
		"[ -f \"\$0.stderr\" ] && cat \"\$0.stderr\" >&2\n" .
		"[ -f \"\$0.exit\" ] && exit \"\$(cat \"\$0.exit\")\"\n" .
		"exit 0\n"
	);
	chmod( $this->sendmail, 0700 );

	$this->captured = fn (): string => (string) file_get_contents( $this->sendmail . '.eml' );

	Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );
	Functions\when( 'network_home_url' )->justReturn( 'https://www.example.test/' );
	Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	Functions\when( 'is_email' )->alias(
		static fn ( string $email ): string|false => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false
	);
} );

afterEach( function (): void {
	foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
		unlink( (string) $file );
	}

	rmdir( $this->dir );
} );

/**
 * The wp_mail() arguments as pre_wp_mail gets them.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function mailAtts( array $overrides = [] ): array {
	return $overrides + [
		'to'          => 'Ada <ada@example.test>, bob@example.test',
		'subject'     => 'Hello',
		'message'     => 'Body text',
		'headers'     => '',
		'attachments' => [],
		'embeds'      => [],
	];
}

it( 'registers pre_wp_mail first in the dev runtime', function (): void {
	$module = new DevMailCapture( Runtime::DEV, $this->dir . '/missing' );
	$module->register();

	expect( has_filter( 'pre_wp_mail', [ $module, 'capture' ] ) )->toBe( PHP_INT_MIN );
} );

it( 'registers where the capture binary exists, whatever the runtime', function (): void {
	$module = new DevMailCapture( Runtime::LAMBDA, $this->sendmail );
	$module->register();

	expect( has_filter( 'pre_wp_mail', [ $module, 'capture' ] ) )->toBe( PHP_INT_MIN );
} );

it( 'stays off in production', function (): void {
	$module = new DevMailCapture( Runtime::LAMBDA, $this->dir . '/missing' );
	$module->register();

	expect( has_filter( 'pre_wp_mail', [ $module, 'capture' ] ) )->toBeFalse();
} );

it( 'pipes the whole message to the capture binary and reports success', function (): void {
	file_put_contents( $this->dir . '/report.txt', 'attached bytes' );
	Actions\expectDone( 'wp_mail_succeeded' )->once();
	Actions\expectDone( 'wp_mail_failed' )->never();

	$result = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture(
		null,
		mailAtts( [
			'headers'     => "From: \"Site\" <site@example.test>\r\nCc: carol@example.test\r\nBcc: dave@example.test\r\nReply-To: help@example.test\r\nX-Custom: yes\r\nContent-Type: text/html; charset=UTF-8",
			'message'     => '<p>Héllo</p>',
			'attachments' => [ $this->dir . '/report.txt' ],
		] )
	);

	$eml = ( $this->captured )();

	expect( $result )->toBeTrue()
		->and( file( $this->sendmail . '.args', FILE_IGNORE_NEW_LINES ) )->toBe( [ '-t', '-i' ] )
		->and( $eml )->toContain( 'To: Ada <ada@example.test>, bob@example.test' )
		->and( $eml )->toContain( 'Cc: carol@example.test' )
		->and( $eml )->toContain( 'Bcc: dave@example.test' )
		->and( $eml )->toContain( 'Reply-To: help@example.test' )
		->and( $eml )->toContain( 'From: Site <site@example.test>' )
		->and( $eml )->toContain( 'Subject: Hello' )
		->and( $eml )->toContain( 'X-Custom: yes' )
		->and( $eml )->toMatch( '/^Message-ID: <[^>]+>$/m' )
		->and( $eml )->toContain( 'Content-Type: text/html; charset=UTF-8' )
		->and( $eml )->toContain( '<p>Héllo</p>' )
		->and( $eml )->toContain( 'filename=report.txt' )
		->and( $eml )->toContain( base64_encode( 'attached bytes' ) );
} );

it( 'uses core\'s default sender and runs the sender filters', function (): void {
	Filters\expectApplied( 'wp_mail_from' )->once()->with( 'wordpress@example.test' )->andReturn( 'noreply@example.test' );
	Filters\expectApplied( 'wp_mail_from_name' )->once()->with( 'WordPress' )->andReturn( 'Example' );
	Filters\expectApplied( 'wp_mail_content_type' )->once()->with( 'text/plain' )->andReturn( 'text/plain' );
	Filters\expectApplied( 'wp_mail_charset' )->once()->with( 'UTF-8' )->andReturn( 'UTF-8' );

	$result = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture( null, mailAtts() );

	expect( $result )->toBeTrue()
		->and( ( $this->captured )() )->toContain( 'From: Example <noreply@example.test>' );
} );

it( 'captures even when an SMTP mailer plugin configures PHPMailer', function (): void {
	// What an SMTP mailer plugin does in phpmailer_init: switch to SMTP at a
	// host that never answers (TEST-NET-1), and force its own sender.
	Actions\expectDone( 'phpmailer_init' )->once()->whenHappen(
		static function ( PHPMailer $mailer ): void {
			$mailer->isSMTP();
			$mailer->Host     = '192.0.2.1';
			$mailer->Port     = 587;
			$mailer->SMTPAuth = true;
			$mailer->Timeout  = 1;
			$mailer->setFrom( 'smtp-user@example.test', 'SMTP Sender' );
		}
	);

	$started = microtime( true );
	$result  = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture( null, mailAtts() );

	expect( $result )->toBeTrue()
		->and( microtime( true ) - $started )->toBeLessThan( 1.0 )
		->and( ( $this->captured )() )->toContain( 'From: SMTP Sender <smtp-user@example.test>' )
		->and( ( $this->captured )() )->toContain( 'To: Ada <ada@example.test>, bob@example.test' );
} );

it( 'fails with wp_mail_failed when the capture binary refuses the message', function (): void {
	file_put_contents( $this->sendmail . '.exit', '75' );
	file_put_contents( $this->sendmail . '.stderr', 'vector-sendmail: spool_full' );
	$error = null;
	Actions\expectDone( 'wp_mail_failed' )->once()->whenHappen(
		static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e;
		}
	);
	Actions\expectDone( 'wp_mail_succeeded' )->never();

	$result = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture( null, mailAtts() );

	expect( $result )->toBeFalse()
		->and( $error?->get_error_code() )->toBe( 'wp_mail_failed' )
		->and( $error?->get_error_message() )->toContain( 'exited 75' )
		->and( $error?->get_error_message() )->toContain( 'spool_full' )
		->and( $error?->get_error_data()['subject'] )->toBe( 'Hello' );
} );

it( 'fails when the capture binary is missing', function (): void {
	Actions\expectDone( 'wp_mail_failed' )->once();

	$result = ( new DevMailCapture( Runtime::DEV, $this->dir . '/missing' ) )->capture( null, mailAtts() );

	expect( $result )->toBeFalse();
} );

it( 'fails like core when there is no valid recipient', function (): void {
	$error = null;
	Actions\expectDone( 'wp_mail_failed' )->once()->whenHappen(
		static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e;
		}
	);

	$result = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture( null, mailAtts( [ 'to' => 'not an address' ] ) );

	expect( $result )->toBeFalse()
		->and( $error?->get_error_data() )->toHaveKey( 'phpmailer_exception_code' )
		->and( is_file( $this->sendmail . '.eml' ) )->toBeFalse();
} );

it( 'passes through an earlier filter\'s result', function ( bool $earlier ): void {
	$result = ( new DevMailCapture( Runtime::DEV, $this->sendmail ) )->capture( $earlier, mailAtts() );

	expect( $result )->toBe( $earlier )
		->and( is_file( $this->sendmail . '.eml' ) )->toBeFalse();
} )->with( [ 'true' => true, 'false' => false ] );
