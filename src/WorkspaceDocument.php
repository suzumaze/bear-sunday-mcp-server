<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

final readonly class WorkspaceDocument
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public string $uri,
        public string $languageId,
        public string $contents,
    ) {
    }
}
