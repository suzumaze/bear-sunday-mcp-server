<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class BundledInstallTest extends TestCase
{
    public function testBundledPhpactorExposesBearSemanticsWithoutAnExternalBinary(): void
    {
        $root = dirname(__DIR__, 2);
        $client = Client::builder()
            ->setClientInfo('bear-sunday-mcp-bundle-test', '1.0.0')
            ->setMaxRetries(0)
            ->setInitTimeout(10)
            ->setRequestTimeout(30)
            ->build();
        $client->connect(new StdioTransport(
            PHP_BINARY,
            [
                $root . '/bin/bear-sunday-mcp',
                '--workspace=' . $root . '/tests/Fixture/workspace',
                '--timeout=20',
            ],
            $root,
        ));

        try {
            $project = $client->callTool('bear_project_info')->structuredContent;
            self::assertIsArray($project);
            self::assertSame('ok', $project['status']);
            self::assertSame('bear-semantic', $project['data']['semanticProtocol']);
            self::assertContains('bear/project/contractCoverage', $project['data']['requests']);
            self::assertContains('bear/di/bindings', $project['data']['requests']);
            self::assertContains('bear/aop/pointcuts', $project['data']['requests']);
            self::assertContains('contractCoverage', $project['data']['capabilities']);
            self::assertContains('diBindingInventory', $project['data']['capabilities']);
            self::assertContains('aopPointcutInventory', $project['data']['capabilities']);
            self::assertSame('v0.2.0', $project['data']['versions']['suzumaze/bear-phpactor-extension']);

            $coverage = $client->callTool('bear_contract_coverage', ['limit' => 1])->structuredContent;
            self::assertIsArray($coverage);
            self::assertSame('ok', $coverage['status']);

            $bindings = $client->callTool('bear_di_bindings')->structuredContent;
            self::assertIsArray($bindings);
            self::assertSame('ok', $bindings['status']);
            self::assertSame(1, $bindings['data']['total']);

            $pointcuts = $client->callTool('bear_aop_pointcuts')->structuredContent;
            self::assertIsArray($pointcuts);
            self::assertSame('ok', $pointcuts['status']);
            self::assertSame(1, $pointcuts['data']['total']);
        } finally {
            $client->disconnect();
        }
    }
}
