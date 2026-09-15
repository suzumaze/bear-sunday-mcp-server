<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\FileUri;
use Suzumaze\BearSundayMcp\StandardLspTools;
use Suzumaze\BearSundayMcp\Tests\Support\InMemoryLspClient;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(StandardLspTools::class)]
#[CoversClass(FileUri::class)]
final class StandardLspToolsTest extends TestCase
{
    private string $fixture;
    private InMemoryLspClient $client;
    private StandardLspTools $tools;

    protected function setUp(): void
    {
        $fixture = realpath(__DIR__ . '/../Fixture/workspace');
        self::assertNotFalse($fixture);
        $this->fixture = $fixture;
        $this->client = new InMemoryLspClient(function (string $method): mixed {
            $range = self::range(8, 12, 8, 16);
            if ($method === 'textDocument/definition') {
                return [
                    'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                    'range' => $range,
                ];
            }
            if ($method === 'textDocument/references') {
                return [
                    [
                        'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                        'range' => $range,
                    ],
                    [
                        'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/Dashboard.php'),
                        'range' => self::range(13, 31, 13, 46),
                    ],
                    [
                        'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                        'range' => $range,
                    ],
                    [
                        'uri' => FileUri::fromPath(dirname($this->fixture, 3) . '/src/Application.php'),
                        'range' => self::range(0, 0, 0, 1),
                    ],
                ];
            }
            if ($method === 'textDocument/hover') {
                return [
                    'contents' => ['kind' => 'markdown', 'value' => '**BEAR Resource** `app://self/user`'],
                    'range' => self::range(12, 31, 12, 51),
                ];
            }

            return null;
        });
        $this->tools = new StandardLspTools($this->client, Workspace::fromPath($fixture));
    }

    public function testNormalizesDefinitionReferencesAndHover(): void
    {
        $definition = $this->tools->definition('src/Resource/App/Dashboard.php', 12, 40);
        self::assertSame('ok', $definition['status']);
        self::assertSame('src/Resource/App/User.php', $definition['data']['locations'][0]['path']);
        self::assertSame(1, $definition['data']['total']);

        $references = $this->tools->references('src/Resource/App/Dashboard.php', 12, 40, true, 1);
        self::assertSame('ok', $references['status']);
        self::assertSame(2, $references['data']['total']);
        self::assertTrue($references['data']['truncated']);
        self::assertSame('src/Resource/App/Dashboard.php', $references['data']['locations'][0]['path']);

        $hover = $this->tools->hover('src/Resource/App/Dashboard.php', 12, 40);
        self::assertSame('ok', $hover['status']);
        self::assertSame('markdown', $hover['data']['contents']['kind']);
        self::assertStringContainsString('app://self/user', $hover['data']['contents']['value']);
        self::assertFalse($hover['data']['truncated']);

        self::assertSame(
            [
                'textDocument/didOpen',
                'textDocument/didClose',
                'textDocument/didOpen',
                'textDocument/didClose',
                'textDocument/didOpen',
                'textDocument/didClose',
            ],
            array_column($this->client->notifications, 'method'),
        );
        self::assertSame(
            ['context' => ['includeDeclaration' => true]],
            array_diff_key($this->client->requests[1]['params'], [
                'textDocument' => true,
                'position' => true,
            ]),
        );
    }

    public function testReturnsStableEmptyFailuresForInvalidInputAndResponses(): void
    {
        self::assertSame(
            'invalid_input',
            $this->tools->definition('../outside.php', 0, 0)['status'],
        );
        self::assertSame(
            'not_found',
            $this->tools->definition('src/Missing.php', 0, 0)['status'],
        );
        self::assertSame(
            'invalid_input',
            $this->tools->definition('src/Resource/App/Dashboard.php', 999, 0)['status'],
        );
        self::assertSame(
            'invalid_input',
            $this->tools->references('src/Resource/App/Dashboard.php', 12, 40, false, 0)['status'],
        );

        $client = new InMemoryLspClient(static fn (): string => 'invalid');
        $tools = new StandardLspTools($client, Workspace::fromPath($this->fixture));
        self::assertSame(
            'parse_error',
            $tools->definition('src/Resource/App/Dashboard.php', 12, 40)['status'],
        );
    }

    public function testBoundsHoverWithoutSplittingUtf8(): void
    {
        $client = new InMemoryLspClient(static fn (): array => [
            'contents' => ['kind' => 'plaintext', 'value' => str_repeat('あ', 30_000)],
        ]);
        $tools = new StandardLspTools($client, Workspace::fromPath($this->fixture));

        $hover = $tools->hover('src/Resource/App/Dashboard.php', 12, 40);

        self::assertSame('ok', $hover['status']);
        self::assertTrue($hover['data']['truncated']);
        self::assertSame(1, preg_match('//u', $hover['data']['contents']['value']));
        self::assertLessThanOrEqual(65_536, strlen($hover['data']['contents']['value']));
    }

    public function testValidatesPositionsAsUtf16CodeUnits(): void
    {
        $client = new InMemoryLspClient(static fn (): array => [
            'contents' => ['kind' => 'plaintext', 'value' => 'unicode'],
        ]);
        $tools = new StandardLspTools($client, Workspace::fromPath($this->fixture));

        self::assertSame('ok', $tools->hover('unicode.txt', 0, 2)['status']);
        self::assertSame('invalid_input', $tools->hover('unicode.txt', 0, 4)['status']);
    }

    /** @return array<string, array{line:int,character:int}> */
    private static function range(int $startLine, int $startCharacter, int $endLine, int $endCharacter): array
    {
        return [
            'start' => ['line' => $startLine, 'character' => $startCharacter],
            'end' => ['line' => $endLine, 'character' => $endCharacter],
        ];
    }
}
