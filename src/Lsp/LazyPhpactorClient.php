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

    public function request(string $method, array $params): mixed
    {
        $this->client ??= PhpactorLanguageServer::start(
            $this->workspace,
            $this->commandPrefix,
            $this->timeout,
        );

        try {
            return $this->client->request($method, $params);
        } catch (LspException $exception) {
            // A timed-out or dead server may leave the LSP stream out of sync.
            // Discard it so the next MCP call can start a fresh Phpactor process.
            $this->close();
            throw $exception;
        }
    }

    public function notify(string $method, array $params): void
    {
        $this->client ??= PhpactorLanguageServer::start(
            $this->workspace,
            $this->commandPrefix,
            $this->timeout,
        );
        $this->client->notify($method, $params);
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
