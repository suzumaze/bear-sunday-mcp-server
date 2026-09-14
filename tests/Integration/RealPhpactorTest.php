<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\PhpactorLanguageServer;
use Suzumaze\BearSundayMcp\Workspace;

final class RealPhpactorTest extends TestCase
{
    public function testQueriesSemanticApiV1ThroughARealPhpactorProcess(): void
    {
        $phpactor = getenv('BEAR_MCP_TEST_PHPACTOR');
        if (!is_string($phpactor) || $phpactor === '') {
            self::markTestSkipped('Set BEAR_MCP_TEST_PHPACTOR to run the real Phpactor integration test.');
        }

        $client = PhpactorLanguageServer::start(
            Workspace::fromPath(__DIR__ . '/../Fixture/workspace'),
            [$phpactor],
            20,
        );
        try {
            $result = $client->request('bear/project/info', []);
            self::assertSame('ok', $result['status']);
            self::assertSame(1, $result['data']['semanticApiVersion']);
            self::assertSame(1, $result['data']['resourceCount']);

            $outside = $client->request('bear/resource/describe', [
                'uri' => 'app://self/user',
                'contextPath' => '../../etc/passwd',
            ]);
            self::assertContains($outside['status'], ['invalid_input', 'outside_workspace']);
            self::assertStringNotContainsString('/etc/passwd', json_encode($outside, JSON_THROW_ON_ERROR));
        } finally {
            $client->close();
        }
    }
}
