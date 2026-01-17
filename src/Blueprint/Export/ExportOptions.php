<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Export;

final class ExportOptions {

	public function __construct(
		public readonly string $outputPath,
		public readonly string $sourceUrl,
		public readonly string $dbPrefix,
		public readonly bool $includeUploads = false,
		public readonly ?int $maxUploadSize = null,
		/** @var list<string> */
		public readonly array $includePlugins = [],
		/** @var list<string> */
		public readonly array $includeThemes = [],
		/** @var list<string> */
		public readonly array $excludeTables = [],
	) {
	}
}
