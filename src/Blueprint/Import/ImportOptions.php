<?php

declare(strict_types=1);

namespace VectorMU\Blueprint\Import;

final class ImportOptions {

	public function __construct(
		public readonly string $archivePath,
		public readonly string $targetUrl,
		public readonly string $dbPrefix,
		public readonly bool $skipDatabase = false,
		public readonly bool $skipContent = false,
		public readonly bool $skipSearchReplace = false,
		public readonly bool $dryRun = false,
		public readonly bool $wipeContent = false,
	) {
	}
}
