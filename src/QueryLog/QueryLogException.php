<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final class QueryLogException extends \RuntimeException
{
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }
}
