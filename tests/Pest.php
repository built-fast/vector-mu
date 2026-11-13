<?php

declare(strict_types=1);

use Brain\Monkey;

require_once __DIR__ . '/stubs/WP_Error.php';

uses()
    ->beforeEach( fn () => Monkey\setUp() )
    ->afterEach( fn () => Monkey\tearDown() )
    ->in( 'Unit' );
