<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final readonly class QueryLogSession
{
    public function __construct(
        public string $id,
        public ?string $recordedAt,
        public string $json,
        public string $provenancePath,
    ) {
    }
}
