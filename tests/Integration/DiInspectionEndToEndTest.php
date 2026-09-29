<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

/** Exercises the real MCP -> Phpactor -> saved-source path against the matching extension checkout. */
final class DiInspectionEndToEndTest extends TestCase
{
    public function testInspectsContextsAndBindingEvidenceThroughRealMcp(): void
    {
        $extension = getenv('BEAR_MCP_TEST_EXTENSION_ROOT');
        if (!is_string($extension) || $extension === '') {
            self::markTestSkipped('Set BEAR_MCP_TEST_EXTENSION_ROOT to the matching extension checkout.');
        }
        $extension = realpath($extension);
        self::assertNotFalse($extension);
        $temporary = sys_get_temp_dir() . '/bear-di-mcp-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporary));
        $config = json_decode(
            (string) file_get_contents($extension . '/.phpactor.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $config['language_server_configuration.auto_config'] = false;
        $config['composer.enable'] = false;
        $config['indexer.enabled_watchers'] = [];
        $config['indexer.index_path'] = $temporary . '/index';
        $config['language_server.diagnostics_on_open'] = false;
        $config['language_server.diagnostics_on_update'] = false;
        $config['language_server.diagnostics_on_save'] = false;
        $wrapper = $temporary . '/phpactor';
        $bootstrap = <<<'PHP'
$argv = array_values(array_filter(
    $_SERVER['argv'],
    static fn (string $arg): bool => !str_starts_with($arg, '--config-extra='),
));
$argv[] = %s;
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);
putenv(%s);
putenv(%s);
require %s;
PHP;
        file_put_contents($wrapper, '#!' . PHP_BINARY . "\n<?php\n" . sprintf(
            $bootstrap,
            var_export('--config-extra=' . json_encode($config, JSON_THROW_ON_ERROR), true),
            var_export('XDG_CACHE_HOME=' . $temporary . '/cache', true),
            var_export('XDG_CONFIG_HOME=' . $temporary . '/config', true),
            var_export($extension . '/vendor/bin/phpactor', true),
        ));
        chmod($wrapper, 0700);
        $client = Client::builder()->setClientInfo('di-inspection-test', '1.0.0')
            ->setMaxRetries(0)->setInitTimeout(10)->setRequestTimeout(30)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, [
                dirname(__DIR__, 2) . '/bin/bear-sunday-mcp',
                '--workspace=' . $extension . '/tests/Fixture/DiComposition',
                '--phpactor=' . $wrapper,
                '--timeout=20',
            ], dirname(__DIR__, 2)));
            $contexts = $client->callTool('bear_app_context_list')->structuredContent;
            self::assertSame('ok', $contexts['status'], json_encode($contexts, JSON_THROW_ON_ERROR));
            self::assertSame(6, $contexts['data']['total']);
            self::assertSame(
                ['cli-app', 'dev-html-app', 'forwarded-app', 'override-app', 'prod-app', 'prod-html-app'],
                array_column($contexts['data']['items'], 'applicationContext'),
            );
            self::assertArrayNotHasKey('selectedContext', $contexts['data']); // LSP omits nulls on the wire.
            $sourceMap = $client->callTool('bear_di_module_tree_read')->structuredContent;
            self::assertSame('ok', $sourceMap['status']);
            self::assertSame('workspace_source_map', $sourceMap['data']['view']);
            self::assertGreaterThan(0, $sourceMap['data']['coverage']['totalModules']);
            $inspectNode = array_values(array_filter(
                $sourceMap['data']['modules'],
                static fn (array $module): bool => $module['module'] === 'Acme\\Shop\\Module\\InspectModule',
            ))[0];
            self::assertSame('src/Module/InspectModule.php', $inspectNode['path']);
            self::assertSame(12, $inspectNode['line']);
            self::assertSame(2, $inspectNode['bindingDeclarations']);
            self::assertSame(0, $inspectNode['interceptorDeclarations']);
            self::assertSame('bear/di/moduleDeclarations', $inspectNode['declarationsRequest']);
            $contextGraph = $client->callTool('bear_di_module_tree_read', [
                'applicationContext' => 'inspect-app',
            ])->structuredContent;
            self::assertSame('ok', $contextGraph['status']);
            self::assertArrayNotHasKey('view', $contextGraph['data']);
            self::assertSame('inspect-app', $contextGraph['data']['applicationContext']);
            $moduleSource = $client->callTool('bear_di_module_declarations', [
                'module' => 'Acme\\Shop\\Module\\InspectModule',
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('ok', $moduleSource['status']);
            self::assertSame('not_requested', $moduleSource['data']['contextMembership']['state']);
            self::assertGreaterThan(0, $moduleSource['data']['bindings']['total']);
            self::assertSame(0, $moduleSource['data']['pointcuts']['total']);
            self::assertSame('src/Module/InspectModule.php', $moduleSource['data']['bindings']['items'][0]['path']);
            self::assertSame(17, $moduleSource['data']['bindings']['items'][0]['line']);
            $moduleContext = $client->callTool('bear_di_module_declarations', [
                'module' => 'Acme\\Shop\\Module\\InspectModule',
                'applicationContext' => 'inspect-app',
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('present_in_workspace_graph', $moduleContext['data']['contextMembership']['state']);
            self::assertFalse($moduleContext['data']['coverage']['bindingWinnerResolved']);
            $bindings = $client->callTool('bear_di_binding_lookup', [
                'applicationContext' => 'inspect-app',
                'overridesOnly' => true,
                'resourcesOnly' => true,
            ])->structuredContent;
            self::assertSame('ok', $bindings['status']);
            self::assertSame(1, $bindings['data']['total']);
            $item = $bindings['data']['items'][0];
            self::assertSame('Acme\\Shop\\Resource\\Page\\Index', $item['type']);
            self::assertSame('source_selected', $item['selectionStatus']);
            self::assertSame('src/Module/InspectModule.php', $item['selected']['origin']['path']);
            self::assertNotEmpty($item['decisions']);
            $unknown = $client->callTool('bear_di_binding_lookup', [
                'applicationContext' => 'uncertain-app',
                'type' => 'Acme\\Shop\\Service\\ClockInterface',
            ])->structuredContent;
            self::assertSame('provisional', $unknown['data']['items'][0]['selectionStatus']);
            self::assertTrue($unknown['data']['coverage']['hasUnknowns']);
            $scalar = $client->callTool('bear_di_binding_lookup', [
                'applicationContext' => 'inspect-app', 'type' => '', 'name' => 'api-key',
            ])->structuredContent;
            self::assertSame('string', $scalar['data']['items'][0]['selected']['kind']);
            self::assertStringNotContainsString('fixture-private-value', json_encode($scalar, JSON_THROW_ON_ERROR));
            $allBindings = $client->callTool('bear_di_bindings', ['limit' => 100])->structuredContent;
            $bindingJson = json_encode($allBindings, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('targetExpression', $bindingJson);
            self::assertStringNotContainsString('constructorArguments', $bindingJson);
            self::assertStringNotContainsString('fixture-private-value', $bindingJson);
            $aop = $client->callTool('bear_aop_applications', [
                'applicationContext' => 'advice-app', 'uri' => 'app://self/advice',
                'attribute' => 'Acme\\Shop\\Annotation\\First',
            ])->structuredContent;
            self::assertSame('ok', $aop['status']);
            self::assertSame(1, $aop['data']['total']);
            self::assertSame('onGet', $aop['data']['items'][0]['method']);
            self::assertSame('Acme\\Shop\\Interceptor\\Priority', $aop['data']['items'][0]['chain'][0]['interceptor']);
            self::assertSame('provisional', $aop['data']['items'][0]['status']);
            $unknownSummary = $aop['data']['unknownSummary'];
            self::assertSame(1, $unknownSummary['filterMatchedMethods']);
            self::assertSame(
                $aop['data']['unknownTotal'],
                $unknownSummary['compositionOccurrences']
                    + $unknownSummary['resourceOccurrences']
                    + $unknownSummary['applicationOccurrences'],
            );
            self::assertArrayHasKey('items', $unknownSummary['groups']);
            self::assertGreaterThanOrEqual(0, $unknownSummary['groups']['total']);
            $helper = $client->callTool('bear_aop_applications', [
                'applicationContext' => 'advice-app', 'uri' => 'app://self/advice', 'method' => 'helper',
            ])->structuredContent;
            self::assertSame('ok', $helper['status']);
            self::assertSame(1, $helper['data']['total']);
            self::assertSame('helper', $helper['data']['items'][0]['method']);
            self::assertSame('exact_public_method', $helper['data']['coverage']['methodScope']);
            $catalog = $client->callTool('bear_attribute_catalog', [
                'applicationContext' => 'advice-app', 'attribute' => 'Acme\\Shop\\Annotation\\First',
            ])->structuredContent;
            self::assertSame('ok', $catalog['status']);
            self::assertSame(['method'], $catalog['data']['items'][0]['targets']);
            self::assertNotEmpty($catalog['data']['items'][0]['mechanisms']);
            self::assertStringNotContainsString('fixture-private-default', json_encode($catalog, JSON_THROW_ON_ERROR));
        } finally {
            $client->disconnect();
            $this->removeTree($temporary);
        }
    }

    public function testReadsKataModuleSourceAndContextOverlayThroughRealMcp(): void
    {
        $extension = getenv('BEAR_MCP_TEST_EXTENSION_ROOT');
        $kata = getenv('BEAR_MCP_TEST_KATA_ROOT');
        if (!is_string($extension) || $extension === '' || !is_string($kata) || $kata === '') {
            self::markTestSkipped(
                'Set BEAR_MCP_TEST_EXTENSION_ROOT and BEAR_MCP_TEST_KATA_ROOT to run the Kata source-only check.',
            );
        }
        $extension = realpath($extension);
        $kata = realpath($kata);
        self::assertNotFalse($extension);
        self::assertNotFalse($kata);
        $temporary = sys_get_temp_dir() . '/bear-kata-module-mcp-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporary));
        $config = json_decode(
            (string) file_get_contents($extension . '/.phpactor.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $config['language_server_configuration.auto_config'] = false;
        $config['composer.enable'] = false;
        $config['indexer.enabled_watchers'] = [];
        $config['indexer.index_path'] = $temporary . '/index';
        $config['language_server.diagnostics_on_open'] = false;
        $config['language_server.diagnostics_on_update'] = false;
        $config['language_server.diagnostics_on_save'] = false;
        $wrapper = $temporary . '/phpactor';
        $bootstrap = <<<'PHP'
$argv = array_values(array_filter(
    $_SERVER['argv'],
    static fn (string $arg): bool => !str_starts_with($arg, '--config-extra='),
));
$argv[] = %s;
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);
putenv(%s);
putenv(%s);
require %s;
PHP;
        file_put_contents($wrapper, '#!' . PHP_BINARY . "\n<?php\n" . sprintf(
            $bootstrap,
            var_export('--config-extra=' . json_encode($config, JSON_THROW_ON_ERROR), true),
            var_export('XDG_CACHE_HOME=' . $temporary . '/cache', true),
            var_export('XDG_CONFIG_HOME=' . $temporary . '/config', true),
            var_export($extension . '/vendor/bin/phpactor', true),
        ));
        chmod($wrapper, 0700);
        $client = Client::builder()->setClientInfo('kata-module-declarations-test', '1.0.0')
            ->setMaxRetries(0)->setInitTimeout(60)->setRequestTimeout(60)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, [
                dirname(__DIR__, 2) . '/bin/bear-sunday-mcp',
                '--workspace=' . $kata,
                '--phpactor=' . $wrapper,
                '--timeout=60',
            ], dirname(__DIR__, 2)));
            $module = 'BEAR\\Kata\\Module\\AppModule';
            $source = $client->callTool('bear_di_module_declarations', [
                'module' => $module,
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('ok', $source['status'], json_encode($source, JSON_THROW_ON_ERROR));
            self::assertSame('not_requested', $source['data']['contextMembership']['state']);
            self::assertGreaterThan(0, $source['data']['bindings']['total']);
            self::assertSame(0, $source['data']['pointcuts']['total']);
            self::assertSame('src/Module/AppModule.php', $source['data']['bindings']['items'][0]['path']);
            self::assertSame(84, $source['data']['bindings']['items'][0]['line']);
            self::assertSame('direct_workspace_source', $source['data']['coverage']['declarations']);

            $contextual = $client->callTool('bear_di_module_declarations', [
                'module' => $module,
                'applicationContext' => 'test-hal-api-app',
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('ok', $contextual['status'], json_encode($contextual, JSON_THROW_ON_ERROR));
            self::assertSame('test-hal-api-app', $contextual['data']['contextMembership']['applicationContext']);
            self::assertSame('present_in_workspace_graph', $contextual['data']['contextMembership']['state']);
            self::assertSame('saved_workspace_module_graph', $contextual['data']['coverage']['contextMembership']);
            self::assertFalse($contextual['data']['coverage']['bindingWinnerResolved']);

            $fakeModule = 'BEAR\\Kata\\Module\\FakeModule';
            $fakeSource = $client->callTool('bear_di_module_declarations', [
                'module' => $fakeModule,
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('ok', $fakeSource['status'], json_encode($fakeSource, JSON_THROW_ON_ERROR));
            self::assertGreaterThan(0, $fakeSource['data']['bindings']['total']);
            self::assertSame('not_requested', $fakeSource['data']['contextMembership']['state']);
            $fakeContextual = $client->callTool('bear_di_module_declarations', [
                'module' => $fakeModule,
                'applicationContext' => 'test-hal-api-app',
                'limit' => 2,
            ])->structuredContent;
            self::assertSame('ok', $fakeContextual['status'], json_encode($fakeContextual, JSON_THROW_ON_ERROR));
            self::assertSame('present_in_workspace_graph', $fakeContextual['data']['contextMembership']['state']);
            self::assertSame(['install'], array_column($fakeContextual['data']['contextMembership']['route'], 'kind'));
            self::assertSame(
                'src/Module/TestModule.php',
                $fakeContextual['data']['contextMembership']['route'][0]['path'],
            );
            self::assertSame(0, $fakeContextual['data']['pointcuts']['total']);
            foreach ([$source, $contextual, $fakeSource, $fakeContextual] as $response) {
                $encoded = json_encode($response, JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('targetExpression', $encoded);
                self::assertStringNotContainsString('constructorArguments', $encoded);
                self::assertStringNotContainsString('host=127.0.0.1', $encoded);
            }
        } finally {
            $client->disconnect();
            $this->removeTree($temporary);
        }
    }

    private function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
