<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit\QueryLog;

use Koriym\SemanticLogger\SemanticLogValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogDocument;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogEntry;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogException;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogSession;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogValidator;

#[CoversClass(QueryLogValidator::class)]
#[CoversClass(QueryLogDocument::class)]
#[CoversClass(QueryLogEntry::class)]
#[CoversClass(QueryLogException::class)]
final class QueryLogValidatorTest extends TestCase
{
    private const ROOT_SCHEMA = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';
    private const CONTEXT_SCHEMA = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/';

    public function testUsesTheUpstreamValidatorAndBuildsOpaqueNodePositions(): void
    {
        $json = json_encode([
            '$schema' => self::ROOT_SCHEMA,
            'open' => [$this->entry('get', ['uri' => 'app://self/user?id=secret'])],
        ], JSON_THROW_ON_ERROR);
        $validator = new QueryLogValidator(__DIR__ . '/../../Fixture/context-schemas');

        $document = $validator->validate($this->session($json));

        self::assertCount(1, $document->entries);
        self::assertSame('get', $document->entries[0]->type);
        self::assertSame('open.0', $document->entries[0]->node);
        self::assertSame('open', $document->entries[0]->position);
    }

    public function testTraversesNestedScopesEventsAndCloses(): void
    {
        $json = json_encode([
            '$schema' => self::ROOT_SCHEMA,
            'open' => [[
                ...$this->entry('get', ['uri' => 'app://self/parent']),
                'events' => [$this->entry('cache_policy', [
                    'uri' => 'app://self/parent',
                    'expiry' => 'never',
                    'expirySecond' => null,
                    'expiryAt' => null,
                    'resolvedTtl' => 60,
                ])],
                'open' => [$this->entry('get', ['uri' => 'app://self/child'])],
                'close' => $this->entry('cache_miss', ['layer' => 'resource', 'durationMs' => 1.2]),
            ]],
        ], JSON_THROW_ON_ERROR);

        $document = $this->permissiveValidator()->validate($this->session($json));

        self::assertSame(
            ['open.0', 'open.0.events.0', 'open.0.open.0', 'open.0.close'],
            array_map(static fn (QueryLogEntry $entry): string => $entry->node, $document->entries),
        );
        self::assertSame('open.0', $document->entries[3]->scope);
    }

    public function testFailsClosedForMalformedUnknownAndMismatchedVocabulary(): void
    {
        $cases = [
            [
                'json' => '{secret',
                'status' => 'parse_error',
            ],
            [
                'json' => json_encode([
                    '$schema' => 'https://example.com/future.json',
                    'open' => [],
                ], JSON_THROW_ON_ERROR),
                'status' => 'unsupported',
            ],
            [
                'json' => json_encode([
                    '$schema' => self::ROOT_SCHEMA,
                    'events' => [$this->entry('future_cache_event', [])],
                ], JSON_THROW_ON_ERROR),
                'status' => 'unsupported',
            ],
            [
                'json' => json_encode([
                    '$schema' => self::ROOT_SCHEMA,
                    'events' => [[
                        ...$this->entry('cache_hit', ['layer' => 'resource', 'durationMs' => null]),
                        'schemaUrl' => self::CONTEXT_SCHEMA . 'cache_miss.json',
                    ]],
                ], JSON_THROW_ON_ERROR),
                'status' => 'unsupported',
            ],
        ];

        foreach ($cases as $index => $case) {
            try {
                $this->permissiveValidator()->validate($this->session($case['json']));
                self::fail('Invalid or unknown log vocabulary must fail closed. Case: ' . $index);
            } catch (QueryLogException $exception) {
                self::assertSame($case['status'], $exception->status);
                self::assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    public function testRedactsUpstreamValidationDiagnostics(): void
    {
        $upstream = new class implements SemanticLogValidatorInterface {
            public function validate(string $file, string $schemaDir, bool $failOnDiagnostics = false): void
            {
                echo 'secret-output';
                throw new \RuntimeException('secret-validator-message');
            }
        };
        $validator = new QueryLogValidator(__DIR__, $upstream);
        $json = json_encode([
            '$schema' => self::ROOT_SCHEMA,
            'open' => [$this->entry('get', ['uri' => 'app://self/user?token=secret'])],
        ], JSON_THROW_ON_ERROR);

        try {
            $validator->validate($this->session($json));
            self::fail('Upstream validation failures must be normalized.');
        } catch (QueryLogException $exception) {
            self::assertSame('parse_error', $exception->status);
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    private function permissiveValidator(): QueryLogValidator
    {
        $upstream = new class implements SemanticLogValidatorInterface {
            public function validate(string $file, string $schemaDir, bool $failOnDiagnostics = false): void
            {
            }
        };

        return new QueryLogValidator(__DIR__, $upstream);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function entry(string $type, array $context): array
    {
        return [
            'id' => $type . '_1',
            'type' => $type,
            'schemaUrl' => self::CONTEXT_SCHEMA . $type . '.json',
            'context' => $context,
        ];
    }

    private function session(string $json): QueryLogSession
    {
        return new QueryLogSession('0123456789abcdef01234567', null, $json, 'var/query-log.jsonl');
    }
}
