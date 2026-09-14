<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class RealMcpEndToEndTest extends TestCase
{
    public function testRunsAllM1ToolsThroughMcpAndARealPhpactorProcess(): void
    {
        $phpactor = getenv('BEAR_MCP_TEST_PHPACTOR');
        if (!is_string($phpactor) || $phpactor === '') {
            self::markTestSkipped('Set BEAR_MCP_TEST_PHPACTOR to run the real MCP end-to-end test.');
        }

        $root = dirname(__DIR__, 2);
        $client = Client::builder()
            ->setClientInfo('bear-sunday-mcp-phpunit', '1.0.0')
            ->setMaxRetries(0)
            ->setInitTimeout(10)
            ->setRequestTimeout(30)
            ->build();
        $client->connect(new StdioTransport(
            PHP_BINARY,
            [
                $root . '/bin/bear-sunday-mcp',
                '--workspace=' . $root . '/tests/Fixture/workspace',
                '--phpactor=' . $phpactor,
                '--timeout=20',
            ],
            $root,
        ));

        try {
            $names = array_map(static fn ($tool): string => $tool->name, $client->listTools()->tools);
            sort($names);
            self::assertSame([
                'bear_project_info',
                'bear_resource_describe',
                'bear_resource_list',
                'bear_schema_lookup',
            ], $names);

            $project = $client->callTool('bear_project_info')->structuredContent;
            self::assertIsArray($project);
            self::assertSame(1, $project['data']['semanticApiVersion']);

            $resources = $client->callTool('bear_resource_list', ['scheme' => 'app'])->structuredContent;
            $resourcesAgain = $client->callTool('bear_resource_list', ['scheme' => 'app'])->structuredContent;
            self::assertIsArray($resources);
            self::assertSame('ok', $resources['status']);
            self::assertSame(
                json_encode($resources, JSON_THROW_ON_ERROR),
                json_encode($resourcesAgain, JSON_THROW_ON_ERROR),
            );

            $resource = $client->callTool('bear_resource_describe', [
                'uri' => 'app://self/user',
            ])->structuredContent;
            self::assertIsArray($resource);
            self::assertSame('ok', $resource['status']);

            $schema = $client->callTool('bear_schema_lookup', [
                'resourceUri' => 'app://self/user',
            ])->structuredContent;
            self::assertIsArray($schema);
            self::assertContains($schema['status'], ['ok', 'not_found']);
        } finally {
            $client->disconnect();
        }
    }
}
