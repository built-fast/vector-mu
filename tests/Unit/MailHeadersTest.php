<?php

declare(strict_types=1);

use VectorMU\Mail\MailHeaders;

it( 'parses a header string as core does', function (): void {
	$headers = MailHeaders::parse(
		"From: \"Site Name\" <site@example.test>\r\n" .
		"Cc: a@example.test, b@example.test\r\n" .
		"cc: c@example.test\r\n" .
		"Bcc: d@example.test\r\n" .
		"Reply-To: e@example.test\r\n" .
		"Content-Type: text/html; charset=\"ISO-8859-1\"\r\n" .
		"X-Thing: one\r\n" .
		"X-Thing: two\r\n" .
		"no colon here\r\n"
	);

	expect( $headers->fromName )->toBe( 'Site Name' )
		->and( $headers->fromEmail )->toBe( 'site@example.test' )
		->and( $headers->cc )->toBe( [ 'a@example.test', ' b@example.test', 'c@example.test' ] )
		->and( $headers->bcc )->toBe( [ 'd@example.test' ] )
		->and( $headers->replyTo )->toBe( [ 'e@example.test' ] )
		->and( $headers->contentType )->toBe( 'text/html' )
		->and( $headers->charset )->toBe( 'ISO-8859-1' )
		->and( $headers->custom )->toBe( [ 'X-Thing' => 'two' ] );
} );

it( 'parses a header array', function (): void {
	$headers = MailHeaders::parse( [ 'From: site@example.test', 'X-A: 1' ] );

	expect( $headers->fromName )->toBeNull()
		->and( $headers->fromEmail )->toBe( 'site@example.test' )
		->and( $headers->custom )->toBe( [ 'X-A' => '1' ] );
} );

it( 'keeps a multipart boundary with an empty charset', function (): void {
	$headers = MailHeaders::parse( 'Content-Type: Multipart/Alternative; boundary="abc123"' );

	expect( $headers->contentType )->toBe( 'Multipart/Alternative' )
		->and( $headers->charset )->toBe( '' );

	$headers = MailHeaders::parse( 'Content-Type: multipart/Alternative; boundary="abc123"' );

	expect( $headers->contentType )->toBe( 'multipart/alternative; boundary="abc123"' );
} );

it( 'leaves empty values unset', function ( mixed $raw ): void {
	$headers = MailHeaders::parse( $raw );

	expect( $headers->fromEmail )->toBeNull()
		->and( $headers->contentType )->toBeNull()
		->and( $headers->custom )->toBe( [] );
} )->with( [
	'empty string' => '',
	'empty array'  => [ [] ],
	'empty from'   => 'From: ',
	'empty type'   => 'Content-Type:',
] );
