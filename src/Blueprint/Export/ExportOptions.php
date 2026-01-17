<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Export;

final class ExportOptions {

	/**
	 * @param list<string> $excludeTables Database tables to exclude
	 */
	public function __construct(
		public readonly string $outputPath,
		public readonly string $sourceUrl,
		public readonly string $dbPrefix,
		public readonly bool $includeUploads = false,
		public readonly ?int $maxUploadSize = null,
		public readonly bool $skipPlugins = false,
		public readonly bool $skipThemes = false,
		public readonly array $excludeTables = [],
	) {
	}
}
