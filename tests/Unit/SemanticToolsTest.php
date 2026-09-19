<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
use Suzumaze\BearSundayMcp\Lsp\LspTimeoutException;
use Suzumaze\BearSundayMcp\SemanticTools;
use Suzumaze\BearSundayMcp\Tests\Support\InMemoryLspClient;

#[CoversClass(SemanticTools::class)]
final class SemanticToolsTest extends TestCase
{
    public function testMapsTheFourM1ToolsToSemanticApiV1WithoutChangingResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);

        self::assertSame(self::projectInfoResult(), $tools->projectInfo());
        self::assertSame(
            self::ok([
                'method' => 'bear/resource/list',
                'params' => ['scheme' => 'app', 'prefix' => 'user', 'limit' => 25],
            ]),
            $tools->resourceList('app', 'user', 25),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/resource/describe',
                'params' => [
                    'uri' => 'app://self/user',
                    'contextPath' => 'src/Resource/App/User.php',
                    'incomingLimit' => 10,
                ],
            ]),
            $tools->resourceDescribe('app://self/user', 'src/Resource/App/User.php', 10),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/schema/describeForResource',
                'params' => [
                    'uri' => 'app://self/user',
                    'kind' => 'response',
                    'contextPath' => null,
                ],
            ]),
            $tools->schemaLookup('app://self/user'),
        );
    }

    public function testMapsM2NavigationToolsToSemanticApiV1WithoutChangingResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);
        $tools->projectInfo();

        self::assertSame(
            self::ok([
                'method' => 'bear/route/resolve',
                'params' => ['route' => '/thing/detail', 'contextPath' => 'aura.route.php'],
            ]),
            $tools->routeLookup('/thing/detail', 'aura.route.php'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/sql/resolve',
                'params' => ['queryId' => 'point_distance', 'contextPath' => null],
            ]),
            $tools->sqlLookup('point_distance'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/template/resolve',
                'params' => [
                    'engine' => 'qiq',
                    'name' => './sibling',
                    'contextPath' => 'var/qiq/template/Page/Nested/RelativeReferences.php',
                ],
            ]),
            $tools->templateLookup(
                'qiq',
                './sibling',
                'var/qiq/template/Page/Nested/RelativeReferences.php',
            ),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/template/forResource',
                'params' => [
                    'uri' => 'app://self/user',
                    'engine' => 'twig',
                    'contextPath' => null,
                ],
            ]),
            $tools->templateForResource('app://self/user', 'twig'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/alps/describeDescriptor',
                'params' => ['descriptorId' => 'goArticle', 'contextPath' => null],
            ]),
            $tools->alpsDescriptorLookup('goArticle'),
        );
    }

    public function testMapsM2ReferenceToolsToSemanticApiV1WithoutChangingResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);
        $tools->projectInfo();

        self::assertSame(
            self::ok([
                'method' => 'bear/resource/references',
                'params' => [
                    'uri' => 'app://self/user',
                    'contextPath' => 'src/Resource/App/Dashboard.php',
                    'limit' => 25,
                ],
            ]),
            $tools->resourceReferences('app://self/user', 'src/Resource/App/Dashboard.php', 25),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/resource/incomingRelations',
                'params' => [
                    'uri' => 'app://self/user',
                    'contextPath' => null,
                    'limit' => 50,
                ],
            ]),
            $tools->resourceIncomingRelations('app://self/user'),
        );
    }

    public function testMapsAttributeAndContractToolsWithoutChangingSemanticResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);
        $tools->projectInfo();

        self::assertSame(
            self::ok([
                'method' => 'bear/resource/attributes',
                'params' => [
                    'uri' => 'app://self/dashboard',
                    'contextPath' => 'src/Resource/App/Dashboard.php',
                ],
            ]),
            $tools->resourceAttributes('app://self/dashboard', 'src/Resource/App/Dashboard.php'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/resource/attributeIndex',
                'params' => ['scheme' => 'app', 'prefix' => 'dash', 'limit' => 25],
            ]),
            $tools->resourceAttributeIndex('app', 'dash', 25),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/contract/compare',
                'params' => [
                    'uri' => 'app://self/user',
                    'method' => 'onPost',
                    'schemaKind' => 'request',
                    'descriptorId' => 'createUser',
                    'contextPath' => 'src/Resource/App/User.php',
                ],
            ]),
            $tools->contractCompare(
                'app://self/user',
                'onPost',
                'request',
                'createUser',
                'src/Resource/App/User.php',
            ),
        );
    }

    public function testPreservesPartialNotFoundDataForAMissingResourceTemplate(): void
    {
        $partial = [
            'status' => 'not_found',
            'data' => null,
            'partial' => [
                'resource' => [
                    'uri' => 'app://self/dashboard',
                    'fqn' => 'Acme\\Resource\\App\\Dashboard',
                    'path' => 'src/Resource/App/Dashboard.php',
                ],
                'engine' => 'twig',
                'path' => null,
                'searched' => ['var/templates/App/Dashboard.html.twig'],
            ],
            'candidates' => [],
            'provenance' => [[
                'source' => 'file',
                'path' => 'src/Resource/App/Dashboard.php',
                'freshness' => 'saved',
            ]],
            'error' => [
                'code' => 'semantic_not_found',
                'message' => 'No semantic target was found.',
            ],
        ];
        $client = new InMemoryLspClient(static fn (string $method): array =>
            $method === 'bear/project/info' ? self::projectInfoResult() : $partial);
        $tools = new SemanticTools($client);

        self::assertSame($partial, $tools->templateForResource('app://self/dashboard', 'twig'));
    }

    public function testPreflightsTheApiOnlyOnceWhenProjectInfoWasNotCalled(): void
    {
        $client = new InMemoryLspClient(static fn (string $method): array => $method === 'bear/project/info'
            ? self::projectInfoResult()
            : self::ok([]));
        $tools = new SemanticTools($client);

        $tools->resourceList();
        $tools->resourceList();

        self::assertSame(
            ['bear/project/info', 'bear/resource/list', 'bear/resource/list'],
            array_column($client->requests, 'method'),
        );
    }

    public function testRejectsAnUnsupportedSemanticApiMajorVersion(): void
    {
        $client = new InMemoryLspClient(static fn (): array => self::ok(['semanticApiVersion' => 2]));

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('unsupported', $result['status']);
        self::assertSame('unsupported_semantic_api_version', $result['error']['code']);
    }

    public function testMapsMethodNotFoundToEngineUnavailable(): void
    {
        $client = new InMemoryLspClient(static function (): array {
            throw new LspRpcException(-32601);
        });

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('engine_unavailable', $result['status']);
        self::assertSame('semantic_api_unavailable', $result['error']['code']);
    }

    public function testMapsTimeoutWithoutLeakingAnException(): void
    {
        $client = new InMemoryLspClient(static function (): array {
            throw new LspTimeoutException('sensitive child process details');
        });

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('timeout', $result['status']);
        self::assertSame('lsp_timeout', $result['error']['code']);
        self::assertStringNotContainsString('sensitive', $result['error']['message']);
    }

    public function testRejectsAnInvalidLspEnvelope(): void
    {
        $client = new InMemoryLspClient(static fn (): array => ['status' => 'invented']);

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('parse_error', $result['status']);
        self::assertSame('invalid_lsp_response', $result['error']['code']);
    }

    /** @return array<string, mixed> */
    private static function projectInfoResult(): array
    {
        return self::ok([
            'semanticApiVersion' => 1,
            'workspaceName' => 'fixture',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function ok(array $data): array
    {
        return [
            'status' => 'ok',
            'data' => $data,
            'candidates' => [],
            'provenance' => [],
        ];
    }
}
