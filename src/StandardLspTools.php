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
    private const MAX_COMPLETION_FIELD_BYTES = 8_192;
    private const MAX_SYMBOL_DEPTH = 20;

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
            [$document, $raw] = $this->requestAtWithRetry('textDocument/hover', $path, $line, $character);
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

    /** @return array<string, mixed> */
    public function completion(
        string $path,
        int $line,
        int $character,
        int $limit = 50,
    ): array {
        if ($limit < 1 || $limit > self::MAX_LOCATIONS) {
            return self::failure('invalid_input', 'invalid_limit', 'Completion limit must be between 1 and 200.');
        }

        try {
            [$document, $raw] = $this->requestAtWithRetry(
                'textDocument/completion',
                $path,
                $line,
                $character,
            );
        } catch (\Throwable $exception) {
            return $this->exceptionResult($exception);
        }

        $provenance = $this->provenance('textDocument/completion', $document);
        if ($raw === null || $raw === []) {
            return $this->envelope('not_found', null, $provenance);
        }
        $normalized = $this->normalizeCompletions($raw);
        if ($normalized === null) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned invalid Completions.');
        }
        if ($normalized['items'] === []) {
            return $this->envelope('not_found', null, $provenance);
        }

        $total = count($normalized['items']);

        return $this->envelope('ok', [
            'items' => array_slice($normalized['items'], 0, $limit),
            'total' => $total,
            'isIncomplete' => $normalized['isIncomplete'],
            'truncated' => $normalized['truncated'] || $total > $limit,
        ], $provenance);
    }

    /** @return array<string, mixed> */
    public function documentSymbols(string $path, int $limit = 100): array
    {
        if ($limit < 1 || $limit > self::MAX_LOCATIONS) {
            return self::failure('invalid_input', 'invalid_limit', 'Symbol limit must be between 1 and 200.');
        }

        try {
            [$document, $raw] = $this->requestDocumentWithRetry('textDocument/documentSymbol', $path);
        } catch (\Throwable $exception) {
            return $this->exceptionResult($exception);
        }

        $provenance = $this->provenance('textDocument/documentSymbol', $document);
        if ($raw === null || $raw === []) {
            return $this->envelope('not_found', null, $provenance);
        }
        $normalized = $this->normalizeDocumentSymbols($raw, $document->relativePath);
        if ($normalized === null) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned invalid Document Symbols.');
        }
        $symbols = $normalized['symbols'];
        if ($symbols === []) {
            return $this->envelope('not_found', null, $provenance);
        }

        $total = count($symbols);

        return $this->envelope('ok', [
            'symbols' => array_slice($symbols, 0, $limit),
            'total' => $total,
            'truncated' => $normalized['truncated'] || $total > $limit,
        ], $provenance);
    }

    /** @return array<string, mixed> */
    public function workspaceSymbols(string $query = '', int $limit = 50): array
    {
        if (str_contains($query, "\0") || strlen($query) > 512) {
            return self::failure('invalid_input', 'invalid_query', 'Symbol query is invalid or too long.');
        }
        if ($limit < 1 || $limit > self::MAX_LOCATIONS) {
            return self::failure('invalid_input', 'invalid_limit', 'Symbol limit must be between 1 and 200.');
        }

        try {
            $raw = $this->requestWithRetry('workspace/symbol', ['query' => $query]);
        } catch (\Throwable $exception) {
            return $this->exceptionResult($exception);
        }

        $provenance = [['source' => 'workspace/symbol', 'freshness' => 'saved']];
        if ($raw === null || $raw === []) {
            return $this->envelope('not_found', null, $provenance);
        }
        $normalized = $this->normalizeWorkspaceSymbols($raw);
        if ($normalized === null) {
            return self::failure('parse_error', 'invalid_lsp_response', 'Phpactor returned invalid Workspace Symbols.');
        }
        $symbols = $normalized['symbols'];
        if ($symbols === []) {
            return $this->envelope('not_found', null, $provenance);
        }

        $total = count($symbols);

        return $this->envelope('ok', [
            'symbols' => array_slice($symbols, 0, $limit),
            'total' => $total,
            'truncated' => $normalized['truncated'] || $total > $limit,
        ], $provenance);
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
            [$document, $raw] = $this->requestAtWithRetry($method, $path, $line, $character, $extra);
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

        $raw = $this->requestDocument($method, $document, [
            'position' => ['line' => $line, 'character' => $character],
            ...$extra,
        ]);

        return [$document, $raw];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function requestDocument(string $method, WorkspaceDocument $document, array $extra = []): mixed
    {
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

        return $raw;
    }

    /**
     * Phpactor can return one transient InternalError while its reference index warms up.
     * These allowlisted standard requests are read-only and safe to retry once.
     *
     * @param array<string, mixed> $extra
     * @return array{WorkspaceDocument, mixed}
     */
    private function requestAtWithRetry(
        string $method,
        string $path,
        int $line,
        int $character,
        array $extra = [],
    ): array {
        try {
            return $this->requestAt($method, $path, $line, $character, $extra);
        } catch (LspRpcException $exception) {
            if ($exception->rpcCode !== -32603) {
                throw $exception;
            }

            return $this->requestAt($method, $path, $line, $character, $extra);
        }
    }

    /** @return array{WorkspaceDocument, mixed} */
    private function requestDocumentWithRetry(string $method, string $path): array
    {
        $document = $this->workspace->readDocument($path);
        try {
            return [$document, $this->requestDocument($method, $document)];
        } catch (LspRpcException $exception) {
            if ($exception->rpcCode !== -32603) {
                throw $exception;
            }

            return [$document, $this->requestDocument($method, $document)];
        }
    }

    /** @param array<string, mixed> $params */
    private function requestWithRetry(string $method, array $params): mixed
    {
        try {
            return $this->client->request($method, $params);
        } catch (LspRpcException $exception) {
            if ($exception->rpcCode !== -32603) {
                throw $exception;
            }

            return $this->client->request($method, $params);
        }
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

    /** @return array{items:list<array<string, mixed>>,isIncomplete:bool,truncated:bool}|null */
    private function normalizeCompletions(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $isIncomplete = false;
        if (array_is_list($raw)) {
            $items = $raw;
        } else {
            $items = $raw['items'] ?? null;
            if (!is_array($items) || !array_is_list($items)) {
                return null;
            }
            if (isset($raw['isIncomplete']) && !is_bool($raw['isIncomplete'])) {
                return null;
            }
            $isIncomplete = $raw['isIncomplete'] ?? false;
        }

        $normalized = [];
        $truncated = false;
        foreach ($items as $item) {
            if (!is_array($item) || array_is_list($item) || !is_string($item['label'] ?? null)) {
                return null;
            }

            [$label, $fieldTruncated] = $this->truncateUtf8(
                $item['label'],
                self::MAX_COMPLETION_FIELD_BYTES,
            );
            $completion = ['label' => $label];
            $truncated = $truncated || $fieldTruncated;

            $kind = $item['kind'] ?? null;
            if ($kind !== null) {
                if (!is_int($kind) || $kind < 1) {
                    return null;
                }
                $completion['kind'] = $kind;
            }
            foreach (['detail', 'sortText', 'filterText', 'insertText'] as $field) {
                if (!array_key_exists($field, $item)) {
                    continue;
                }
                if (!is_string($item[$field])) {
                    return null;
                }
                [$value, $fieldTruncated] = $this->truncateUtf8(
                    $item[$field],
                    self::MAX_COMPLETION_FIELD_BYTES,
                );
                $completion[$field] = $value;
                $truncated = $truncated || $fieldTruncated;
            }
            if (array_key_exists('insertTextFormat', $item)) {
                if (!is_int($item['insertTextFormat']) || !in_array($item['insertTextFormat'], [1, 2], true)) {
                    return null;
                }
                $completion['insertTextFormat'] = $item['insertTextFormat'];
            }
            if (array_key_exists('documentation', $item)) {
                $documentation = $this->hoverContents($item['documentation']);
                if ($documentation === null) {
                    return null;
                }
                [$value, $fieldTruncated] = $this->truncateUtf8(
                    $documentation['value'],
                    self::MAX_COMPLETION_FIELD_BYTES,
                );
                $completion['documentation'] = [
                    'kind' => $documentation['kind'],
                    'value' => $value,
                ];
                $truncated = $truncated || $fieldTruncated;
            }

            $normalized[] = $completion;
        }

        usort($normalized, static fn (array $left, array $right): int => [
            $left['sortText'] ?? $left['label'],
            $left['label'],
            $left['kind'] ?? 0,
            $left['insertText'] ?? '',
        ] <=> [
            $right['sortText'] ?? $right['label'],
            $right['label'],
            $right['kind'] ?? 0,
            $right['insertText'] ?? '',
        ]);

        $deduplicated = [];
        foreach ($normalized as $completion) {
            $deduplicated[json_encode($completion, JSON_THROW_ON_ERROR)] = $completion;
        }

        return [
            'items' => array_values($deduplicated),
            'isIncomplete' => $isIncomplete,
            'truncated' => $truncated,
        ];
    }

    /** @return array{symbols:list<array<string, mixed>>,truncated:bool}|null */
    private function normalizeDocumentSymbols(mixed $raw, string $documentPath): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $symbols = [];
        $truncated = false;
        foreach ($raw as $item) {
            if (!$this->appendDocumentSymbol($symbols, $truncated, $item, $documentPath, null, 0)) {
                return null;
            }
        }
        $this->sortAndDeduplicateSymbols($symbols);

        return ['symbols' => $symbols, 'truncated' => $truncated];
    }

    /**
     * @param list<array<string, mixed>> $symbols
     */
    private function appendDocumentSymbol(
        array &$symbols,
        bool &$truncated,
        mixed $raw,
        string $documentPath,
        ?string $parentName,
        int $depth,
    ): bool {
        if ($depth >= self::MAX_SYMBOL_DEPTH || !is_array($raw) || array_is_list($raw)) {
            return false;
        }
        $name = $raw['name'] ?? null;
        $kind = $raw['kind'] ?? null;
        if (!is_string($name) || !is_int($kind) || $kind < 1) {
            return false;
        }
        [$name, $fieldTruncated] = $this->truncateUtf8($name, self::MAX_COMPLETION_FIELD_BYTES);
        $truncated = $truncated || $fieldTruncated;

        if (isset($raw['location'])) {
            $symbol = $this->workspaceSymbol($raw, $truncated);
            if ($symbol === false) {
                return false;
            }
            if ($symbol !== null) {
                $symbols[] = $symbol;
            }

            return true;
        }

        $range = $this->range($raw['range'] ?? null);
        $selectionRange = $this->range($raw['selectionRange'] ?? null);
        if ($range === null || $selectionRange === null) {
            return false;
        }
        $symbol = [
            'name' => $name,
            'kind' => $kind,
            'path' => $documentPath,
            'range' => $range,
            'selectionRange' => $selectionRange,
        ];
        if ($parentName !== null) {
            $symbol['containerName'] = $parentName;
        }
        if (array_key_exists('detail', $raw)) {
            if (!is_string($raw['detail'])) {
                return false;
            }
            [$detail, $fieldTruncated] = $this->truncateUtf8(
                $raw['detail'],
                self::MAX_COMPLETION_FIELD_BYTES,
            );
            $symbol['detail'] = $detail;
            $truncated = $truncated || $fieldTruncated;
        }
        $symbols[] = $symbol;

        if (!array_key_exists('children', $raw)) {
            return true;
        }
        if (!is_array($raw['children']) || !array_is_list($raw['children'])) {
            return false;
        }
        foreach ($raw['children'] as $child) {
            if (
                !$this->appendDocumentSymbol(
                    $symbols,
                    $truncated,
                    $child,
                    $documentPath,
                    $name,
                    $depth + 1,
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{symbols:list<array<string, mixed>>,truncated:bool}|null */
    private function normalizeWorkspaceSymbols(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $symbols = [];
        $truncated = false;
        foreach ($raw as $item) {
            $symbol = $this->workspaceSymbol($item, $truncated);
            if ($symbol === false) {
                return null;
            }
            if ($symbol !== null) {
                $symbols[] = $symbol;
            }
        }
        $this->sortAndDeduplicateSymbols($symbols);

        return ['symbols' => $symbols, 'truncated' => $truncated];
    }

    /**
     * @return array<string, mixed>|false|null False means malformed; null means outside the workspace.
     */
    private function workspaceSymbol(mixed $raw, bool &$truncated = false): array|false|null
    {
        if (!is_array($raw) || array_is_list($raw)) {
            return false;
        }
        $name = $raw['name'] ?? null;
        $kind = $raw['kind'] ?? null;
        $location = $raw['location'] ?? null;
        if (
            !is_string($name)
            || !is_int($kind)
            || $kind < 1
            || !is_array($location)
            || array_is_list($location)
            || !is_string($location['uri'] ?? null)
        ) {
            return false;
        }
        [$name, $fieldTruncated] = $this->truncateUtf8($name, self::MAX_COMPLETION_FIELD_BYTES);
        $truncated = $truncated || $fieldTruncated;

        $absolute = FileUri::toPath($location['uri']);
        if ($absolute === null) {
            return null;
        }
        $path = $this->workspace->relativeExistingFile($absolute);
        if ($path === null) {
            return null;
        }

        $symbol = ['name' => $name, 'kind' => $kind, 'path' => $path];
        if (array_key_exists('range', $location)) {
            $range = $this->range($location['range']);
            if ($range === null) {
                return false;
            }
            $symbol['range'] = $range;
        }
        if (array_key_exists('containerName', $raw)) {
            if (!is_string($raw['containerName'])) {
                return false;
            }
            [$containerName, $fieldTruncated] = $this->truncateUtf8(
                $raw['containerName'],
                self::MAX_COMPLETION_FIELD_BYTES,
            );
            $symbol['containerName'] = $containerName;
            $truncated = $truncated || $fieldTruncated;
        }

        return $symbol;
    }

    /** @param list<array<string, mixed>> $symbols */
    private function sortAndDeduplicateSymbols(array &$symbols): void
    {
        usort($symbols, static fn (array $left, array $right): int => [
            $left['path'],
            $left['range']['start']['line'] ?? -1,
            $left['range']['start']['character'] ?? -1,
            $left['name'],
            $left['kind'],
            $left['containerName'] ?? '',
        ] <=> [
            $right['path'],
            $right['range']['start']['line'] ?? -1,
            $right['range']['start']['character'] ?? -1,
            $right['name'],
            $right['kind'],
            $right['containerName'] ?? '',
        ]);

        $deduplicated = [];
        foreach ($symbols as $symbol) {
            $deduplicated[json_encode($symbol, JSON_THROW_ON_ERROR)] = $symbol;
        }
        $symbols = array_values($deduplicated);
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
        if ($exception instanceof LspRpcException) {
            return self::failure(
                'engine_unavailable',
                'lsp_request_failed',
                'Phpactor rejected the standard LSP request.',
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
