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
