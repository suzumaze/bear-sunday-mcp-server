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
