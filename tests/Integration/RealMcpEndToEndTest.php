<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

final class RealMcpEndToEndTest extends TestCase
{
    public function testRunsAllAvailableToolsThroughMcpAndARealPhpactorProcess(): void
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
                'bear_alps_descriptor_lookup',
                'bear_project_info',
                'bear_resource_describe',
                'bear_resource_incoming_relations',
                'bear_resource_list',
                'bear_resource_references',
                'bear_route_lookup',
                'bear_schema_lookup',
                'bear_sql_lookup',
                'bear_template_for_resource',
                'bear_template_lookup',
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

            $route = $client->callTool('bear_route_lookup', [
                'route' => '/thing/detail',
                'contextPath' => 'aura.route.php',
            ])->structuredContent;
            self::assertIsArray($route);
            self::assertSame('ok', $route['status']);
            self::assertSame('page://self/thing/detail', $route['data']['resource']['uri']);
            self::assertSame('src/Resource/Page/Thing/Detail.php', $route['data']['resource']['path']);

            $sql = $client->callTool('bear_sql_lookup', [
                'queryId' => 'point_distance',
                'contextPath' => 'src/Resource/App/User.php',
            ])->structuredContent;
            self::assertIsArray($sql);
            self::assertSame('ok', $sql['status']);
            self::assertSame('var/db/sql/point_distance.sql', $sql['data']['path']);

            $template = $client->callTool('bear_template_lookup', [
                'engine' => 'twig',
                'name' => 'App/User.html.twig',
            ])->structuredContent;
            self::assertIsArray($template);
            self::assertSame('ok', $template['status']);
            self::assertSame('var/templates/App/User.html.twig', $template['data']['path']);

            $resourceTemplate = $client->callTool('bear_template_for_resource', [
                'resourceUri' => 'app://self/user',
                'engine' => 'qiq',
            ])->structuredContent;
            self::assertIsArray($resourceTemplate);
            self::assertSame('ok', $resourceTemplate['status']);
            self::assertSame('var/qiq/template/App/User.php', $resourceTemplate['data']['path']);

            $alps = $client->callTool('bear_alps_descriptor_lookup', [
                'descriptorId' => 'goArticle',
                'contextPath' => 'src/Resource/App/User.php',
            ])->structuredContent;
            self::assertIsArray($alps);
            self::assertSame('ok', $alps['status']);
            self::assertSame('safe', $alps['data']['type']);
            self::assertSame('Article', $alps['data']['relationsOut'][0]['targetId']);
            self::assertSame('Article', $alps['data']['relationsIn'][0]['sourceId']);

            $references = $client->callTool('bear_resource_references', [
                'resourceUri' => 'app://self/user',
            ])->structuredContent;
            self::assertIsArray($references);
            self::assertSame('ok', $references['status']);
            self::assertSame(2, $references['data']['total']);
            self::assertFalse($references['data']['truncated']);
            self::assertSame(
                ['app://self/user{?id}', 'app://self/user'],
                array_column($references['data']['references'], 'identifier'),
            );
            self::assertSame(
                ['src/Resource/App/Dashboard.php', 'src/Resource/App/Dashboard.php'],
                array_column($references['data']['references'], 'path'),
            );

            $relations = $client->callTool('bear_resource_incoming_relations', [
                'resourceUri' => 'app://self/user',
            ])->structuredContent;
            self::assertIsArray($relations);
            self::assertSame('ok', $relations['status']);
            self::assertTrue($relations['data']['available']);
            self::assertSame(2, $relations['data']['total']);
            self::assertSame(['embed', 'link'], array_column($relations['data']['items'], 'kind'));
            self::assertSame(
                ['app://self/dashboard', 'app://self/dashboard'],
                array_column($relations['data']['items'], 'sourceUri'),
            );
        } finally {
            $client->disconnect();
        }
    }
}
