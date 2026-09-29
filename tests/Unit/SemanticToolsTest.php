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
    public function testRetriesDiscoveryAfterATransientFailure(): void
    {
        $infoCalls = 0;
        $client = new InMemoryLspClient(static function (string $method) use (&$infoCalls): array {
            if ($method === 'bear/project/info' && ++$infoCalls === 1) {
                return [
                    'status' => 'timeout',
                    'error' => ['code' => 'lsp_timeout', 'message' => 'Phpactor timed out.'],
                    'candidates' => [],
                    'provenance' => [],
                ];
            }
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method]);
        });
        $tools = new SemanticTools($client);

        self::assertSame('timeout', $tools->resourceList()['status']);
        self::assertSame('ok', $tools->resourceList()['status']);
        self::assertSame(
            ['bear/project/info', 'bear/project/info', 'bear/resource/list'],
            array_column($client->requests, 'method'),
        );
    }

    public function testMapsProjectDiagnosticsWithoutChangingSemanticResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);

        self::assertSame(
            self::ok([
                'method' => 'bear/project/diagnostics',
                'params' => ['limit' => 25, 'offset' => 10],
            ]),
            $tools->projectDiagnostics(25, 10),
        );
        self::assertSame(
            ['bear/project/info', 'bear/project/diagnostics'],
            array_column($client->requests, 'method'),
        );
    }

    public function testMapsContractCoverageWithoutChangingSemanticResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);

        self::assertSame(
            self::ok([
                'method' => 'bear/project/contractCoverage',
                'params' => ['limit' => 25, 'offset' => 10, 'gapsOnly' => true],
            ]),
            $tools->contractCoverage(25, 10, true),
        );
        self::assertSame(
            ['bear/project/info', 'bear/project/contractCoverage'],
            array_column($client->requests, 'method'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/project/contractCoverage',
                'params' => ['limit' => 5, 'offset' => 0, 'gapsOnly' => true, 'scheme' => 'page'],
            ]),
            $tools->contractCoverage(5, 0, true, 'page'),
        );
    }

    public function testMapsDiAndAopInventoriesWithoutChangingSemanticResults(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info') {
                return self::projectInfoResult();
            }

            return self::ok(['method' => $method, 'params' => $params]);
        });
        $tools = new SemanticTools($client);

        self::assertSame(
            self::ok([
                'method' => 'bear/di/bindings',
                'params' => [
                    'limit' => 25,
                    'offset' => 10,
                    'type' => 'App\\ClockInterface',
                    'applicationContext' => 'dev-html-app',
                ],
            ]),
            $tools->diBindings('App\\ClockInterface', 25, 10, 'dev-html-app'),
        );
        self::assertSame(
            self::ok([
                'method' => 'bear/aop/pointcuts',
                'params' => [
                    'limit' => 20,
                    'offset' => 5,
                    'interceptor' => 'App\\AuditInterceptor',
                    'applicationContext' => 'dev-html-app',
                ],
            ]),
            $tools->aopPointcuts('App\\AuditInterceptor', 20, 5, 'dev-html-app'),
        );
        self::assertSame(
            self::ok(['method' => 'bear/di/moduleGraph', 'params' => []]),
            $tools->diModuleGraph(),
        );
        self::assertSame(
            ['bear/project/info', 'bear/di/bindings', 'bear/aop/pointcuts', 'bear/di/moduleGraph'],
            array_column($client->requests, 'method'),
        );
    }

    public function testDoesNotSendContextScopeToAnExtensionThatDoesNotAdvertiseIt(): void
    {
        $client = new InMemoryLspClient(static function (string $method): array {
            if ($method === 'bear/project/info') {
                $result = self::projectInfoResult();
                $result['data']['capabilities'] = [];

                return $result;
            }

            return self::ok([]);
        });
        $tools = new SemanticTools($client);

        $result = $tools->diBindings(applicationContext: 'dev-html-app');

        self::assertSame('unsupported', $result['status']);
        self::assertSame('semantic_capability_unavailable', $result['error']['code']);
        self::assertSame(['bear/project/info'], array_column($client->requests, 'method'));
    }

    public function testModuleDeclarationListOffsetsFailClosedWhenTheEngineIgnoresThem(): void
    {
        $tools = static function (bool $honorsListOffsets): SemanticTools {
            return new SemanticTools(new InMemoryLspClient(
                static function (string $method, array $params) use ($honorsListOffsets): array {
                    if ($method === 'bear/project/info') {
                        $result = self::projectInfoResult();
                        $result['data']['requests'][] = 'bear/di/moduleDeclarations';

                        return $result;
                    }
                    $offset = $params['offset'];

                    return self::ok([
                        'bindings' => ['offset' => $honorsListOffsets ? $params['bindingsOffset'] ?? $offset : $offset],
                        'pointcuts' => ['offset' => $honorsListOffsets ? $params['pointcutsOffset'] ?? $offset : $offset],
                    ]);
                },
            ));
        };

        $older = $tools(false)->diModuleDeclarations('App\\Module\\AppModule', bindingsOffset: 29, pointcutsOffset: 0);
        self::assertSame('unsupported', $older['status']);
        self::assertSame('semantic_parameter_unsupported', $older['error']['code']);
        self::assertSame('ok', $tools(false)->diModuleDeclarations('App\\Module\\AppModule', offset: 10)['status']);

        $current = $tools(true)->diModuleDeclarations('App\\Module\\AppModule', bindingsOffset: 29, pointcutsOffset: 0);
        self::assertSame('ok', $current['status']);
        self::assertSame(29, $current['data']['bindings']['offset']);
    }

    public function testNewInspectionToolsFailClosedOnOlderEngines(): void
    {
        $client = new InMemoryLspClient(static fn (): array => self::projectInfoResult());
        $tools = new SemanticTools($client);
        self::assertSame('unsupported', $tools->appContextList()['status']);
        self::assertSame('unsupported', $tools->diBindingLookup('prod-html-app')['status']);
        self::assertSame('unsupported', $tools->aopApplications('prod-html-app')['status']);
        self::assertSame('unsupported', $tools->attributeCatalog()['status']);
        self::assertSame(['bear/project/info'], array_column($client->requests, 'method'));
    }

    public function testMapsTheFourM1ToolsWithoutChangingSemanticResults(): void
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
                'params' => ['scheme' => 'app', 'prefix' => 'user', 'limit' => 25, 'offset' => 10],
            ]),
            $tools->resourceList('app', 'user', 25, 10),
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

    public function testMapsM2NavigationToolsWithoutChangingSemanticResults(): void
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

    public function testMapsM2ReferenceToolsWithoutChangingSemanticResults(): void
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
                'params' => ['scheme' => 'app', 'prefix' => 'dash', 'limit' => 25, 'offset' => 10],
            ]),
            $tools->resourceAttributeIndex('app', 'dash', 25, 10),
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

    public function testFailedContextSpecificProjectInfoDoesNotPoisonDefaultPreflight(): void
    {
        $client = new InMemoryLspClient(static function (string $method, array $params): array {
            if ($method === 'bear/project/info' && ($params['contextPath'] ?? null) === 'missing.php') {
                return [
                    'status' => 'not_found',
                    'data' => null,
                    'candidates' => [],
                    'provenance' => [],
                    'error' => ['code' => 'semantic_not_found', 'message' => 'Not found.'],
                ];
            }

            return $method === 'bear/project/info' ? self::projectInfoResult() : self::ok([]);
        });
        $tools = new SemanticTools($client);

        self::assertSame('not_found', $tools->projectInfo('missing.php')['status']);
        self::assertSame('ok', $tools->resourceList()['status']);
        self::assertSame(
            ['bear/project/info', 'bear/project/info', 'bear/resource/list'],
            array_column($client->requests, 'method'),
        );
    }

    public function testRejectsAnUnsupportedSemanticProtocol(): void
    {
        $client = new InMemoryLspClient(static fn (): array => self::ok([
            'semanticProtocol' => 'other-semantic-protocol',
            'requests' => [],
        ]));

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('unsupported', $result['status']);
        self::assertSame('unsupported_semantic_protocol', $result['error']['code']);
    }

    public function testRejectsARequestThatWasNotAdvertised(): void
    {
        $client = new InMemoryLspClient(static fn (): array => self::ok([
            'semanticProtocol' => 'bear-semantic',
            'requests' => ['bear/project/info'],
        ]));

        $result = (new SemanticTools($client))->resourceList();

        self::assertSame('unsupported', $result['status']);
        self::assertSame('semantic_request_unavailable', $result['error']['code']);
        self::assertSame(['bear/project/info'], array_column($client->requests, 'method'));
    }

    public function testRejectsInvalidRequestDiscovery(): void
    {
        $client = new InMemoryLspClient(static fn (): array => self::ok([
            'semanticProtocol' => 'bear-semantic',
            'requests' => ['bear/project/info', 42],
        ]));

        $result = (new SemanticTools($client))->projectInfo();

        self::assertSame('parse_error', $result['status']);
        self::assertSame('invalid_semantic_discovery', $result['error']['code']);
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
            'semanticProtocol' => 'bear-semantic',
            'requests' => [
                'bear/project/info',
                'bear/project/diagnostics',
                'bear/project/contractCoverage',
                'bear/di/bindings',
                'bear/di/moduleGraph',
                'bear/aop/pointcuts',
                'bear/resource/list',
                'bear/resource/describe',
                'bear/resource/attributes',
                'bear/resource/attributeIndex',
                'bear/resource/references',
                'bear/resource/incomingRelations',
                'bear/contract/compare',
                'bear/schema/describeForResource',
                'bear/route/resolve',
                'bear/sql/resolve',
                'bear/template/resolve',
                'bear/template/forResource',
                'bear/alps/describeDescriptor',
            ],
            'workspaceName' => 'fixture',
            'capabilities' => ['contextScopedDiAopInventory', 'diModuleGraph'],
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
