<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A client may kill the MCP server without letting it shut Phpactor down. Without
 * patches/language-server-exit-when-stdio-closes.patch, Phpactor ignores its closed input and keeps
 * running, with its file watcher, until someone kills it by hand.
 */
#[CoversNothing]
final class PhpactorExitsWithServerTest extends TestCase
{
    public function testPhpactorExitsWhenTheServerIsKilled(): void
    {
        $root = dirname(__DIR__, 2);
        $pipes = [];
        $server = proc_open(
            [
                PHP_BINARY,
                $root . '/bin/bear-sunday-mcp',
                '--workspace=' . $root . '/tests/Fixture/workspace',
                '--timeout=20',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($server);
        stream_set_timeout($pipes[1], 30);

        $send = static function (array $message) use ($pipes): void {
            fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        };
        $receive = static function (int $id) use ($pipes): array {
            while (($line = fgets($pipes[1])) !== false) {
                $message = json_decode($line, true);
                if (is_array($message) && ($message['id'] ?? null) === $id) {
                    return $message;
                }
            }
            self::fail('The server stopped answering before response ' . $id);
        };
        $send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phpactor-exit-test', 'version' => '1.0.0'],
        ]]);
        $receive(1);
        $send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'bear_project_info',
            'arguments' => [],
        ]]);
        $receive(2);

        $descendants = self::descendants(proc_get_status($server)['pid']);
        self::assertNotSame([], $descendants, 'Phpactor should be running under the server');

        proc_terminate($server, 9);
        proc_close($server);

        $deadline = microtime(true) + 20;
        do {
            $alive = array_values(array_filter($descendants, static fn (int $pid): bool => posix_kill($pid, 0)));
            if ($alive === []) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        foreach ($alive as $pid) {
            posix_kill($pid, 9);
        }

        self::assertSame([], $alive, 'Phpactor processes left after the server was killed');
    }

    /** @return list<int> */
    private static function descendants(int $pid): array
    {
        $lines = array_filter(explode("\n", (string) shell_exec('pgrep -P ' . $pid)));
        $children = array_values(array_map('intval', $lines));
        $all = $children;
        foreach ($children as $child) {
            $all = [...$all, ...self::descendants($child)];
        }

        return $all;
    }
}
