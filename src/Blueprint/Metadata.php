<?php

declare(strict_types=1);

namespace VectorMU\Blueprint;

use DateTimeImmutable;
use JsonSerializable;
use VectorMU\Blueprint\Exception\BlueprintException;
use VectorMU\Plugin;

final class Metadata implements JsonSerializable {

	public const VERSION = '1.0';

	/**
	 * @param array<string, array{version: string, active: bool}> $installedPlugins
	 * @param array<string, array{version: string, active: bool}> $installedThemes
	 */
	public function __construct(
		public readonly string $version,
		public readonly DateTimeImmutable $createdAt,
		public readonly string $vectorMuVersion,
		public readonly string $sourceUrl,
		public readonly string $wpVersion,
		public readonly string $phpVersion,
		public readonly string $dbPrefix,
		public readonly bool $hasDatabase,
		public readonly bool $hasUploads,
		public readonly bool $hasPlugins,
		public readonly bool $hasThemes,
		public readonly array $installedPlugins,
		public readonly array $installedThemes,
	) {
	}

	/**
	 * @param array<string, array{version: string, active: bool}> $installedPlugins
	 * @param array<string, array{version: string, active: bool}> $installedThemes
	 */
	public static function create(
		string $sourceUrl,
		string $dbPrefix,
		bool $hasDatabase = true,
		bool $hasUploads = false,
		bool $hasPlugins = true,
		bool $hasThemes = true,
		array $installedPlugins = [],
		array $installedThemes = [],
	): self {
		return new self(
			version: self::VERSION,
			createdAt: new DateTimeImmutable(),
			vectorMuVersion: Plugin::VERSION,
			sourceUrl: $sourceUrl,
			wpVersion: get_bloginfo( 'version' ),
			phpVersion: phpversion(),
			dbPrefix: $dbPrefix,
			hasDatabase: $hasDatabase,
			hasUploads: $hasUploads,
			hasPlugins: $hasPlugins,
			hasThemes: $hasThemes,
			installedPlugins: $installedPlugins,
			installedThemes: $installedThemes,
		);
	}

	/**
	 * @throws BlueprintException
	 */
	public static function fromJson( string $json ): self {
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );

		if ( ! is_array( $data ) ) {
			throw new BlueprintException( 'Invalid metadata JSON: expected object' );
		}

		return self::fromArray( $data );
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws BlueprintException
	 */
	public static function fromArray( array $data ): self {
		self::validateRequiredKeys( $data );

		$source   = $data['source'];
		$contents = $data['contents'];

		if ( ! is_array( $source ) || ! is_array( $contents ) ) {
			throw new BlueprintException( 'Invalid metadata: source and contents must be objects' );
		}

		$createdAt = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i:sP', $data['created_at'] );

		if ( false === $createdAt ) {
			throw new BlueprintException( 'Invalid metadata: invalid created_at date format' );
		}

		/** @var array<string, array{version: string, active: bool}> $installedPlugins */
		$installedPlugins = is_array( $source['installed_plugins'] ?? null ) ? $source['installed_plugins'] : [];
		/** @var array<string, array{version: string, active: bool}> $installedThemes */
		$installedThemes = is_array( $source['installed_themes'] ?? null ) ? $source['installed_themes'] : [];

		return new self(
			version: (string) $data['version'],
			createdAt: $createdAt,
			vectorMuVersion: (string) $data['vector_mu_version'],
			sourceUrl: (string) $source['url'],
			wpVersion: (string) $source['wp_version'],
			phpVersion: (string) $source['php_version'],
			dbPrefix: (string) $source['db_prefix'],
			hasDatabase: (bool) $contents['database'],
			hasUploads: (bool) $contents['uploads'],
			hasPlugins: (bool) $contents['plugins'],
			hasThemes: (bool) $contents['themes'],
			installedPlugins: $installedPlugins,
			installedThemes: $installedThemes,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'version'           => $this->version,
			'created_at'        => $this->createdAt->format( 'Y-m-d\TH:i:sP' ),
			'vector_mu_version' => $this->vectorMuVersion,
			'source'            => [
				'url'               => $this->sourceUrl,
				'wp_version'        => $this->wpVersion,
				'php_version'       => $this->phpVersion,
				'db_prefix'         => $this->dbPrefix,
				'installed_plugins' => $this->installedPlugins,
				'installed_themes'  => $this->installedThemes,
			],
			'contents'          => [
				'database' => $this->hasDatabase,
				'uploads'  => $this->hasUploads,
				'plugins'  => $this->hasPlugins,
				'themes'   => $this->hasThemes,
			],
		];
	}

	public function toJson(): string {
		return json_encode( $this, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws BlueprintException
	 */
	private static function validateRequiredKeys( array $data ): void {
		$required = [ 'version', 'created_at', 'vector_mu_version', 'source', 'contents' ];

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				throw new BlueprintException( "Invalid metadata: missing required key '{$key}'" );
			}
		}

		if ( ! is_array( $data['source'] ) ) {
			throw new BlueprintException( 'Invalid metadata: source must be an object' );
		}

		$sourceRequired = [ 'url', 'wp_version', 'php_version', 'db_prefix' ];

		foreach ( $sourceRequired as $key ) {
			if ( ! array_key_exists( $key, $data['source'] ) ) {
				throw new BlueprintException( "Invalid metadata: missing required key 'source.{$key}'" );
			}
		}

		if ( ! is_array( $data['contents'] ) ) {
			throw new BlueprintException( 'Invalid metadata: contents must be an object' );
		}

		$contentsRequired = [ 'database', 'uploads', 'plugins', 'themes' ];

		foreach ( $contentsRequired as $key ) {
			if ( ! array_key_exists( $key, $data['contents'] ) ) {
				throw new BlueprintException( "Invalid metadata: missing required key 'contents.{$key}'" );
			}
		}
	}
}
