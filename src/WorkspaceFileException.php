<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

final class WorkspaceFileException extends \RuntimeException
{
    public function __construct(
        public readonly string $status,
        string $message,
        public readonly string $errorCode = 'invalid_document',
    ) {
        parent::__construct($message);
    }
}
