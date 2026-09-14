<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Support;

use Suzumaze\BearSundayMcp\Lsp\SemanticLspClient;

final class InMemoryLspClient implements SemanticLspClient
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @param \Closure(string, array<string, mixed>): array<string, mixed> $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function request(string $method, array $params): array
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return ($this->handler)($method, $params);
    }

    public function close(): void
    {
    }
}
