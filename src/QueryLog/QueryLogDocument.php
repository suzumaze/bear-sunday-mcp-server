<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final readonly class QueryLogDocument
{
    /**
     * @param array<string, mixed> $root
     * @param list<QueryLogEntry>  $entries
     */
    public function __construct(
        public QueryLogSession $session,
        public array $root,
        public array $entries,
    ) {
    }
}
