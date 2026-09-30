<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\LazyPhpactorClient;
use Suzumaze\BearSundayMcp\Lsp\LspException;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
use Suzumaze\BearSundayMcp\Lsp\LspTimeoutException;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(LazyPhpactorClient::class)]
final class LazyPhpactorClientTest extends TestCase
{
    public function testTimeoutKeepsTheWarmProcessAndSkipsItsLateAnswer(): void
    {
        // The timeout also covers process start and initialize, so keep it generous for CI
        // and make the slow answer clearly longer than it.
        $client = $this->client(1.0);
        try {
            $pid = $client->request('test/pid', []);
            try {
                $client->request('test/slow', ['microseconds' => 1_500_000]);
                self::fail('The slow request should time out.');
            } catch (LspTimeoutException) {
            }

            // A restart here would repeat a cold start that may never finish within the timeout.
            // The late answer arrives while the next request waits and is skipped by its id.
            self::assertSame($pid, $client->request('test/pid', []));
        } finally {
            $client->close();
        }
    }

    public function testRpcErrorKeepsTheProcess(): void
    {
        $client = $this->client(2);
        try {
            $pid = $client->request('test/pid', []);
            try {
                $client->request('test/error', []);
                self::fail('The request should be rejected.');
            } catch (LspRpcException) {
            }

            self::assertSame($pid, $client->request('test/pid', []));
        } finally {
            $client->close();
        }
    }

    public function testDeadProcessIsReplacedOnTheNextRequest(): void
    {
        $client = $this->client(2);
        try {
            $pid = $client->request('test/pid', []);
            self::assertIsInt($pid);
            posix_kill($pid, SIGKILL);
            usleep(100000);
            try {
                $client->request('test/pid', []);
            } catch (LspException $exception) {
                self::assertNotInstanceOf(LspTimeoutException::class, $exception);
                self::assertNotInstanceOf(LspRpcException::class, $exception);
            }

            $replacement = $client->request('test/pid', []);
            self::assertIsInt($replacement);
            self::assertNotSame($pid, $replacement);
        } finally {
            $client->close();
        }
    }

    private function client(float $timeout): LazyPhpactorClient
    {
        return new LazyPhpactorClient(
            Workspace::fromPath(__DIR__ . '/../Fixture/workspace'),
            [__DIR__ . '/../Fixture/fake-phpactor'],
            $timeout,
        );
    }
}
