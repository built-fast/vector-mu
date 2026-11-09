<?php

declare(strict_types=1);

use Brain\Monkey;

uses()
    ->beforeEach( fn () => Monkey\setUp() )
    ->afterEach( fn () => Monkey\tearDown() )
    ->in( 'Unit' );
