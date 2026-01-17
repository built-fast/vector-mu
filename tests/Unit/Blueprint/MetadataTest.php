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
				'url'         => 'https://example.com',
				'wp_version'  => '6.4.2',
				'php_version' => '8.1.27',
				'db_prefix'   => 'wp_',
			],
			'contents'          => [
				'database' => true,
				'uploads'  => false,
				'plugins'  => [ 'my-plugin' ],
				'themes'   => [ 'my-theme' ],
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
		expect( $metadata->plugins )->toBe( [ 'my-plugin' ] );
		expect( $metadata->themes )->toBe( [ 'my-theme' ] );
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
				'plugins'  => [],
				'themes'   => [],
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
				'plugins'  => [],
				'themes'   => [],
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
			plugins: [ 'my-plugin' ],
			themes: [ 'my-theme' ],
		);

		$json = $metadata->toJson();
		$data = json_decode( $json, true );

		expect( $data['version'] )->toBe( '1.0' );
		expect( $data['source']['url'] )->toBe( 'https://example.com' );
		expect( $data['contents']['database'] )->toBeTrue();
		expect( $data['contents']['uploads'] )->toBeFalse();
		expect( $data['contents']['plugins'] )->toBe( [ 'my-plugin' ] );
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
			plugins: [],
			themes: [],
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
		expect( $array['contents'] )->toHaveKey( 'database' );
		expect( $array['contents'] )->toHaveKey( 'uploads' );
		expect( $array['contents'] )->toHaveKey( 'plugins' );
		expect( $array['contents'] )->toHaveKey( 'themes' );
	} );
} );
