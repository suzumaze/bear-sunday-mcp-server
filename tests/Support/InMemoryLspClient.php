<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Support;

use Suzumaze\BearSundayMcp\Lsp\SemanticLspClient;

final class InMemoryLspClient implements SemanticLspClient
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $notifications = [];

    /** @param \Closure(string, array<string, mixed>): mixed $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function request(string $method, array $params): mixed
    {
        $this->requests[] = ['method' => $method, 'params' => $params];

        return ($this->handler)($method, $params);
    }

    public function notify(string $method, array $params): void
    {
        $this->notifications[] = ['method' => $method, 'params' => $params];
    }

    public function close(): void
    {
    }
}
