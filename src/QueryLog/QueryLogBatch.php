<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final readonly class QueryLogBatch
{
    /** @param list<QueryLogSession> $sessions */
    public function __construct(
        public array $sessions,
        public int $total,
        public bool $truncated,
    ) {
    }
}
