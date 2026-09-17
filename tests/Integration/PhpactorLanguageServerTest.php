<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\LspFrameCodec;
use Suzumaze\BearSundayMcp\Lsp\PhpactorLanguageServer;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(PhpactorLanguageServer::class)]
#[CoversClass(LspFrameCodec::class)]
final class PhpactorLanguageServerTest extends TestCase
{
    public function testDisablesPhpactorAutoConfiguration(): void
    {
        $workspace = sys_get_temp_dir() . '/bear-mcp-auto-config-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($workspace));
        self::assertNotFalse(file_put_contents($workspace . '/.simulate-phpactor-auto-config', "\n"));

        try {
            $client = PhpactorLanguageServer::start(
                Workspace::fromPath($workspace),
                [__DIR__ . '/../Fixture/fake-phpactor'],
                2,
            );
            $client->close();

            self::assertFileDoesNotExist($workspace . '/.phpactor.json');
        } finally {
            if (isset($client)) {
                $client->close();
            }
            if (is_file($workspace . '/.phpactor.json')) {
                unlink($workspace . '/.phpactor.json');
            }
            unlink($workspace . '/.simulate-phpactor-auto-config');
            rmdir($workspace);
        }
    }

    public function testRunsInitializeQueryShutdownAgainstAStdioLanguageServer(): void
    {
        $client = PhpactorLanguageServer::start(
            Workspace::fromPath(__DIR__ . '/../Fixture/workspace'),
            [__DIR__ . '/../Fixture/fake-phpactor'],
            2,
        );

        try {
            $result = $client->request('bear/resource/list', ['scheme' => 'app']);
            self::assertSame('ok', $result['status']);
            self::assertSame('bear/resource/list', $result['data']['method']);
            self::assertSame(['scheme' => 'app'], $result['data']['params']);

            $locations = $client->request('textDocument/references', [
                'textDocument' => ['uri' => 'file:///fixture.php'],
                'position' => ['line' => 0, 'character' => 0],
                'context' => ['includeDeclaration' => false],
            ]);
            self::assertIsArray($locations);
            self::assertCount(2, $locations);
        } finally {
            $client->close();
        }
    }
}
