<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Suzumaze\BearSundayMcp\Lsp\LspException;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
use Suzumaze\BearSundayMcp\Lsp\LspTimeoutException;
use Suzumaze\BearSundayMcp\Lsp\SemanticLspClient;

final class SemanticTools
{
    private const SEMANTIC_API_VERSION = 1;
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

    public function __construct(private readonly SemanticLspClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function projectInfo(?string $contextPath = null): array
    {
        $result = $this->forward('bear/project/info', ['contextPath' => $contextPath]);
        $checked = $this->checkApiVersion($result);
        if (($checked['status'] ?? null) === 'ok') {
            $this->preflightComplete = true;
            $this->preflightFailure = null;
        }

        return $checked;
    }

    /** @return array<string, mixed> */
    public function resourceList(?string $scheme = null, string $prefix = '', int $limit = 50): array
    {
        return $this->query('bear/resource/list', [
            'scheme' => $scheme,
            'prefix' => $prefix,
            'limit' => $limit,
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

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function query(string $method, array $params): array
    {
        $failure = $this->preflight();
        if ($failure !== null) {
            return $failure;
        }

        return $this->forward($method, $params);
    }

    /** @return array<string, mixed>|null */
    private function preflight(): ?array
    {
        if ($this->preflightComplete) {
            return $this->preflightFailure;
        }

        $result = $this->checkApiVersion($this->forward('bear/project/info', ['contextPath' => null]));
        $this->preflightComplete = true;
        if (($result['status'] ?? null) !== 'ok') {
            $this->preflightFailure = $result;
        }

        return $this->preflightFailure;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function forward(string $method, array $params): array
    {
        try {
            return $this->validateEnvelope($this->client->request($method, $params));
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
     * @return array<string, mixed>
     */
    private function checkApiVersion(array $result): array
    {
        if (($result['status'] ?? null) !== 'ok') {
            return $result;
        }

        $data = $result['data'] ?? null;
        $version = is_array($data) ? ($data['semanticApiVersion'] ?? null) : null;
        if ($version !== self::SEMANTIC_API_VERSION) {
            return self::failure(
                'unsupported',
                'unsupported_semantic_api_version',
                'The BEAR Semantic API major version is not supported by this adapter.',
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
