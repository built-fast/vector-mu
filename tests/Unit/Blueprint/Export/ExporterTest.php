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
			skipPlugins: true,
			skipThemes: false,
			excludeTables: [ 'wp_sessions' ],
		);

		expect( $options->outputPath )->toBe( '/path/to/output.tar.gz' );
		expect( $options->sourceUrl )->toBe( 'https://example.com' );
		expect( $options->dbPrefix )->toBe( 'wp_' );
		expect( $options->includeUploads )->toBeTrue();
		expect( $options->maxUploadSize )->toBe( 104857600 );
		expect( $options->skipPlugins )->toBeTrue();
		expect( $options->skipThemes )->toBeFalse();
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
		expect( $options->skipPlugins )->toBeFalse();
		expect( $options->skipThemes )->toBeFalse();
		expect( $options->excludeTables )->toBe( [] );
	} );
} );
