<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\PhpactorLanguageServer;
use Suzumaze\BearSundayMcp\StandardLspTools;
use Suzumaze\BearSundayMcp\Workspace;

final class RealPhpactorTest extends TestCase
{
    public function testRunsReferencesAsTheFirstRequestAfterInitialize(): void
    {
        $phpactor = getenv('BEAR_MCP_TEST_PHPACTOR');
        if (!is_string($phpactor) || $phpactor === '') {
            self::markTestSkipped('Set BEAR_MCP_TEST_PHPACTOR to run the real Phpactor integration test.');
        }

        $workspace = Workspace::fromPath(__DIR__ . '/../Fixture/workspace');
        $client = PhpactorLanguageServer::start($workspace, [$phpactor], 20);
        try {
            $result = (new StandardLspTools($client, $workspace))->references(
                'src/Resource/App/Dashboard.php',
                12,
                40,
            );

            self::assertSame('ok', $result['status']);
            self::assertSame(2, $result['data']['total']);
        } finally {
            $client->close();
        }
    }

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
            self::assertSame(3, $result['data']['resourceCount']);

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
