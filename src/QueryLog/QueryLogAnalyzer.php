<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

final class QueryLogAnalyzer
{
    private const MAX_CLAIMS = 200;
    private const MAX_ITEMS = 50;
    private const MAX_STRING_BYTES = 512;

    /** @return array<string, mixed> */
    public function summary(QueryLogDocument $document): array
    {
        $rootKinds = [];
        $uris = [];
        $mutation = false;
        $failure = false;
        $diagnostic = false;
        foreach ($document->entries as $entry) {
            if ($entry->position === 'open' && preg_match('/^open\.\d+$/D', $entry->node) === 1) {
                $rootKinds[] = $entry->type;
            }
            foreach ($this->entryUris($entry) as $uri) {
                $uris[] = $this->safeUri($uri);
            }
            $mutation = $mutation || in_array($entry->type, [
                'command',
                'invalidate',
                'manual_invalidate',
                'manual_purge',
                'manual_store',
                'purge',
                'put_donut',
                'save_donut',
                'save_donut_view',
                'save_etag',
                'save_value',
                'save_view',
            ], true);
            $failure = $failure || in_array($entry->type, ['cache_error', 'pool_error'], true)
                || (($entry->context['saved'] ?? null) === false)
                || (($entry->context['result'] ?? null) === 'failed')
                || (($entry->context['cdn'] ?? null) === 'failed')
                || (($entry->context['roPool'] ?? null) === 'failed')
                || (($entry->context['etagPool'] ?? null) === 'failed');
            $diagnostic = $diagnostic || str_starts_with($entry->type, 'semantic_logger_');
        }

        $rootKinds = $this->uniqueStrings($rootKinds);
        $uris = $this->uniqueStrings($uris);

        return [
            'sessionId' => $document->session->id,
            'recordedAt' => $document->session->recordedAt,
            'rootKinds' => $rootKinds,
            'resourceUris' => $uris,
            'hasMutation' => $mutation,
            'hasFailure' => $failure,
            'hasDiagnostic' => $diagnostic,
            'schemaValidation' => 'valid',
            'experimental' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function claims(QueryLogDocument $document): array
    {
        $claims = [];
        foreach ($document->entries as $entry) {
            if (str_starts_with($entry->type, 'save_')) {
                $claims[] = $this->cacheWriteClaim($entry);
            } elseif ($entry->type === 'cdn_headers') {
                $claims[] = $this->cdnClaim($entry);
            } elseif ($entry->type === 'invalidate') {
                $claims[] = $this->invalidationClaim($entry);
            } elseif ($entry->type === 'conditional_request' && $entry->position === 'open') {
                $claims[] = $this->conditionalClaim($document, $entry);
            } elseif ($entry->type === 'get' && $entry->position === 'open') {
                $missReason = $this->missReasonClaim($document, $entry);
                if ($missReason !== null) {
                    $claims[] = $missReason;
                }
            } elseif (
                $entry->position === 'open'
                && ($entry->type === 'command' || str_starts_with($entry->type, 'manual_'))
            ) {
                $claims[] = $this->initiatorClaim($document, $entry);
            } elseif ($entry->type === 'cache_policy') {
                $claims[] = $this->cachePolicyClaim($entry);
            } elseif ($entry->type === 'cache_error' || $entry->type === 'pool_error') {
                $claims[] = $this->poolHealthClaim($entry);
            }
            if (
                $entry->position === 'close'
                && in_array($entry->type, ['cache_hit', 'cache_miss'], true)
                && is_numeric($entry->context['durationMs'] ?? null)
            ) {
                $claims[] = $this->costClaim($entry);
            }
            if (count($claims) >= self::MAX_CLAIMS) {
                break;
            }
        }

        return array_slice($claims, 0, self::MAX_CLAIMS);
    }

    public function concernsResource(QueryLogDocument $document, string $resourceUri): bool
    {
        $identity = $this->uriIdentity($resourceUri);
        foreach ($document->entries as $entry) {
            foreach ($this->entryUris($entry) as $uri) {
                if ($this->uriIdentity($uri) === $identity) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function cacheWriteClaim(QueryLogEntry $entry): array
    {
        return $this->claim(
            'cache_write',
            $this->contextUri($entry),
            ($entry->context['saved'] ?? null) === true ? 'saved' : 'not_saved',
            $entry,
            [
                'storage' => substr($entry->type, 5),
                'tags' => $this->safeStringList($entry->context['tags'] ?? []),
                'requestedTtl' => $this->integerOrNull($entry->context['requestedTtl'] ?? null),
            ],
            ['The requested TTL does not prove when the backend actually evicted the entry.'],
        );
    }

    /** @return array<string, mixed> */
    private function cdnClaim(QueryLogEntry $entry): array
    {
        $headers = $entry->context['headers'] ?? [];
        $headerNames = is_array($headers) ? $this->safeStringList(array_keys($headers)) : [];

        return $this->claim(
            'cdn_instruction',
            $this->contextUri($entry),
            'headers_set',
            $entry,
            [
                'headerNames' => $headerNames,
                'surrogateKeys' => $this->safeStringList($entry->context['surrogateKeys'] ?? []),
            ],
            ['This records response instructions, not how a CDN edge applied them. Header values are redacted.'],
        );
    }

    /** @return array<string, mixed> */
    private function invalidationClaim(QueryLogEntry $entry): array
    {
        return $this->claim(
            'invalidation',
            null,
            $this->safeScalar($entry->context['cdn'] ?? null),
            $entry,
            [
                'tags' => $this->safeStringList($entry->context['tags'] ?? []),
                'roPool' => $this->safeScalar($entry->context['roPool'] ?? null),
                'etagPool' => $this->safeScalar($entry->context['etagPool'] ?? null),
                'cdn' => $this->safeScalar($entry->context['cdn'] ?? null),
                'durationMs' => $this->numberOrNull($entry->context['durationMs'] ?? null),
            ],
            ['A purger result does not prove propagation to every CDN edge.'],
        );
    }

    /** @return array<string, mixed> */
    private function conditionalClaim(QueryLogDocument $document, QueryLogEntry $entry): array
    {
        $close = $this->scopeClose($document, $entry->scope);
        $outcome = $close?->type === 'cache_hit' ? 'hit' : ($close?->type === 'cache_miss' ? 'miss' : 'unclosed');

        return $this->claim(
            'conditional_request',
            null,
            $outcome,
            $entry,
            ['layer' => $this->safeScalar($close?->context['layer'] ?? null)],
            ['The client validator value is redacted. A hit at the etag layer is the observed 304 decision.'],
            $close,
        );
    }

    /** @return array<string, mixed>|null */
    private function missReasonClaim(QueryLogDocument $document, QueryLogEntry $entry): ?array
    {
        $close = $this->scopeClose($document, $entry->scope);
        if ($close?->type !== 'cache_miss') {
            return null;
        }
        $scopeEntries = $this->scopeEntries($document, $entry->scope);
        $reason = 'cold';
        $reasonEntry = null;
        foreach ($scopeEntries as $candidate) {
            if ($candidate->type === 'cache_error' && ($candidate->context['operation'] ?? null) === 'read') {
                $reason = 'degraded_read';
                $reasonEntry = $candidate;
                break;
            }
            if ($candidate->type === 'cache_error' && ($candidate->context['operation'] ?? null) === 'write') {
                $reason = 'write_failure';
                $reasonEntry = $candidate;
            }
            if ($candidate->type === 'put_skipped') {
                $reason = 'policy_skip';
                $reasonEntry = $candidate;
            }
        }

        return $this->claim(
            'miss_reason',
            $this->contextUri($entry),
            $reason,
            $entry,
            ['skipReason' => $this->safeScalar($reasonEntry?->context['reason'] ?? null)],
            [
                'This explains only the observed session; absent production sessions may have been dropped '
                    . 'by retention policy.',
            ],
            $reasonEntry ?? $close,
        );
    }

    /** @return array<string, mixed> */
    private function initiatorClaim(QueryLogDocument $document, QueryLogEntry $entry): array
    {
        $close = $this->scopeClose($document, $entry->scope);
        $manual = str_starts_with($entry->type, 'manual_');

        return $this->claim(
            'initiator',
            $this->contextUri($entry),
            $manual ? 'application' : 'framework_interceptor',
            $entry,
            [
                'operation' => $entry->type,
                'method' => $this->safeScalar($entry->context['method'] ?? null),
                'source' => $this->safeScalar($entry->context['source'] ?? null),
                'result' => $this->safeScalar($close?->context['result'] ?? null),
            ],
            ['This identifies the recorded initiating boundary, not the end user or distributed request.'],
            $close,
        );
    }

    /** @return array<string, mixed> */
    private function cachePolicyClaim(QueryLogEntry $entry): array
    {
        return $this->claim(
            'cache_policy',
            $this->contextUri($entry),
            'resolved',
            $entry,
            [
                'expiry' => $this->safeScalar($entry->context['expiry'] ?? null),
                'expirySecond' => $this->integerOrNull($entry->context['expirySecond'] ?? null),
                'expiryAtField' => $this->safeScalar($entry->context['expiryAt'] ?? null),
                'resolvedTtl' => $this->integerOrNull($entry->context['resolvedTtl'] ?? null),
            ],
            ['Resolved TTL records framework intent, not the backend eviction time.'],
        );
    }

    /** @return array<string, mixed> */
    private function poolHealthClaim(QueryLogEntry $entry): array
    {
        return $this->claim(
            'pool_health',
            $entry->type === 'cache_error' ? $this->contextUri($entry) : null,
            'failure',
            $entry,
            [
                'source' => $entry->type,
                'operation' => $this->safeScalar($entry->context['operation'] ?? null),
                'exceptionClass' => $this->safeScalar($entry->context['exceptionClass'] ?? null),
            ],
            [
                'The backend key and exception message are redacted. This is one observed failure, '
                    . 'not an availability rate.',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function costClaim(QueryLogEntry $entry): array
    {
        return $this->claim(
            'cost_observation',
            null,
            $entry->type === 'cache_hit' ? 'hit' : 'miss',
            $entry,
            [
                'layer' => $this->safeScalar($entry->context['layer'] ?? null),
                'durationMs' => $this->numberOrNull($entry->context['durationMs'] ?? null),
            ],
            ['A single duration is an observation, not a benchmark or SLO assessment.'],
        );
    }

    /**
     * @param array<string, mixed> $details
     * @param list<string>         $limitations
     *
     * @return array<string, mixed>
     */
    private function claim(
        string $type,
        ?string $subject,
        ?string $outcome,
        QueryLogEntry $entry,
        array $details,
        array $limitations,
        ?QueryLogEntry $additionalEvidence = null,
    ): array {
        $evidence = [['context' => $entry->type, 'node' => $entry->node]];
        if ($additionalEvidence !== null && $additionalEvidence->node !== $entry->node) {
            $evidence[] = ['context' => $additionalEvidence->type, 'node' => $additionalEvidence->node];
        }

        return [
            'type' => $type,
            'subject' => $subject,
            'outcome' => $outcome,
            'details' => $details,
            'evidence' => $evidence,
            'limitations' => $limitations,
        ];
    }

    /** @return list<QueryLogEntry> */
    private function scopeEntries(QueryLogDocument $document, string $scope): array
    {
        return array_values(array_filter(
            $document->entries,
            static fn (QueryLogEntry $entry): bool => $entry->scope === $scope && $entry->position === 'event',
        ));
    }

    private function scopeClose(QueryLogDocument $document, string $scope): ?QueryLogEntry
    {
        foreach ($document->entries as $entry) {
            if ($entry->scope === $scope && $entry->position === 'close') {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function entryUris(QueryLogEntry $entry): array
    {
        $uris = [];
        foreach (['uri', 'parent', 'child'] as $field) {
            $value = $entry->context[$field] ?? null;
            if (is_string($value)) {
                $uris[] = $value;
            }
        }
        $annotations = $entry->context['annotations'] ?? [];
        if (is_array($annotations)) {
            foreach ($annotations as $annotation) {
                if (is_array($annotation) && is_string($annotation['uri'] ?? null)) {
                    $uris[] = $annotation['uri'];
                }
            }
        }

        return $uris;
    }

    private function contextUri(QueryLogEntry $entry): ?string
    {
        $uri = $entry->context['uri'] ?? null;

        return is_string($uri) ? $this->safeUri($uri) : null;
    }

    private function safeUri(string $uri): string
    {
        $uri = $this->boundedString($uri);
        if (preg_match('/\{\?[^}]+\}$/D', $uri) === 1 || !str_contains($uri, '?')) {
            return $uri;
        }
        [$base, $query] = explode('?', $uri, 2);
        $names = [];
        foreach (explode('&', $query) as $part) {
            $name = rawurldecode(explode('=', $part, 2)[0]);
            if ($name !== '' && preg_match('/^[A-Za-z0-9_.\[\]-]+$/D', $name) === 1) {
                $names[] = $name . '=<redacted>';
            }
        }

        return $base . ($names === [] ? '' : '?' . implode('&', array_slice($names, 0, self::MAX_ITEMS)));
    }

    private function uriIdentity(string $uri): string
    {
        if (preg_match('/^(.*)\{\?[^}]+\}$/D', $uri, $matches) === 1) {
            return $matches[1];
        }

        return explode('?', $uri, 2)[0];
    }

    /** @return list<string> */
    private function safeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $bounded = $this->boundedString($value);
                $strings[] = preg_replace('/=[^,\s]+/', '=<redacted>', $bounded) ?? $bounded;
            }
        }

        return array_slice($strings, 0, self::MAX_ITEMS);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return array_slice($values, 0, self::MAX_ITEMS);
    }

    private function boundedString(string $value): string
    {
        return strlen($value) <= self::MAX_STRING_BYTES
            ? $value
            : substr($value, 0, self::MAX_STRING_BYTES) . '…';
    }

    private function safeScalar(mixed $value): ?string
    {
        return is_string($value) ? $this->boundedString($value) : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }
}
