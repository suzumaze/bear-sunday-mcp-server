<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

use Suzumaze\BearSundayMcp\Version;
use Suzumaze\BearSundayMcp\Workspace;

final class PhpactorLanguageServer implements SemanticLspClient
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';
    private int $nextId = 0;
    private bool $closed = false;
    private bool $busy = false;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        $process,
        array $pipes,
        private readonly LspFrameCodec $codec,
        private readonly float $timeout,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * @param non-empty-list<string> $commandPrefix
     */
    public static function start(
        Workspace $workspace,
        array $commandPrefix,
        float $timeout = 20.0,
    ): self {
        if ($timeout <= 0 || $timeout > 60) {
            throw new \InvalidArgumentException('LSP timeout must be between 0 and 60 seconds');
        }

        $command = [
            ...$commandPrefix,
            'language-server',
            '--working-dir=' . $workspace->root,
            '--config-extra={"language_server_configuration.auto_config":false}',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $workspace->root);
        if (!is_resource($process) || count($pipes) !== 3) {
            throw new LspException('Could not start Phpactor');
        }

        /** @var array<int, resource> $pipes */
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $client = new self($process, $pipes, new LspFrameCodec(), $timeout);

        try {
            $initialize = $client->rpcRequest('initialize', [
                'processId' => getmypid(),
                'rootUri' => FileUri::fromPath($workspace->root),
                'capabilities' => (object) [],
                'clientInfo' => [
                    'name' => 'bear-sunday-mcp-server',
                    'version' => Version::CURRENT,
                ],
            ]);
            if (!is_array($initialize)) {
                throw new LspException('Phpactor returned an invalid initialize result');
            }
            $client->notify('initialized', []);
        } catch (\Throwable $exception) {
            $client->forceClose();
            throw $exception;
        }

        return $client;
    }

    public function request(string $method, array $params): mixed
    {
        if ($this->busy) {
            throw new LspException('Concurrent LSP requests are not supported');
        }

        $this->busy = true;
        try {
            $result = $this->rpcRequest($method, $params);
        } finally {
            $this->busy = false;
        }

        return $result;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->rpcRequest('shutdown', [], min($this->timeout, 2.0));
            $this->notify('exit', []);
        } catch (\Throwable) {
            // The process is closed below even when graceful shutdown is unavailable.
        }

        $this->forceClose();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function forceClose(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rpcRequest(string $method, array $params, ?float $timeout = null): mixed
    {
        if ($this->closed) {
            throw new LspException('Phpactor process is closed');
        }

        $id = ++$this->nextId;
        $this->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        while (true) {
            $message = $this->read($timeout ?? $this->timeout);
            if (isset($message['method']) && is_string($message['method'])) {
                if (array_key_exists('id', $message)) {
                    $this->answerServerRequest($message);
                }
                continue;
            }

            if (($message['id'] ?? null) !== $id) {
                continue;
            }
            if (isset($message['error']) && is_array($message['error'])) {
                $code = $message['error']['code'] ?? -32603;
                throw new LspRpcException(is_int($code) ? $code : -32603);
            }

            return $message['result'] ?? null;
        }
    }

    /** @param array<string, mixed> $message */
    private function answerServerRequest(array $message): void
    {
        $result = null;
        if ($message['method'] === 'workspace/configuration') {
            $items = $message['params']['items'] ?? [];
            $result = is_array($items) ? array_fill(0, count($items), null) : [];
        }

        $this->send([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => $result,
        ]);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $frame = $this->codec->encode($message);
        $offset = 0;
        while ($offset < strlen($frame)) {
            $written = fwrite($this->pipes[0], substr($frame, $offset));
            if ($written === false || $written === 0) {
                throw new LspException('Could not write to Phpactor');
            }
            $offset += $written;
        }
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    private function read(float $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $decoded = $this->codec->decode($this->stdoutBuffer);
            if ($decoded !== null) {
                return $decoded;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new LspTimeoutException('Timed out waiting for Phpactor');
            }

            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
            if ($selected === false) {
                throw new LspException('Could not read from Phpactor');
            }
            if ($selected === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $this->pipes[1]) {
                    $this->stdoutBuffer .= $chunk;
                    if (strlen($this->stdoutBuffer) > 5 * 1024 * 1024) {
                        throw new LspException('Phpactor output exceeds the configured size limit');
                    }
                } else {
                    $this->stderrBuffer = substr($this->stderrBuffer . $chunk, -65536);
                }
            }

            $status = proc_get_status($this->process);
            if (!$status['running'] && feof($this->pipes[1])) {
                throw new LspException('Phpactor exited before responding');
            }
        }
    }
}
