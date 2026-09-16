<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

use Koriym\SemanticLogger\SemanticLogValidator;
use Koriym\SemanticLogger\SemanticLogValidatorInterface;

final class QueryLogValidator
{
    private const ROOT_SCHEMA = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';
    private const CONTEXT_SCHEMA_BASE = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/';
    private const SUPPORTED_TYPES = [
        'cache_error',
        'cache_hit',
        'cache_miss',
        'cache_policy',
        'cdn_headers',
        'command',
        'command_result',
        'conditional_request',
        'depends_on',
        'get',
        'invalidate',
        'manual_invalidate',
        'manual_invalidate_result',
        'manual_purge',
        'manual_purge_result',
        'manual_store',
        'manual_store_result',
        'pool_error',
        'pre_write_cleanup',
        'purge',
        'put_donut',
        'put_skipped',
        'refresh_donut',
        'save_donut',
        'save_donut_view',
        'save_etag',
        'save_value',
        'save_view',
        'semantic_logger_error',
        'semantic_logger_invalid_context',
    ];

    public function __construct(
        private readonly string $schemaDirectory,
        private readonly SemanticLogValidatorInterface $validator = new SemanticLogValidator(),
    ) {
    }

    public function validate(QueryLogSession $session): QueryLogDocument
    {
        if ($session->json === '') {
            throw new QueryLogException(
                'unsupported',
                'The query log session exceeds the supported size or is unreadable.',
            );
        }

        try {
            $root = json_decode($session->json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new QueryLogException('parse_error', 'The query log session is not valid JSON.');
        }
        if (!is_array($root) || array_is_list($root)) {
            throw new QueryLogException('parse_error', 'The query log session root must be an object.');
        }
        if (($root['$schema'] ?? null) !== self::ROOT_SCHEMA) {
            throw new QueryLogException('unsupported', 'The query log schema version is not supported.');
        }

        $entries = [];
        $this->collectEntries($root, $entries);
        $this->assertSupportedVocabulary($entries);
        $this->validateWithUpstream($session->json);

        return new QueryLogDocument($session, $root, $entries);
    }

    /**
     * @param array<string, mixed> $root
     * @param list<QueryLogEntry>  $entries
     */
    private function collectEntries(array $root, array &$entries): void
    {
        foreach (['open', 'events', 'close'] as $collection) {
            $items = $root[$collection] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $node = $collection . '.' . $index;
                if ($collection === 'open') {
                    $this->collectOpen($entry, $node, $entries);
                    continue;
                }
                $this->appendEntry($entry, $node, $collection === 'events' ? 'event' : 'close', 'root', $entries);
            }
        }
    }

    /**
     * @param array<string, mixed> $open
     * @param list<QueryLogEntry>  $entries
     */
    private function collectOpen(array $open, string $node, array &$entries): void
    {
        $this->appendEntry($open, $node, 'open', $node, $entries);
        $events = $open['events'] ?? [];
        if (is_array($events)) {
            foreach ($events as $index => $event) {
                if (is_array($event)) {
                    $this->appendEntry($event, $node . '.events.' . $index, 'event', $node, $entries);
                }
            }
        }
        $children = $open['open'] ?? [];
        if (is_array($children)) {
            foreach ($children as $index => $child) {
                if (is_array($child)) {
                    $this->collectOpen($child, $node . '.open.' . $index, $entries);
                }
            }
        }
        $close = $open['close'] ?? null;
        if (is_array($close)) {
            $this->appendEntry($close, $node . '.close', 'close', $node, $entries);
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<QueryLogEntry>  $entries
     */
    private function appendEntry(array $entry, string $node, string $position, string $scope, array &$entries): void
    {
        $type = $entry['type'] ?? null;
        $schemaUrl = $entry['schemaUrl'] ?? null;
        $context = $entry['context'] ?? null;
        if (!is_string($type) || !is_string($schemaUrl) || !is_array($context)) {
            return;
        }
        $entries[] = new QueryLogEntry($type, $schemaUrl, $node, $position, $context, $scope);
    }

    /** @param list<QueryLogEntry> $entries */
    private function assertSupportedVocabulary(array $entries): void
    {
        foreach ($entries as $entry) {
            if (!in_array($entry->type, self::SUPPORTED_TYPES, true)) {
                throw new QueryLogException('unsupported', 'The query log contains an unsupported context type.');
            }
            if (str_starts_with($entry->type, 'semantic_logger_')) {
                continue;
            }
            if ($entry->schemaUrl !== self::CONTEXT_SCHEMA_BASE . $entry->type . '.json') {
                throw new QueryLogException('unsupported', 'The query log context schema is not supported.');
            }
        }
    }

    private function validateWithUpstream(string $json): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'bear-mcp-query-log-');
        if ($temporary === false || file_put_contents($temporary, $json) === false) {
            throw new QueryLogException('parse_error', 'The query log session could not be staged for validation.');
        }
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $this->validator->validate($temporary, $this->schemaDirectory, failOnDiagnostics: false);
        } catch (\InvalidArgumentException | \RuntimeException) {
            throw new QueryLogException('parse_error', 'The query log session does not satisfy its schemas.');
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            unlink($temporary);
        }
    }
}
