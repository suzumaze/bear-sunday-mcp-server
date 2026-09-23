<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Suzumaze\BearSundayMcp\Lsp\LspException;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
use Suzumaze\BearSundayMcp\Lsp\LspTimeoutException;
use Suzumaze\BearSundayMcp\Lsp\SemanticLspClient;

final class SemanticTools
{
    private const SEMANTIC_PROTOCOL = 'bear-semantic';
    private const STATUSES = [
        'ok',
        'not_found',
        'ambiguous',
        'invalid_input',
        'unsupported',
        'parse_error',
        'engine_unavailable',
        'outside_workspace',
        'timeout',
    ];

    private bool $preflightComplete = false;

    /** @var array<string, mixed>|null */
    private ?array $preflightFailure = null;

    /** @var array<string, true> */
    private array $availableRequests = [];

    public function __construct(private readonly SemanticLspClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function projectInfo(?string $contextPath = null): array
    {
        $result = $this->forward('bear/project/info', ['contextPath' => $contextPath]);
        $checked = $this->checkDiscovery($result);
        if (($checked['status'] ?? null) === 'ok') {
            $this->rememberDiscovery($checked);
        }

        return $checked;
    }

    /** @return array<string, mixed> */
    public function projectDiagnostics(int $limit = 100, int $offset = 0): array
    {
        return $this->query('bear/project/diagnostics', [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @return array<string, mixed> */
    public function contractCoverage(
        int $limit = 100,
        int $offset = 0,
        bool $gapsOnly = false,
        ?string $scheme = null,
    ): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
            'gapsOnly' => $gapsOnly,
        ];
        if ($scheme !== null) {
            $params['scheme'] = $scheme;
        }

        return $this->query('bear/project/contractCoverage', $params);
    }

    /** @return array<string, mixed> */
    public function resourceList(
        ?string $scheme = null,
        string $prefix = '',
        int $limit = 50,
        int $offset = 0,
    ): array {
        return $this->query('bear/resource/list', [
            'scheme' => $scheme,
            'prefix' => $prefix,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @return array<string, mixed> */
    public function resourceDescribe(
        string $uri,
        ?string $contextPath = null,
        int $incomingLimit = 50,
    ): array {
        return $this->query('bear/resource/describe', [
            'uri' => $uri,
            'contextPath' => $contextPath,
            'incomingLimit' => $incomingLimit,
        ]);
    }

    /** @return array<string, mixed> */
    public function resourceAttributes(string $resourceUri, ?string $contextPath = null): array
    {
        return $this->query('bear/resource/attributes', [
            'uri' => $resourceUri,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function resourceAttributeIndex(
        ?string $scheme = null,
        string $prefix = '',
        int $limit = 50,
        int $offset = 0,
    ): array {
        return $this->query('bear/resource/attributeIndex', [
            'scheme' => $scheme,
            'prefix' => $prefix,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** @return array<string, mixed> */
    public function contractCompare(
        string $resourceUri,
        string $method = 'onGet',
        string $schemaKind = 'response',
        ?string $descriptorId = null,
        ?string $contextPath = null,
    ): array {
        return $this->query('bear/contract/compare', [
            'uri' => $resourceUri,
            'method' => $method,
            'schemaKind' => $schemaKind,
            'descriptorId' => $descriptorId,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function schemaLookup(
        string $resourceUri,
        string $kind = 'response',
        ?string $contextPath = null,
    ): array {
        return $this->query('bear/schema/describeForResource', [
            'uri' => $resourceUri,
            'kind' => $kind,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function routeLookup(string $route, ?string $contextPath = null): array
    {
        return $this->query('bear/route/resolve', [
            'route' => $route,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function sqlLookup(string $queryId, ?string $contextPath = null): array
    {
        return $this->query('bear/sql/resolve', [
            'queryId' => $queryId,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function templateLookup(string $engine, string $name, ?string $contextPath = null): array
    {
        return $this->query('bear/template/resolve', [
            'engine' => $engine,
            'name' => $name,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function templateForResource(
        string $resourceUri,
        string $engine,
        ?string $contextPath = null,
    ): array {
        return $this->query('bear/template/forResource', [
            'uri' => $resourceUri,
            'engine' => $engine,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function alpsDescriptorLookup(string $descriptorId, ?string $contextPath = null): array
    {
        return $this->query('bear/alps/describeDescriptor', [
            'descriptorId' => $descriptorId,
            'contextPath' => $contextPath,
        ]);
    }

    /** @return array<string, mixed> */
    public function resourceReferences(
        string $resourceUri,
        ?string $contextPath = null,
        int $limit = 50,
    ): array {
        return $this->query('bear/resource/references', [
            'uri' => $resourceUri,
            'contextPath' => $contextPath,
            'limit' => $limit,
        ]);
    }

    /** @return array<string, mixed> */
    public function resourceIncomingRelations(
        string $resourceUri,
        ?string $contextPath = null,
        int $limit = 50,
    ): array {
        return $this->query('bear/resource/incomingRelations', [
            'uri' => $resourceUri,
            'contextPath' => $contextPath,
            'limit' => $limit,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function query(string $method, array $params): array
    {
        $failure = $this->preflight($method);
        if ($failure !== null) {
            return $failure;
        }

        return $this->forward($method, $params);
    }

    /** @return array<string, mixed>|null */
    private function preflight(string $method): ?array
    {
        if (! $this->preflightComplete) {
            $result = $this->checkDiscovery($this->forward('bear/project/info', ['contextPath' => null]));
            $this->preflightComplete = true;
            if (($result['status'] ?? null) === 'ok') {
                $this->rememberDiscovery($result);
            } else {
                $this->preflightFailure = $result;
            }
        }

        if ($this->preflightFailure !== null) {
            return $this->preflightFailure;
        }

        if (! isset($this->availableRequests[$method])) {
            return self::failure(
                'unsupported',
                'semantic_request_unavailable',
                'Phpactor does not advertise the required BEAR semantic request.',
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function forward(string $method, array $params): array
    {
        try {
            $result = $this->client->request($method, $params);
            if (!is_array($result) || array_is_list($result)) {
                return self::failure(
                    'parse_error',
                    'invalid_lsp_response',
                    'Phpactor returned an invalid BEAR semantic result.',
                );
            }

            return $this->validateEnvelope($result);
        } catch (LspTimeoutException) {
            return self::failure('timeout', 'lsp_timeout', 'Phpactor did not respond before the timeout.');
        } catch (LspRpcException $exception) {
            if ($exception->rpcCode === -32601) {
                return self::failure(
                    'engine_unavailable',
                    'semantic_api_unavailable',
                    'Phpactor does not expose the required BEAR Semantic API.',
                );
            }

            return self::failure('engine_unavailable', 'lsp_request_failed', 'Phpactor rejected the semantic query.');
        } catch (LspException) {
            return self::failure('engine_unavailable', 'phpactor_unavailable', 'Phpactor is unavailable.');
        } catch (\Throwable) {
            return self::failure(
                'engine_unavailable',
                'adapter_failure',
                'The semantic adapter could not complete the query.',
            );
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function rememberDiscovery(array $result): void
    {
        $this->preflightComplete = true;
        $this->preflightFailure = null;
        $this->availableRequests = [];

        $data = $result['data'] ?? null;
        $requests = is_array($data) ? ($data['requests'] ?? []) : [];
        foreach ($requests as $request) {
            if (is_string($request)) {
                $this->availableRequests[$request] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function checkDiscovery(array $result): array
    {
        if (($result['status'] ?? null) !== 'ok') {
            return $result;
        }

        $data = $result['data'] ?? null;
        if (! is_array($data) || ($data['semanticProtocol'] ?? null) !== self::SEMANTIC_PROTOCOL) {
            return self::failure(
                'unsupported',
                'unsupported_semantic_protocol',
                'The BEAR semantic protocol is not supported by this adapter.',
            );
        }

        $requests = $data['requests'] ?? null;
        if (
            ! is_array($requests)
            || array_is_list($requests) === false
            || array_filter($requests, static fn (mixed $request): bool => ! is_string($request)) !== []
        ) {
            return self::failure(
                'parse_error',
                'invalid_semantic_discovery',
                'Phpactor returned invalid BEAR semantic request discovery.',
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function validateEnvelope(array $result): array
    {
        $status = $result['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            return self::failure(
                'parse_error',
                'invalid_lsp_response',
                'Phpactor returned an invalid BEAR semantic result.',
            );
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private static function failure(string $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'data' => null,
            'candidates' => [],
            'provenance' => [],
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
