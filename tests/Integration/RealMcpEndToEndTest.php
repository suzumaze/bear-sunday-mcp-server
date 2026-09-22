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

            $project = $client->callTool('bear_project_info')->structuredContent;
            self::assertIsArray($project);
            self::assertSame(1, $project['data']['semanticApiVersion']);
            self::assertContains('projectDiagnostics', $project['data']['capabilities']);

            $diagnostics = $client->callTool('bear_project_diagnostics', [
                'limit' => 10,
                'offset' => 0,
            ])->structuredContent;
            self::assertIsArray($diagnostics);
            self::assertSame('ok', $diagnostics['status']);
            self::assertSame(2, $diagnostics['data']['total']);
            self::assertSame(0, $diagnostics['data']['offset']);
            self::assertFalse($diagnostics['data']['truncated']);
            self::assertSame(7, $diagnostics['data']['scannedFiles']);
            self::assertSame(3, $diagnostics['data']['scannedResources']);
            self::assertFalse($diagnostics['data']['resourceScanTruncated']);
            self::assertSame(['request_schema_references'], $diagnostics['data']['skippedChecks']);

            $coverage = $client->callTool('bear_contract_coverage', [
                'limit' => 10,
                'offset' => 0,
                'gapsOnly' => true,
            ])->structuredContent;
            self::assertIsArray($coverage);
            self::assertSame('ok', $coverage['status']);
            self::assertGreaterThan(0, $coverage['data']['total']);
            self::assertGreaterThan(0, $coverage['data']['matchingTotal']);
            self::assertSame(0, $coverage['data']['offset']);
            self::assertTrue($coverage['data']['gapsOnly']);
            self::assertSame($coverage['data']['total'], $coverage['data']['summary']['methods']);
            self::assertArrayHasKey('alps', $coverage['data']['items'][0]['surfaces']);

            $resources = $client->callTool('bear_resource_list', [
                'scheme' => 'app',
                'offset' => 0,
            ])->structuredContent;
            $resourcesAgain = $client->callTool('bear_resource_list', [
                'scheme' => 'app',
                'offset' => 0,
            ])->structuredContent;
            self::assertIsArray($resources);
            self::assertSame('ok', $resources['status']);
            self::assertSame(0, $resources['data']['offset']);
            self::assertSame(
                json_encode($resources, JSON_THROW_ON_ERROR),
                json_encode($resourcesAgain, JSON_THROW_ON_ERROR),
            );

            $resource = $client->callTool('bear_resource_describe', [
                'uri' => 'app://self/user',
            ])->structuredContent;
            self::assertIsArray($resource);
            self::assertSame('ok', $resource['status']);

            $attributes = $client->callTool('bear_resource_attributes', [
                'resourceUri' => 'app://self/dashboard',
            ])->structuredContent;
            self::assertIsArray($attributes);
            self::assertSame('ok', $attributes['status']);
            self::assertSame(['Embed', 'Link'], array_column($attributes['data']['attributes'], 'name'));
            self::assertSame('explicit_only', $attributes['data']['argumentPolicy']['source']);
            self::assertFalse($attributes['data']['argumentPolicy']['constructorDefaultsExpanded']);

            $attributeIndex = $client->callTool('bear_resource_attribute_index', [
                'scheme' => 'app',
                'prefix' => 'dashboard',
                'offset' => 0,
            ])->structuredContent;
            self::assertIsArray($attributeIndex);
            self::assertSame('ok', $attributeIndex['status']);
            self::assertSame(0, $attributeIndex['data']['offset']);
            self::assertSame('ok', $attributeIndex['data']['items'][0]['status']);
            self::assertSame(2, count($attributeIndex['data']['items'][0]['attributes']));

            $contract = $client->callTool('bear_contract_compare', [
                'resourceUri' => 'app://self/user',
                'descriptorId' => 'Article',
            ])->structuredContent;
            self::assertIsArray($contract);
            self::assertSame('ok', $contract['status']);
            self::assertSame(['schema', 'alps'], $contract['data']['comparison']['compared']);
            self::assertSame(['name'], $contract['data']['comparison']['onlyInSchema']);

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

            $missingResourceTemplate = $client->callTool('bear_template_for_resource', [
                'resourceUri' => 'app://self/dashboard',
                'engine' => 'twig',
            ])->structuredContent;
            self::assertIsArray($missingResourceTemplate);
            self::assertSame('not_found', $missingResourceTemplate['status']);
            self::assertArrayNotHasKey('data', $missingResourceTemplate);
            self::assertSame(
                'src/Resource/App/Dashboard.php',
                $missingResourceTemplate['partial']['resource']['path'],
            );
            self::assertArrayNotHasKey('path', $missingResourceTemplate['partial']);
            self::assertSame([
                'src/Resource/App/Dashboard.html.twig',
                'var/templates/App/Dashboard.html.twig',
            ], $missingResourceTemplate['partial']['searched']);

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

            $position = [
                'path' => 'src/Resource/App/Dashboard.php',
                'line' => 12,
                'character' => 40,
            ];
            $definition = $client->callTool('lsp_definition', $position)->structuredContent;
            self::assertIsArray($definition);
            self::assertSame('ok', $definition['status']);
            self::assertSame('src/Resource/App/User.php', $definition['data']['locations'][0]['path']);

            $position['includeDeclaration'] = true;
            $lspReferences = $client->callTool('lsp_references', $position)->structuredContent;
            self::assertIsArray($lspReferences);
            self::assertSame('ok', $lspReferences['status']);
            self::assertContains(
                'src/Resource/App/Dashboard.php',
                array_column($lspReferences['data']['locations'], 'path'),
            );
            self::assertContains(
                'src/Resource/App/User.php',
                array_column($lspReferences['data']['locations'], 'path'),
            );

            unset($position['includeDeclaration']);
            $hover = $client->callTool('lsp_hover', $position)->structuredContent;
            self::assertIsArray($hover);
            self::assertSame('ok', $hover['status']);
            self::assertSame('markdown', $hover['data']['contents']['kind']);
            self::assertStringContainsString('app://self/user', $hover['data']['contents']['value']);

            $completion = $client->callTool('lsp_completion', [
                'path' => 'src/Client.php',
                'line' => 11,
                'character' => 28,
            ])->structuredContent;
            self::assertIsArray($completion);
            self::assertSame('ok', $completion['status']);
            self::assertContains('app://self/user', array_column($completion['data']['items'], 'label'));

            $documentSymbols = $client->callTool('lsp_document_symbols', [
                'path' => 'src/Resource/App/Dashboard.php',
            ])->structuredContent;
            self::assertIsArray($documentSymbols);
            self::assertSame('ok', $documentSymbols['status']);
            self::assertSame(
                ['Dashboard', 'onGet'],
                array_column($documentSymbols['data']['symbols'], 'name'),
            );

            $workspaceSymbols = $client->callTool('lsp_workspace_symbols', [
                'query' => 'Dashboard',
            ])->structuredContent;
            self::assertIsArray($workspaceSymbols);
            self::assertSame('ok', $workspaceSymbols['status']);
            self::assertSame(
                'Acme\\Demo\\Resource\\App\\Dashboard',
                $workspaceSymbols['data']['symbols'][0]['name'],
            );
            self::assertSame(
                'src/Resource/App/Dashboard.php',
                $workspaceSymbols['data']['symbols'][0]['path'],
            );
            self::assertFalse($workspaceSymbols['data']['coverage']['includesMethods']);
            self::assertSame('unknown', $workspaceSymbols['data']['coverage']['indexFreshness']);
            self::assertSame('unknown', $workspaceSymbols['provenance'][0]['freshness']);

            $typeDefinition = $client->callTool('lsp_type_definition', [
                'path' => 'src/Resource/App/User.php',
                'line' => 8,
                'character' => 14,
            ])->structuredContent;
            self::assertIsArray($typeDefinition);
            self::assertSame('ok', $typeDefinition['status']);
            self::assertSame(
                'var/json_schema/user.json',
                $typeDefinition['data']['locations'][0]['path'],
            );

            $documentLinks = $client->callTool('lsp_document_links', [
                'path' => 'src/Resource/App/Dashboard.php',
            ])->structuredContent;
            self::assertIsArray($documentLinks);
            self::assertSame('ok', $documentLinks['status']);
            self::assertSame(2, $documentLinks['data']['total']);
            self::assertSame(
                ['src/Resource/App/User.php', 'src/Resource/App/User.php'],
                array_column($documentLinks['data']['links'], 'targetPath'),
            );
        } finally {
            $client->disconnect();
        }
    }
}
