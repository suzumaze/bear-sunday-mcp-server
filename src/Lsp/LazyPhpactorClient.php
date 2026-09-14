<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

use Suzumaze\BearSundayMcp\Workspace;

final class LazyPhpactorClient implements SemanticLspClient
{
    private ?PhpactorLanguageServer $client = null;

    /**
     * @param non-empty-list<string> $commandPrefix
     */
    public function __construct(
        private readonly Workspace $workspace,
        private readonly array $commandPrefix,
        private readonly float $timeout,
    ) {
    }

    public function request(string $method, array $params): array
    {
        $this->client ??= PhpactorLanguageServer::start(
            $this->workspace,
            $this->commandPrefix,
            $this->timeout,
        );

        return $this->client->request($method, $params);
    }

    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
