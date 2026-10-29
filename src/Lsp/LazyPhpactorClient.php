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

    /**
     * Start Phpactor before the first tool call. Right after initialize it lists every file in the
     * workspace before it answers anything; on a large application that took about 25 seconds, which
     * otherwise lands on the first call. Startup must not fail over this: on any error the first
     * request starts Phpactor again and reports the error as before.
     */
    public function warmUp(float $initializeTimeout): void
    {
        try {
            $this->client ??= PhpactorLanguageServer::start(
                $this->workspace,
                $this->commandPrefix,
                $this->timeout,
                min($initializeTimeout, $this->timeout),
            );
        } catch (LspException) {
            // Left unstarted; the first request tries again.
        }
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
        } catch (LspTimeoutException | LspRpcException $exception) {
            // The stream stays in sync: a late answer is skipped by its request id, and an RPC
            // error is a complete response. Keep the warm process; a restart would repeat a cold
            // start that may itself never finish within the timeout.
            throw $exception;
        } catch (LspException $exception) {
            // The process died or its stream is unreadable. Start a fresh one on the next call.
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
