<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final readonly class QueryLogEntry
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $type,
        public string $schemaUrl,
        public string $node,
        public string $position,
        public array $context,
        public string $scope,
    ) {
    }
}
