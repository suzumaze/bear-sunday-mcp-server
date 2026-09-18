<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\FileUri;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
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
            if ($method === 'textDocument/typeDefinition') {
                return [
                    'uri' => FileUri::fromPath($this->fixture . '/var/json_schema/user.json'),
                    'range' => self::range(2, 2, 2, 2),
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
            if ($method === 'textDocument/completion') {
                return [
                    'isIncomplete' => true,
                    'items' => [
                        [
                            'label' => 'app://self/user',
                            'kind' => 12,
                            'sortText' => '0001-user',
                            'insertText' => 'user',
                            'insertTextFormat' => 1,
                        ],
                        [
                            'label' => 'app://self/dashboard',
                            'kind' => 12,
                            'sortText' => '0000-dashboard',
                            'detail' => 'BEAR Resource URI',
                            'documentation' => ['kind' => 'markdown', 'value' => '**Dashboard**'],
                        ],
                        [
                            'label' => 'app://self/user',
                            'kind' => 12,
                            'sortText' => '0001-user',
                            'insertText' => 'user',
                            'insertTextFormat' => 1,
                        ],
                    ],
                ];
            }
            if ($method === 'textDocument/documentSymbol') {
                return [[
                    'name' => 'Dashboard',
                    'kind' => 5,
                    'range' => self::range(10, 0, 17, 1),
                    'selectionRange' => self::range(10, 12, 10, 21),
                    'children' => [[
                        'name' => 'onGet',
                        'kind' => 6,
                        'range' => self::range(14, 4, 16, 5),
                        'selectionRange' => self::range(14, 20, 14, 25),
                        'children' => [],
                    ]],
                ]];
            }
            if ($method === 'textDocument/documentLink') {
                return [
                    [
                        'range' => self::range(13, 31, 13, 46),
                        'target' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                    ],
                    [
                        'range' => self::range(12, 31, 12, 51),
                        'target' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                    ],
                    [
                        'range' => self::range(12, 31, 12, 51),
                        'target' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                    ],
                    [
                        'range' => self::range(14, 0, 14, 7),
                        'target' => FileUri::fromPath('/etc/passwd'),
                    ],
                    ['range' => self::range(15, 0, 15, 7)],
                ];
            }
            if ($method === 'workspace/symbol') {
                return [
                    [
                        'name' => 'User',
                        'kind' => 5,
                        'containerName' => 'Acme\\Demo\\Resource\\App',
                        'location' => [
                            'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/User.php'),
                            'range' => self::range(8, 12, 8, 16),
                        ],
                    ],
                    [
                        'name' => 'Outside',
                        'kind' => 5,
                        'location' => [
                            'uri' => FileUri::fromPath('/etc/passwd'),
                            'range' => self::range(0, 0, 0, 1),
                        ],
                    ],
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
        self::assertSame(
            'parse_error',
            $tools->typeDefinition('src/Resource/App/User.php', 8, 14)['status'],
        );
        self::assertSame(
            'parse_error',
            $tools->completion('src/Resource/App/Dashboard.php', 12, 40)['status'],
        );
        self::assertSame(
            'parse_error',
            $tools->documentSymbols('src/Resource/App/Dashboard.php')['status'],
        );
        self::assertSame('parse_error', $tools->workspaceSymbols('Dashboard')['status']);
        self::assertSame(
            'parse_error',
            $tools->documentLinks('src/Resource/App/Dashboard.php')['status'],
        );
    }

    public function testNormalizesTypeDefinitionAndDocumentLinks(): void
    {
        $typeDefinition = $this->tools->typeDefinition('src/Resource/App/User.php', 8, 14);
        self::assertSame('ok', $typeDefinition['status']);
        self::assertSame(
            'var/json_schema/user.json',
            $typeDefinition['data']['locations'][0]['path'],
        );

        $documentLinks = $this->tools->documentLinks('src/Resource/App/Dashboard.php', 1);
        self::assertSame('ok', $documentLinks['status']);
        self::assertSame(2, $documentLinks['data']['total']);
        self::assertTrue($documentLinks['data']['truncated']);
        self::assertSame(12, $documentLinks['data']['links'][0]['range']['start']['line']);
        self::assertSame(
            'src/Resource/App/User.php',
            $documentLinks['data']['links'][0]['targetPath'],
        );
    }

    public function testNormalizesCompletionAndSymbols(): void
    {
        $completion = $this->tools->completion('src/Resource/App/Dashboard.php', 12, 40, 1);
        self::assertSame('ok', $completion['status']);
        self::assertSame(2, $completion['data']['total']);
        self::assertTrue($completion['data']['isIncomplete']);
        self::assertTrue($completion['data']['truncated']);
        self::assertSame('app://self/dashboard', $completion['data']['items'][0]['label']);
        self::assertSame('markdown', $completion['data']['items'][0]['documentation']['kind']);

        $documentSymbols = $this->tools->documentSymbols('src/Resource/App/Dashboard.php');
        self::assertSame('ok', $documentSymbols['status']);
        self::assertSame(2, $documentSymbols['data']['total']);
        self::assertSame(['Dashboard', 'onGet'], array_column($documentSymbols['data']['symbols'], 'name'));
        self::assertSame('Dashboard', $documentSymbols['data']['symbols'][1]['containerName']);
        self::assertSame(
            'src/Resource/App/Dashboard.php',
            $documentSymbols['data']['symbols'][1]['path'],
        );

        $workspaceSymbols = $this->tools->workspaceSymbols('User');
        self::assertSame('ok', $workspaceSymbols['status']);
        self::assertSame(1, $workspaceSymbols['data']['total']);
        self::assertSame('User', $workspaceSymbols['data']['symbols'][0]['name']);
        self::assertSame('src/Resource/App/User.php', $workspaceSymbols['data']['symbols'][0]['path']);
        self::assertSame([
            'source' => 'phpactor_workspace_index',
            'recordTypes' => ['class', 'function', 'constant'],
            'includesMethods' => false,
            'indexFreshness' => 'unknown',
            'emptyResultIsDefinitive' => false,
        ], $workspaceSymbols['data']['coverage']);
        self::assertSame('unknown', $workspaceSymbols['provenance'][0]['freshness']);
        self::assertSame(
            ['query' => 'User'],
            $this->client->requests[2]['params'],
        );
    }

    public function testWorkspaceSymbolAbsenceIsExplicitlyInconclusive(): void
    {
        $tools = new StandardLspTools(
            new InMemoryLspClient(static fn (): array => []),
            Workspace::fromPath($this->fixture),
        );

        $result = $tools->workspaceSymbols('MissingMethod');

        self::assertSame('not_found', $result['status']);
        self::assertSame([], $result['data']['symbols']);
        self::assertFalse($result['data']['coverage']['includesMethods']);
        self::assertFalse($result['data']['coverage']['emptyResultIsDefinitive']);
        self::assertSame('unknown', $result['provenance'][0]['freshness']);
    }

    public function testWorkspaceSymbolFailureStillExplainsCoverage(): void
    {
        $tools = new StandardLspTools(
            new InMemoryLspClient(static function (): never {
                throw new LspRpcException(-32603);
            }),
            Workspace::fromPath($this->fixture),
        );

        $result = $tools->workspaceSymbols('Dashboard');

        self::assertSame('engine_unavailable', $result['status']);
        self::assertSame([], $result['data']['symbols']);
        self::assertSame('unknown', $result['data']['coverage']['indexFreshness']);
        self::assertFalse($result['data']['coverage']['emptyResultIsDefinitive']);
    }

    public function testBoundsCompletionFieldsWithoutSplittingUtf8(): void
    {
        $client = new InMemoryLspClient(static fn (): array => [[
            'label' => 'resource',
            'documentation' => str_repeat('あ', 4_000),
        ]]);
        $tools = new StandardLspTools($client, Workspace::fromPath($this->fixture));

        $completion = $tools->completion('src/Resource/App/Dashboard.php', 12, 40);

        self::assertSame('ok', $completion['status']);
        self::assertTrue($completion['data']['truncated']);
        $documentation = $completion['data']['items'][0]['documentation']['value'];
        self::assertSame(1, preg_match('//u', $documentation));
        self::assertLessThanOrEqual(8_192, strlen($documentation));
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

    public function testRetriesOneTransientInternalLspError(): void
    {
        $attempt = 0;
        $client = new InMemoryLspClient(function () use (&$attempt): array {
            if (++$attempt === 1) {
                throw new LspRpcException(-32603);
            }

            return [[
                'uri' => FileUri::fromPath($this->fixture . '/src/Resource/App/Dashboard.php'),
                'range' => self::range(12, 30, 12, 52),
            ]];
        });
        $tools = new StandardLspTools($client, Workspace::fromPath($this->fixture));

        $result = $tools->references('src/Resource/App/Dashboard.php', 12, 40);

        self::assertSame('ok', $result['status']);
        self::assertSame(2, $attempt);
        self::assertSame(
            ['textDocument/didOpen', 'textDocument/didClose', 'textDocument/didOpen', 'textDocument/didClose'],
            array_column($client->notifications, 'method'),
        );
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
