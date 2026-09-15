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
