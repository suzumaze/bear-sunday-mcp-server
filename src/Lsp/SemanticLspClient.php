<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

interface SemanticLspClient
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function request(string $method, array $params): array;

    public function close(): void;
}
