<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Blueprint\Metadata;

describe( 'fromJson', function () {
	it( 'parses valid JSON', function () {
		$json = json_encode( [
			'version'           => '1.0',
			'created_at'        => '2024-01-15T10:30:00+00:00',
			'vector_mu_version' => '1.0.0',
			'source'            => [
				'url'               => 'https://example.com',
				'wp_version'        => '6.4.2',
				'php_version'       => '8.1.27',
				'db_prefix'         => 'wp_',
				'installed_plugins' => [
					'my-plugin' => [ 'version' => '1.0.0', 'active' => true ],
				],
				'installed_themes'  => [
					'my-theme' => [ 'version' => '2.0.0', 'active' => true ],
				],
			],
			'contents'          => [
				'database' => true,
				'uploads'  => false,
				'plugins'  => true,
				'themes'   => true,
			],
		] );

		$metadata = Metadata::fromJson( $json );

		expect( $metadata->version )->toBe( '1.0' );
		expect( $metadata->sourceUrl )->toBe( 'https://example.com' );
		expect( $metadata->wpVersion )->toBe( '6.4.2' );
		expect( $metadata->phpVersion )->toBe( '8.1.27' );
		expect( $metadata->dbPrefix )->toBe( 'wp_' );
		expect( $metadata->hasDatabase )->toBeTrue();
		expect( $metadata->hasUploads )->toBeFalse();
		expect( $metadata->hasPlugins )->toBeTrue();
		expect( $metadata->hasThemes )->toBeTrue();
		expect( $metadata->installedPlugins )->toBe( [
			'my-plugin' => [ 'version' => '1.0.0', 'active' => true ],
		] );
		expect( $metadata->installedThemes )->toBe( [
			'my-theme' => [ 'version' => '2.0.0', 'active' => true ],
		] );
	} );

	it( 'handles missing installed_plugins and installed_themes gracefully', function () {
		$json = json_encode( [
			'version'           => '1.0',
			'created_at'        => '2024-01-15T10:30:00+00:00',
			'vector_mu_version' => '1.0.0',
			'source'            => [
				'url'         => 'https://example.com',
				'wp_version'  => '6.4.2',
				'php_version' => '8.1.27',
				'db_prefix'   => 'wp_',
			],
			'contents'          => [
				'database' => true,
				'uploads'  => false,
				'plugins'  => true,
				'themes'   => true,
			],
		] );

		$metadata = Metadata::fromJson( $json );

		expect( $metadata->installedPlugins )->toBe( [] );
		expect( $metadata->installedThemes )->toBe( [] );
	} );

	it( 'throws on missing required key', function () {
		$json = json_encode( [
			'version' => '1.0',
		] );

		expect( fn() => Metadata::fromJson( $json ) )
			->toThrow( BlueprintException::class );
	} );

	it( 'throws on missing source key', function () {
		$json = json_encode( [
			'version'           => '1.0',
			'created_at'        => '2024-01-15T10:30:00+00:00',
			'vector_mu_version' => '1.0.0',
			'source'            => [
				'url' => 'https://example.com',
			],
			'contents'          => [
				'database' => true,
				'uploads'  => false,
				'plugins'  => true,
				'themes'   => true,
			],
		] );

		expect( fn() => Metadata::fromJson( $json ) )
			->toThrow( BlueprintException::class );
	} );

	it( 'throws on invalid created_at format', function () {
		$json = json_encode( [
			'version'           => '1.0',
			'created_at'        => 'invalid-date',
			'vector_mu_version' => '1.0.0',
			'source'            => [
				'url'         => 'https://example.com',
				'wp_version'  => '6.4.2',
				'php_version' => '8.1.27',
				'db_prefix'   => 'wp_',
			],
			'contents'          => [
				'database' => true,
				'uploads'  => false,
				'plugins'  => true,
				'themes'   => true,
			],
		] );

		expect( fn() => Metadata::fromJson( $json ) )
			->toThrow( BlueprintException::class );
	} );
} );

describe( 'toJson', function () {
	it( 'serializes to valid JSON', function () {
		Functions\when( 'get_bloginfo' )->justReturn( '6.4.2' );

		$metadata = new Metadata(
			version: '1.0',
			createdAt: new DateTimeImmutable( '2024-01-15T10:30:00+00:00' ),
			vectorMuVersion: '1.0.0',
			sourceUrl: 'https://example.com',
			wpVersion: '6.4.2',
			phpVersion: '8.1.27',
			dbPrefix: 'wp_',
			hasDatabase: true,
			hasUploads: false,
			hasPlugins: true,
			hasThemes: true,
			installedPlugins: [ 'my-plugin' => [ 'version' => '1.0.0', 'active' => true ] ],
			installedThemes: [ 'my-theme' => [ 'version' => '2.0.0', 'active' => false ] ],
		);

		$json = $metadata->toJson();
		$data = json_decode( $json, true );

		expect( $data['version'] )->toBe( '1.0' );
		expect( $data['source']['url'] )->toBe( 'https://example.com' );
		expect( $data['source']['installed_plugins'] )->toBe( [
			'my-plugin' => [ 'version' => '1.0.0', 'active' => true ],
		] );
		expect( $data['contents']['database'] )->toBeTrue();
		expect( $data['contents']['uploads'] )->toBeFalse();
		expect( $data['contents']['plugins'] )->toBeTrue();
	} );
} );

describe( 'jsonSerialize', function () {
	it( 'returns array with expected structure', function () {
		$metadata = new Metadata(
			version: '1.0',
			createdAt: new DateTimeImmutable( '2024-01-15T10:30:00+00:00' ),
			vectorMuVersion: '1.0.0',
			sourceUrl: 'https://example.com',
			wpVersion: '6.4.2',
			phpVersion: '8.1.27',
			dbPrefix: 'wp_',
			hasDatabase: true,
			hasUploads: true,
			hasPlugins: true,
			hasThemes: false,
			installedPlugins: [],
			installedThemes: [],
		);

		$array = $metadata->jsonSerialize();

		expect( $array )->toHaveKey( 'version' );
		expect( $array )->toHaveKey( 'created_at' );
		expect( $array )->toHaveKey( 'vector_mu_version' );
		expect( $array )->toHaveKey( 'source' );
		expect( $array )->toHaveKey( 'contents' );
		expect( $array['source'] )->toHaveKey( 'url' );
		expect( $array['source'] )->toHaveKey( 'wp_version' );
		expect( $array['source'] )->toHaveKey( 'php_version' );
		expect( $array['source'] )->toHaveKey( 'db_prefix' );
		expect( $array['source'] )->toHaveKey( 'installed_plugins' );
		expect( $array['source'] )->toHaveKey( 'installed_themes' );
		expect( $array['contents'] )->toHaveKey( 'database' );
		expect( $array['contents'] )->toHaveKey( 'uploads' );
		expect( $array['contents'] )->toHaveKey( 'plugins' );
		expect( $array['contents'] )->toHaveKey( 'themes' );
	} );
} );
