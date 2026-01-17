<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\UserImpersonate;

beforeEach( function () {
    $this->impersonate = new UserImpersonate();
} );

describe( 'register', function () {
    it( 'registers init action for token handling', function () {
        $this->impersonate->register();

        expect( has_action( 'init', [ $this->impersonate, 'handleToken' ] ) )->toBe( 10 );
    } );
} );

describe( 'handleToken', function () {
    beforeEach( function () {
        global $pagenow;
        $pagenow  = null;
        $_GET     = [];
    } );

    it( 'returns early if not on wp-login.php', function () {
        global $pagenow;
        $pagenow = 'index.php';

        Functions\expect( 'get_user_by' )->never();

        $this->impersonate->handleToken();

        expect( true )->toBeTrue(); // Confirms we returned early
    } );

    it( 'returns early if user_id or token missing', function () {
        global $pagenow;
        $pagenow = 'wp-login.php';

        Functions\expect( 'get_user_by' )->never();

        $this->impersonate->handleToken();

        expect( true )->toBeTrue();
    } );
} );
