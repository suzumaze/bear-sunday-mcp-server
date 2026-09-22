<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Mcp\Schema\Extension\Apps\McpApps;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\ContractCoverageUi;
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
            'capabilities' => [
                'extensions' => [
                    McpApps::EXTENSION_ID => [
                        'mimeTypes' => [McpApps::MIME_TYPE],
                    ],
                ],
            ],
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]);
        self::assertSame('2025-11-25', $initialize['result']['protocolVersion']);
        self::assertSame(Version::CURRENT, $initialize['result']['serverInfo']['version']);
        self::assertSame(
            ['mimeTypes' => [McpApps::MIME_TYPE]],
            $initialize['result']['capabilities']['extensions'][McpApps::EXTENSION_ID],
        );
        $this->notify('notifications/initialized');

        $listedResources = $this->request('resources/list', []);
        self::assertSame([
            'uri' => ContractCoverageUi::URI,
            'name' => 'bear-contract-coverage',
            'title' => 'BEAR Contract Coverage',
            'description' => 'Read-only visualization of JSON Schema and ALPS adoption facts.',
            'mimeType' => McpApps::MIME_TYPE,
            '_meta' => ['ui' => []],
        ], $listedResources['result']['resources'][0] ?? null);

        $coverageUi = $this->request('resources/read', ['uri' => ContractCoverageUi::URI]);
        $coverageUiContent = $coverageUi['result']['contents'][0] ?? [];
        self::assertSame(ContractCoverageUi::URI, $coverageUiContent['uri'] ?? null);
        self::assertSame(McpApps::MIME_TYPE, $coverageUiContent['mimeType'] ?? null);
        self::assertTrue($coverageUiContent['_meta']['ui']['prefersBorder'] ?? false);
        self::assertStringContainsString('ui/initialize', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('ui/notifications/tool-result', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('resourceScanTruncated', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('This is not a quality score.', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('No request fields', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString(
            'Absent means no contract artifact was observed, not that one is required.',
            $coverageUiContent['text'] ?? '',
        );
        self::assertStringContainsString('App URI methods', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('Page URI methods', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('Use offset', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('Show contract details for', $coverageUiContent['text'] ?? '');
        self::assertStringContainsString("request('ui/message'", $coverageUiContent['text'] ?? '');
        self::assertStringContainsString('Copy source path', $coverageUiContent['text'] ?? '');

        $listed = $this->request('tools/list', []);
        $tools = $listed['result']['tools'] ?? [];
        self::assertIsArray($tools);
        $names = array_column($tools, 'name');
        sort($names);
        self::assertSame([
            'bear_alps_descriptor_lookup',
            'bear_contract_compare',
            'bear_contract_coverage',
            'bear_project_diagnostics',
            'bear_project_info',
            'bear_resource_attribute_index',
            'bear_resource_attributes',
            'bear_resource_describe',
            'bear_resource_incoming_relations',
            'bear_resource_list',
            'bear_resource_references',
            'bear_route_lookup',
            'bear_schema_lookup',
            'bear_sql_lookup',
            'bear_template_for_resource',
            'bear_template_lookup',
            'lsp_completion',
            'lsp_definition',
            'lsp_document_links',
            'lsp_document_symbols',
            'lsp_hover',
            'lsp_references',
            'lsp_type_definition',
            'lsp_workspace_symbols',
        ], $names);
        foreach ($tools as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint'] ?? false);
            self::assertFalse($tool['annotations']['destructiveHint'] ?? true);
            self::assertFalse($tool['annotations']['openWorldHint'] ?? true);
        }
        $toolsByName = array_column($tools, null, 'name');
        self::assertStringContainsString(
            'Methods are excluded',
            $toolsByName['lsp_workspace_symbols']['description'],
        );
        self::assertStringContainsString(
            'omitted constructor defaults are not expanded',
            $toolsByName['bear_resource_attribute_index']['description'],
        );
        self::assertStringContainsString(
            'not_found has no success data',
            $toolsByName['bear_template_for_resource']['description'],
        );
        self::assertNotContains(
            'data',
            $toolsByName['bear_template_for_resource']['outputSchema']['required'],
        );
        self::assertArrayHasKey(
            'partial',
            $toolsByName['bear_template_for_resource']['outputSchema']['properties'],
        );
        self::assertStringContainsString(
            'skippedChecks',
            $toolsByName['bear_project_diagnostics']['description'],
        );
        self::assertStringContainsString(
            'not an error report or quality score',
            $toolsByName['bear_contract_coverage']['description'],
        );
        self::assertSame(
            100,
            $toolsByName['bear_contract_coverage']['inputSchema']['properties']['limit']['maximum'] ?? null,
        );
        self::assertArrayHasKey(
            'offset',
            $toolsByName['bear_contract_coverage']['inputSchema']['properties'],
        );
        self::assertArrayHasKey(
            'gapsOnly',
            $toolsByName['bear_contract_coverage']['inputSchema']['properties'],
        );
        self::assertSame(
            ['app', 'page'],
            $toolsByName['bear_contract_coverage']['inputSchema']['properties']['scheme']['enum'] ?? null,
        );
        self::assertArrayHasKey(
            'offset',
            $toolsByName['bear_resource_list']['inputSchema']['properties'],
        );
        self::assertArrayHasKey(
            'offset',
            $toolsByName['bear_resource_attribute_index']['inputSchema']['properties'],
        );
        self::assertSame(
            200,
            $toolsByName['bear_project_diagnostics']['inputSchema']['properties']['limit']['maximum'] ?? null,
        );
        self::assertSame(
            [
                'resourceUri' => ContractCoverageUi::URI,
                'visibility' => ['model'],
            ],
            $toolsByName['bear_contract_coverage']['_meta']['ui'] ?? null,
        );

        $diagnostics = $this->request('tools/call', [
            'name' => 'bear_project_diagnostics',
            'arguments' => ['limit' => 25, 'offset' => 10],
        ]);
        self::assertFalse($diagnostics['result']['isError'] ?? true);
        self::assertSame(
            'bear/project/diagnostics',
            $diagnostics['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['limit' => 25, 'offset' => 10],
            $diagnostics['result']['structuredContent']['data']['params'],
        );
        self::assertSame(
            $diagnostics['result']['structuredContent'],
            json_decode($diagnostics['result']['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR),
        );

        $coverage = $this->request('tools/call', [
            'name' => 'bear_contract_coverage',
            'arguments' => ['limit' => 25, 'offset' => 10, 'gapsOnly' => true, 'scheme' => 'page'],
        ]);
        self::assertFalse($coverage['result']['isError'] ?? true);
        self::assertSame(
            'bear/project/contractCoverage',
            $coverage['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['limit' => 25, 'offset' => 10, 'gapsOnly' => true, 'scheme' => 'page'],
            $coverage['result']['structuredContent']['data']['params'],
        );
        self::assertSame(
            $coverage['result']['structuredContent'],
            json_decode($coverage['result']['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR),
        );

        $called = $this->request('tools/call', [
            'name' => 'bear_resource_list',
            'arguments' => ['scheme' => 'app', 'prefix' => 'user', 'limit' => 10, 'offset' => 20],
        ]);
        self::assertFalse($called['result']['isError'] ?? true);
        self::assertSame('ok', $called['result']['structuredContent']['status']);
        self::assertSame(
            'bear/resource/list',
            $called['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['scheme' => 'app', 'prefix' => 'user', 'limit' => 10, 'offset' => 20],
            $called['result']['structuredContent']['data']['params'],
        );

        $attributes = $this->request('tools/call', [
            'name' => 'bear_resource_attributes',
            'arguments' => ['resourceUri' => 'app://self/dashboard'],
        ]);
        self::assertSame(
            'bear/resource/attributes',
            $attributes['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['uri' => 'app://self/dashboard', 'contextPath' => null],
            $attributes['result']['structuredContent']['data']['params'],
        );

        $attributeIndex = $this->request('tools/call', [
            'name' => 'bear_resource_attribute_index',
            'arguments' => ['scheme' => 'app', 'prefix' => 'dash', 'limit' => 10, 'offset' => 20],
        ]);
        self::assertSame(
            'bear/resource/attributeIndex',
            $attributeIndex['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['scheme' => 'app', 'prefix' => 'dash', 'limit' => 10, 'offset' => 20],
            $attributeIndex['result']['structuredContent']['data']['params'],
        );

        $contract = $this->request('tools/call', [
            'name' => 'bear_contract_compare',
            'arguments' => [
                'resourceUri' => 'app://self/user',
                'method' => 'onPost',
                'schemaKind' => 'request',
            ],
        ]);
        self::assertSame(
            'bear/contract/compare',
            $contract['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            [
                'uri' => 'app://self/user',
                'method' => 'onPost',
                'schemaKind' => 'request',
                'descriptorId' => null,
                'contextPath' => null,
            ],
            $contract['result']['structuredContent']['data']['params'],
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

        $references = $this->request('tools/call', [
            'name' => 'bear_resource_references',
            'arguments' => ['resourceUri' => 'app://self/user', 'limit' => 10],
        ]);
        self::assertSame(
            'bear/resource/references',
            $references['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            ['uri' => 'app://self/user', 'contextPath' => null, 'limit' => 10],
            $references['result']['structuredContent']['data']['params'],
        );

        $definition = $this->request('tools/call', [
            'name' => 'lsp_definition',
            'arguments' => [
                'path' => 'src/Resource/App/Dashboard.php',
                'line' => 12,
                'character' => 40,
            ],
        ]);
        self::assertSame('ok', $definition['result']['structuredContent']['status']);
        self::assertSame(
            'src/Resource/App/User.php',
            $definition['result']['structuredContent']['data']['locations'][0]['path'],
        );

        $typeDefinition = $this->request('tools/call', [
            'name' => 'lsp_type_definition',
            'arguments' => [
                'path' => 'src/Resource/App/User.php',
                'line' => 8,
                'character' => 14,
            ],
        ]);
        self::assertSame('ok', $typeDefinition['result']['structuredContent']['status']);
        self::assertSame(
            'var/json_schema/user.json',
            $typeDefinition['result']['structuredContent']['data']['locations'][0]['path'],
        );

        $completion = $this->request('tools/call', [
            'name' => 'lsp_completion',
            'arguments' => [
                'path' => 'src/Resource/App/Dashboard.php',
                'line' => 12,
                'character' => 40,
            ],
        ]);
        self::assertSame('ok', $completion['result']['structuredContent']['status']);
        self::assertSame(
            'app://self/user',
            $completion['result']['structuredContent']['data']['items'][0]['label'],
        );

        $documentSymbols = $this->request('tools/call', [
            'name' => 'lsp_document_symbols',
            'arguments' => ['path' => 'src/Resource/App/Dashboard.php'],
        ]);
        self::assertSame('ok', $documentSymbols['result']['structuredContent']['status']);
        self::assertSame(
            ['Dashboard', 'onGet'],
            array_column($documentSymbols['result']['structuredContent']['data']['symbols'], 'name'),
        );

        $documentLinks = $this->request('tools/call', [
            'name' => 'lsp_document_links',
            'arguments' => ['path' => 'src/Resource/App/Dashboard.php'],
        ]);
        self::assertSame('ok', $documentLinks['result']['structuredContent']['status']);
        self::assertSame(2, $documentLinks['result']['structuredContent']['data']['total']);
        self::assertSame(
            'src/Resource/App/User.php',
            $documentLinks['result']['structuredContent']['data']['links'][0]['targetPath'],
        );

        $workspaceSymbols = $this->request('tools/call', [
            'name' => 'lsp_workspace_symbols',
            'arguments' => ['query' => 'Dashboard'],
        ]);
        self::assertSame('ok', $workspaceSymbols['result']['structuredContent']['status']);
        self::assertSame(
            'src/Resource/App/Dashboard.php',
            $workspaceSymbols['result']['structuredContent']['data']['symbols'][0]['path'],
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

        $coverageWithoutApps = $this->request('tools/call', [
            'name' => 'bear_contract_coverage',
            'arguments' => ['limit' => 10],
        ]);
        self::assertFalse($coverageWithoutApps['result']['isError'] ?? true);
        self::assertSame(
            'bear/project/contractCoverage',
            $coverageWithoutApps['result']['structuredContent']['data']['method'],
        );
        self::assertSame(
            $coverageWithoutApps['result']['structuredContent'],
            json_decode($coverageWithoutApps['result']['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR),
        );

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

        $invalidReferenceLimit = $this->request('tools/call', [
            'name' => 'bear_resource_references',
            'arguments' => ['resourceUri' => 'app://self/user', 'limit' => 0],
        ]);
        self::assertSame(-32602, $invalidReferenceLimit['error']['code']);

        $invalidDiagnosticsLimit = $this->request('tools/call', [
            'name' => 'bear_project_diagnostics',
            'arguments' => ['limit' => 201],
        ]);
        self::assertSame(-32602, $invalidDiagnosticsLimit['error']['code']);

        $invalidCoverageLimit = $this->request('tools/call', [
            'name' => 'bear_contract_coverage',
            'arguments' => ['limit' => 101],
        ]);
        self::assertSame(-32602, $invalidCoverageLimit['error']['code']);

        $invalidCoverageOffset = $this->request('tools/call', [
            'name' => 'bear_contract_coverage',
            'arguments' => ['offset' => -1],
        ]);
        self::assertSame(-32602, $invalidCoverageOffset['error']['code']);

        $invalidContractKind = $this->request('tools/call', [
            'name' => 'bear_contract_compare',
            'arguments' => ['resourceUri' => 'app://self/user', 'schemaKind' => 'behavior'],
        ]);
        self::assertSame(-32602, $invalidContractKind['error']['code']);

        $invalidPosition = $this->request('tools/call', [
            'name' => 'lsp_hover',
            'arguments' => [
                'path' => 'src/Resource/App/Dashboard.php',
                'line' => -1,
                'character' => 0,
            ],
        ]);
        self::assertSame(-32602, $invalidPosition['error']['code']);

        $invalidCompletionLimit = $this->request('tools/call', [
            'name' => 'lsp_completion',
            'arguments' => [
                'path' => 'src/Resource/App/Dashboard.php',
                'line' => 12,
                'character' => 40,
                'limit' => 201,
            ],
        ]);
        self::assertSame(-32602, $invalidCompletionLimit['error']['code']);

        $invalidDocumentLinkLimit = $this->request('tools/call', [
            'name' => 'lsp_document_links',
            'arguments' => [
                'path' => 'src/Resource/App/Dashboard.php',
                'limit' => 0,
            ],
        ]);
        self::assertSame(-32602, $invalidDocumentLinkLimit['error']['code']);

        $invalidSymbolQuery = $this->request('tools/call', [
            'name' => 'lsp_workspace_symbols',
            'arguments' => ['query' => str_repeat('x', 513)],
        ]);
        self::assertSame(-32602, $invalidSymbolQuery['error']['code']);

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
