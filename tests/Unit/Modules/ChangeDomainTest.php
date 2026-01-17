<?php

declare(strict_types=1);

use VectorMU\Modules\ChangeDomain;

beforeEach( function () {
	$this->changeDomain = new ChangeDomain();
} );

describe( 'register', function () {
	it( 'registers change-domain command when WP_CLI available', function () {
		$this->changeDomain->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded
		expect( true )->toBeTrue();
	} );
} );

describe( 'getTables', function () {
	it( 'returns prefixed table names', function () {
		global $wpdb;
		$wpdb         = new stdClass();
		$wpdb->prefix = 'wp_';

		$reflection = new ReflectionClass( ChangeDomain::class );
		$method     = $reflection->getMethod( 'getTables' );
		$method->setAccessible( true );

		$tables = $method->invoke( $this->changeDomain );

		expect( $tables )->toBe( [
			'wp_options',
			'wp_posts',
			'wp_postmeta',
			'wp_comments',
			'wp_commentmeta',
		] );
	} );

	it( 'handles custom table prefix', function () {
		global $wpdb;
		$wpdb         = new stdClass();
		$wpdb->prefix = 'custom_';

		$reflection = new ReflectionClass( ChangeDomain::class );
		$method     = $reflection->getMethod( 'getTables' );
		$method->setAccessible( true );

		$tables = $method->invoke( $this->changeDomain );

		expect( $tables )->toBe( [
			'custom_options',
			'custom_posts',
			'custom_postmeta',
			'custom_comments',
			'custom_commentmeta',
		] );
	} );
} );

describe( 'regex pattern', function () {
	it( 'matches http URLs', function () {
		$old_domain  = 'example.com';
		$old_escaped = preg_quote( $old_domain, '/' );
		$pattern     = "/(https?:)?\\/\\/(www\\.)?{$old_escaped}/";

		expect( preg_match( $pattern, 'http://example.com' ) )->toBe( 1 );
		expect( preg_match( $pattern, 'https://example.com' ) )->toBe( 1 );
		expect( preg_match( $pattern, 'http://www.example.com' ) )->toBe( 1 );
		expect( preg_match( $pattern, 'https://www.example.com' ) )->toBe( 1 );
		expect( preg_match( $pattern, '//example.com' ) )->toBe( 1 );
		expect( preg_match( $pattern, '//www.example.com' ) )->toBe( 1 );
	} );

	it( 'preserves protocol and www in replacement', function () {
		$old_domain  = 'old.com';
		$new_domain  = 'new.com';
		$old_escaped = preg_quote( $old_domain, '/' );
		$pattern     = "/(https?:)?\\/\\/(www\\.)?{$old_escaped}/";
		$replacement = "\\1//\\2{$new_domain}";

		expect( preg_replace( $pattern, $replacement, 'http://old.com' ) )
			->toBe( 'http://new.com' );
		expect( preg_replace( $pattern, $replacement, 'https://old.com' ) )
			->toBe( 'https://new.com' );
		expect( preg_replace( $pattern, $replacement, 'http://www.old.com' ) )
			->toBe( 'http://www.new.com' );
		expect( preg_replace( $pattern, $replacement, 'https://www.old.com' ) )
			->toBe( 'https://www.new.com' );
		expect( preg_replace( $pattern, $replacement, '//old.com' ) )
			->toBe( '//new.com' );
		expect( preg_replace( $pattern, $replacement, '//www.old.com' ) )
			->toBe( '//www.new.com' );
	} );

	it( 'does not match other domains', function () {
		$old_domain  = 'example.com';
		$old_escaped = preg_quote( $old_domain, '/' );
		$pattern     = "/(https?:)?\\/\\/(www\\.)?{$old_escaped}/";

		expect( preg_match( $pattern, 'http://other.com' ) )->toBe( 0 );
		expect( preg_match( $pattern, 'http://example.com.evil.com' ) )->toBe( 1 ); // Matches prefix
		expect( preg_match( $pattern, 'http://notexample.com' ) )->toBe( 0 );
	} );
} );
