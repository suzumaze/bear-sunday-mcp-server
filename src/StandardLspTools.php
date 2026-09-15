<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Suzumaze\BearSundayMcp\Lsp\FileUri;
use Suzumaze\BearSundayMcp\Lsp\LspException;
use Suzumaze\BearSundayMcp\Lsp\LspRpcException;
use Suzumaze\BearSundayMcp\Lsp\LspTimeoutException;
use Suzumaze\BearSundayMcp\Lsp\SemanticLspClient;

final class StandardLspTools
{
    private const MAX_LOCATIONS = 200;
    private const MAX_HOVER_BYTES = 65_536;

    public function __construct(
        private readonly SemanticLspClient $client,
        private readonly Workspace $workspace,
    ) {
    }

    /** @return array<string, mixed> */
    public function definition(
        string $path,
        int $line,
        int $character,
        int $limit = 50,
    ): array {
        return $this->locations(
            'textDocument/definition',
            $path,
            $line,
            $character,
            $limit,
        );
    }

    /** @return array<string, mixed> */
    public function references(
        string $path,
        int $line,
        int $character,
        bool $includeDeclaration = false,
        int $limit = 50,
    ): array {
        return $this->locations(
            'textDocument/references',
            $path,
            $line,
            $character,
            $limit,
            ['context' => ['includeDeclaration' => $includeDeclaration]],
        );
    }

    /** @return array<string, mixed> */
    public function hover(string $path, int $line, int $character): array
    {
        try {
            [$document, $raw] = $this->requestAt('textDocument/hover', $path, $line, $character);
        } catch (\Throwable $exception) {
            return $this->exceptionResult($exception);
        }

        $provenance = $this->provenance('textDocument/hover', $document);
        if ($raw === null || $raw === []) {
            return $this->envelope('not_found', null, $provenance);
        }
        if (!is_array($raw) || array_is_list($raw) || !array_key_exists('contents', $raw)) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned an invalid Hover.');
        }

        $contents = $this->hoverContents($raw['contents']);
        if ($contents === null) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned invalid Hover contents.');
        }
        [$value, $truncated] = $this->truncateUtf8($contents['value'], self::MAX_HOVER_BYTES);
        $data = [
            'contents' => [
                'kind' => $contents['kind'],
                'value' => $value,
            ],
            'truncated' => $truncated,
        ];
        if (isset($raw['range'])) {
            $range = $this->range($raw['range']);
            if ($range === null) {
                return self::failure(
                    'parse_error',
                    'invalid_lsp_response',
                    'Phpactor returned an invalid Hover range.',
                );
            }
            $data['range'] = $range;
        }

        return $this->envelope('ok', $data, $provenance);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function locations(
        string $method,
        string $path,
        int $line,
        int $character,
        int $limit,
        array $extra = [],
    ): array {
        if ($limit < 1 || $limit > self::MAX_LOCATIONS) {
            return self::failure('invalid_input', 'invalid_limit', 'Location limit must be between 1 and 200.');
        }

        try {
            [$document, $raw] = $this->requestAt($method, $path, $line, $character, $extra);
        } catch (\Throwable $exception) {
            return $this->exceptionResult($exception);
        }

        $provenance = $this->provenance($method, $document);
        if ($raw === null || $raw === []) {
            return $this->envelope('not_found', null, $provenance);
        }
        $locations = $this->normalizeLocations($raw);
        if ($locations === null) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned invalid Locations.');
        }
        if ($locations === []) {
            return $this->envelope('not_found', null, $provenance);
        }

        $total = count($locations);

        return $this->envelope('ok', [
            'locations' => array_slice($locations, 0, $limit),
            'total' => $total,
            'truncated' => $total > $limit,
        ], $provenance);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{WorkspaceDocument, mixed}
     */
    private function requestAt(
        string $method,
        string $path,
        int $line,
        int $character,
        array $extra = [],
    ): array {
        $document = $this->workspace->readDocument($path);
        if (!$this->validPosition($document->contents, $line, $character)) {
            throw new WorkspaceFileException('invalid_input', 'Position is outside the saved document.');
        }

        $this->client->notify('textDocument/didOpen', [
            'textDocument' => [
                'uri' => $document->uri,
                'languageId' => $document->languageId,
                'version' => 1,
                'text' => $document->contents,
            ],
        ]);
        try {
            $raw = $this->client->request($method, [
                'textDocument' => ['uri' => $document->uri],
                'position' => ['line' => $line, 'character' => $character],
                ...$extra,
            ]);
        } finally {
            try {
                $this->client->notify('textDocument/didClose', [
                    'textDocument' => ['uri' => $document->uri],
                ]);
            } catch (\Throwable) {
                // The original request result or failure takes precedence.
            }
        }

        return [$document, $raw];
    }

    private function validPosition(string $contents, int $line, int $character): bool
    {
        if ($line < 0 || $character < 0) {
            return false;
        }
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        if ($lines === false || !array_key_exists($line, $lines)) {
            return false;
        }

        $characters = preg_split('//u', $lines[$line], -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            return false;
        }
        $utf16Length = 0;
        foreach ($characters as $value) {
            $utf16Length += strlen($value) === 4 ? 2 : 1;
        }

        return $character <= $utf16Length;
    }

    /** @return list<array<string, mixed>>|null */
    private function normalizeLocations(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $items = array_is_list($raw) ? $raw : [$raw];
        $locations = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item)) {
                return null;
            }
            $uri = $item['uri'] ?? $item['targetUri'] ?? null;
            $rawRange = $item['range'] ?? $item['targetSelectionRange'] ?? $item['targetRange'] ?? null;
            if (!is_string($uri)) {
                return null;
            }
            $range = $this->range($rawRange);
            if ($range === null) {
                return null;
            }
            $absolute = FileUri::toPath($uri);
            if ($absolute === null) {
                continue;
            }
            $path = $this->workspace->relativeExistingFile($absolute);
            if ($path === null) {
                continue;
            }
            $locations[] = ['path' => $path, 'range' => $range];
        }

        usort($locations, static fn (array $left, array $right): int => [
            $left['path'],
            $left['range']['start']['line'],
            $left['range']['start']['character'],
            $left['range']['end']['line'],
            $left['range']['end']['character'],
        ] <=> [
            $right['path'],
            $right['range']['start']['line'],
            $right['range']['start']['character'],
            $right['range']['end']['line'],
            $right['range']['end']['character'],
        ]);

        $deduplicated = [];
        foreach ($locations as $location) {
            $deduplicated[json_encode($location, JSON_THROW_ON_ERROR)] = $location;
        }

        return array_values($deduplicated);
    }

    /** @return array{start:array{line:int,character:int},end:array{line:int,character:int}}|null */
    private function range(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }
        $start = $this->position($raw['start'] ?? null);
        $end = $this->position($raw['end'] ?? null);
        if ($start === null || $end === null) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /** @return array{line:int,character:int}|null */
    private function position(mixed $raw): ?array
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return null;
        }
        $line = $raw['line'] ?? null;
        $character = $raw['character'] ?? null;
        if (!is_int($line) || !is_int($character) || $line < 0 || $character < 0) {
            return null;
        }

        return ['line' => $line, 'character' => $character];
    }

    /** @return array{kind:string,value:string}|null */
    private function hoverContents(mixed $contents): ?array
    {
        if (is_string($contents)) {
            return ['kind' => 'plaintext', 'value' => $contents];
        }
        if (!is_array($contents)) {
            return null;
        }
        if (!array_is_list($contents)) {
            $kind = $contents['kind'] ?? null;
            $value = $contents['value'] ?? null;
            if (is_string($kind) && in_array($kind, ['markdown', 'plaintext'], true) && is_string($value)) {
                return ['kind' => $kind, 'value' => $value];
            }

            return null;
        }

        $parts = [];
        foreach ($contents as $part) {
            if (is_string($part)) {
                $parts[] = $part;
                continue;
            }
            if (!is_array($part) || !is_string($part['value'] ?? null)) {
                return null;
            }
            $language = $part['language'] ?? null;
            $parts[] = is_string($language)
                ? sprintf("```%s\n%s\n```", $language, $part['value'])
                : $part['value'];
        }

        return ['kind' => 'markdown', 'value' => implode("\n\n", $parts)];
    }

    /** @return array{string,bool} */
    private function truncateUtf8(string $value, int $bytes): array
    {
        if (strlen($value) <= $bytes) {
            return [$value, false];
        }

        $value = substr($value, 0, $bytes);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }

        return [$value, true];
    }

    /** @return list<array<string, mixed>> */
    private function provenance(string $method, WorkspaceDocument $document): array
    {
        return [[
            'source' => $method,
            'path' => $document->relativePath,
            'freshness' => 'saved',
        ]];
    }

    /** @return array<string, mixed> */
    private function exceptionResult(\Throwable $exception): array
    {
        if ($exception instanceof WorkspaceFileException) {
            return self::failure($exception->status, 'invalid_document', $exception->getMessage());
        }
        if ($exception instanceof LspTimeoutException) {
            return self::failure('timeout', 'lsp_timeout', 'Phpactor did not respond before the timeout.');
        }
        if ($exception instanceof LspRpcException && $exception->rpcCode === -32601) {
            return self::failure(
                'engine_unavailable',
                'lsp_method_unavailable',
                'Phpactor does not expose the requested standard LSP method.',
            );
        }
        if ($exception instanceof LspException) {
            return self::failure('engine_unavailable', 'phpactor_unavailable', 'Phpactor is unavailable.');
        }

        return self::failure('engine_unavailable', 'adapter_failure', 'The LSP adapter could not complete the query.');
    }

    /**
     * @param array<string, mixed>|null $data
     * @param list<array<string, mixed>> $provenance
     * @return array<string, mixed>
     */
    private function envelope(string $status, ?array $data, array $provenance): array
    {
        return [
            'status' => $status,
            'data' => $data,
            'candidates' => [],
            'provenance' => $provenance,
        ];
    }

    /** @return array<string, mixed> */
    private static function failure(string $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'data' => null,
            'candidates' => [],
            'provenance' => [],
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
