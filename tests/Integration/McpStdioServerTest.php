<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Version;

final class McpStdioServerTest extends TestCase
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $stderr = '';
    private int $nextId = 0;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $command = [
            PHP_BINARY,
            $root . '/bin/bear-sunday-mcp',
            '--workspace=' . $root . '/tests/Fixture/workspace',
            '--phpactor=' . $root . '/tests/Fixture/fake-phpactor',
            '--timeout=2',
        ];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $this->process = $process;
        /** @var array<int, resource> $pipes */
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    protected function tearDown(): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }

        $deadline = microtime(true) + 3;
        while (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (!$status['running'] || microtime(true) >= $deadline) {
                break;
            }
            usleep(10_000);
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
        }
        foreach ([1, 2] as $descriptor) {
            if (isset($this->pipes[$descriptor]) && is_resource($this->pipes[$descriptor])) {
                fclose($this->pipes[$descriptor]);
            }
        }
        if (is_resource($this->process)) {
            proc_close($this->process);
        }
    }

    public function testListsOnlyReadOnlyToolsAndCallsThroughToLsp(): void
    {
        $initialize = $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]);
        self::assertSame('2025-11-25', $initialize['result']['protocolVersion']);
        self::assertSame(Version::CURRENT, $initialize['result']['serverInfo']['version']);
        $this->notify('notifications/initialized');

        $listed = $this->request('tools/list', []);
        $tools = $listed['result']['tools'] ?? [];
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame([
            'bear_alps_descriptor_lookup',
            'bear_project_info',
            'bear_resource_describe',
            'bear_resource_list',
            'bear_route_lookup',
            'bear_schema_lookup',
            'bear_sql_lookup',
            'bear_template_for_resource',
            'bear_template_lookup',
        ], $names);
        foreach ($tools as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint'] ?? false);
            self::assertFalse($tool['annotations']['destructiveHint'] ?? true);
            self::assertFalse($tool['annotations']['openWorldHint'] ?? true);
        }

        $called = $this->request('tools/call', [
            'name' => 'bear_resource_list',
            'arguments' => ['scheme' => 'app', 'prefix' => 'user', 'limit' => 10],
        ]);
        self::assertFalse($called['result']['isError'] ?? true);
        self::assertSame('ok', $called['result']['structuredContent']['status']);
        self::assertSame(
            'bear/resource/list',
            $called['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['scheme' => 'app', 'prefix' => 'user', 'limit' => 10],
            $called['result']['structuredContent']['data']['params'],
        );

        $navigation = $this->request('tools/call', [
            'name' => 'bear_alps_descriptor_lookup',
            'arguments' => ['descriptorId' => 'goArticle'],
        ]);
        self::assertSame(
            'bear/alps/describeDescriptor',
            $navigation['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['descriptorId' => 'goArticle', 'contextPath' => null],
            $navigation['result']['structuredContent']['data']['params'],
        );
    }

    public function testMalformedToolInputDoesNotTerminateTheServer(): void
    {
        $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]);
        $this->notify('notifications/initialized');

        $invalid = $this->request('tools/call', [
            'name' => 'bear_resource_list',
            'arguments' => ['limit' => 999],
        ]);
        self::assertSame(-32602, $invalid['error']['code']);

        $invalidEngine = $this->request('tools/call', [
            'name' => 'bear_template_lookup',
            'arguments' => ['engine' => 'blade', 'name' => 'user'],
        ]);
        self::assertSame(-32602, $invalidEngine['error']['code']);

        $valid = $this->request('tools/call', [
            'name' => 'bear_project_info',
            'arguments' => (object) [],
        ]);
        self::assertSame('ok', $valid['result']['structuredContent']['status']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params): array
    {
        $id = ++$this->nextId;
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        while (true) {
            $message = $this->read();
            if (($message['id'] ?? null) === $id) {
                return $message;
            }
        }
    }

    private function notify(string $method): void
    {
        $this->send(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) []]);
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $line = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        self::assertSame(strlen($line), fwrite($this->pipes[0], $line));
        fflush($this->pipes[0]);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $read = [$this->pipes[1], $this->pipes[2]];
            $write = null;
            $except = null;
            $selected = stream_select($read, $write, $except, 0, 100_000);
            if ($selected === false) {
                self::fail('Could not read MCP process output.');
            }
            foreach ($read as $stream) {
                $line = fgets($stream);
                if ($line === false || $line === '') {
                    continue;
                }
                if ($stream === $this->pipes[2]) {
                    $this->stderr .= $line;
                    continue;
                }

                $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                self::assertIsArray($message);

                return $message;
            }
        }

        self::fail('Timed out waiting for MCP response: ' . $this->stderr);
    }
}
