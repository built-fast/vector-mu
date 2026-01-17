<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Modules\PluginBlocker;

beforeEach( function () {
    $this->blocker = new PluginBlocker();
} );

describe( 'filterActivePlugins', function () {
    it( 'removes blocked plugins from active plugins list', function () {
        $plugins = [
            'hello-dolly/hello.php',
            'wp-rocket/wp-rocket.php',
            'akismet/akismet.php',
        ];

        $result = $this->blocker->filterActivePlugins( $plugins );

        expect( $result )->toBe( [
            'hello-dolly/hello.php',
            'akismet/akismet.php',
        ] );
    } );

    it( 'returns original value if not an array', function () {
        expect( $this->blocker->filterActivePlugins( false ) )->toBeFalse();
        expect( $this->blocker->filterActivePlugins( null ) )->toBeNull();
    } );

    it( 'returns empty array when all plugins blocked', function () {
        $plugins = [ 'wp-rocket/wp-rocket.php' ];

        $result = $this->blocker->filterActivePlugins( $plugins );

        expect( $result )->toBe( [] );
    } );

    it( 'reindexes array after filtering', function () {
        $plugins = [
            'wp-rocket/wp-rocket.php',
            'akismet/akismet.php',
        ];

        $result = $this->blocker->filterActivePlugins( $plugins );

        expect( array_keys( $result ) )->toBe( [ 0 ] );
    } );
} );
