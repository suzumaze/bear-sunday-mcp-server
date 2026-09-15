<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

interface SemanticLspClient
{
    /**
     * @param array<string, mixed> $params
     * @return mixed
     */
    public function request(string $method, array $params): mixed;

    /** @param array<string, mixed> $params */
    public function notify(string $method, array $params): void;

    public function close(): void;
}
