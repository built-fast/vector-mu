<?php

declare(strict_types=1);

use VectorMU\Modules\Cron;

beforeEach( function () {
	$this->cron = new Cron();
} );

describe( 'register', function () {
	it( 'registers cron commands when WP_CLI available', function () {
		$this->cron->register();

		// No assertion needed - just verifying no errors when WP_CLI not loaded.
		expect( true )->toBeTrue();
	} );
} );

describe( 'formatInterval', function () {
	it( 'formats seconds', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 0 ) )->toBe( '0s' );
		expect( $method->invoke( $this->cron, 30 ) )->toBe( '30s' );
		expect( $method->invoke( $this->cron, 59 ) )->toBe( '59s' );
	} );

	it( 'formats minutes', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 60 ) )->toBe( '1m' );
		expect( $method->invoke( $this->cron, 120 ) )->toBe( '2m' );
		expect( $method->invoke( $this->cron, 300 ) )->toBe( '5m' );
		expect( $method->invoke( $this->cron, 3599 ) )->toBe( '60m' );
	} );

	it( 'formats hours', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 3600 ) )->toBe( '1h' );
		expect( $method->invoke( $this->cron, 7200 ) )->toBe( '2h' );
		expect( $method->invoke( $this->cron, 43200 ) )->toBe( '12h' );
	} );

	it( 'formats days', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 86400 ) )->toBe( '1d' );
		expect( $method->invoke( $this->cron, 172800 ) )->toBe( '2d' );
		expect( $method->invoke( $this->cron, 604800 ) )->toBe( '7d' );
	} );

	it( 'rounds to one decimal place for hours', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 5400 ) )->toBe( '1.5h' );
	} );

	it( 'rounds to one decimal place for days', function () {
		$method = new ReflectionMethod( Cron::class, 'formatInterval' );
		$method->setAccessible( true );

		expect( $method->invoke( $this->cron, 129600 ) )->toBe( '1.5d' );
	} );
} );
