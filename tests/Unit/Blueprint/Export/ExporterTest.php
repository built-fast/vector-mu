<?php

declare(strict_types=1);

use VectorMU\Blueprint\Export\ExportOptions;

describe( 'ExportOptions', function () {
	it( 'stores all options', function () {
		$options = new ExportOptions(
			outputPath: '/path/to/output.tar.gz',
			sourceUrl: 'https://example.com',
			dbPrefix: 'wp_',
			includeUploads: true,
			maxUploadSize: 104857600,
			includePlugins: [ 'plugin-a', 'plugin-b' ],
			includeThemes: [ 'theme-a' ],
			excludeTables: [ 'wp_sessions' ],
		);

		expect( $options->outputPath )->toBe( '/path/to/output.tar.gz' );
		expect( $options->sourceUrl )->toBe( 'https://example.com' );
		expect( $options->dbPrefix )->toBe( 'wp_' );
		expect( $options->includeUploads )->toBeTrue();
		expect( $options->maxUploadSize )->toBe( 104857600 );
		expect( $options->includePlugins )->toBe( [ 'plugin-a', 'plugin-b' ] );
		expect( $options->includeThemes )->toBe( [ 'theme-a' ] );
		expect( $options->excludeTables )->toBe( [ 'wp_sessions' ] );
	} );

	it( 'has sensible defaults', function () {
		$options = new ExportOptions(
			outputPath: '/path/to/output.tar.gz',
			sourceUrl: 'https://example.com',
			dbPrefix: 'wp_',
		);

		expect( $options->includeUploads )->toBeFalse();
		expect( $options->maxUploadSize )->toBeNull();
		expect( $options->includePlugins )->toBe( [] );
		expect( $options->includeThemes )->toBe( [] );
		expect( $options->excludeTables )->toBe( [] );
	} );
} );
