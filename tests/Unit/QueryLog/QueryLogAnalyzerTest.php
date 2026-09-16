<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit\QueryLog;

use Koriym\SemanticLogger\SemanticLogValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogAnalyzer;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogDocument;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogSession;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogValidator;

#[CoversClass(QueryLogAnalyzer::class)]
final class QueryLogAnalyzerTest extends TestCase
{
    private const ROOT_SCHEMA = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';
    private const CONTEXT_SCHEMA = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/';

    public function testProducesBoundedClaimsWithoutSensitiveValues(): void
    {
        $document = $this->document();
        $analyzer = new QueryLogAnalyzer();

        $summary = $analyzer->summary($document);
        $claims = $analyzer->claims($document);
        $serialized = json_encode([$summary, $claims], JSON_THROW_ON_ERROR);

        self::assertSame(['command', 'conditional_request', 'get'], $summary['rootKinds']);
        self::assertContains('app://self/user?id=<redacted>&token=<redacted>', $summary['resourceUris']);
        self::assertTrue($summary['hasMutation']);
        self::assertTrue($summary['hasFailure']);
        self::assertSame('valid', $summary['schemaValidation']);
        self::assertTrue($analyzer->concernsResource($document, 'app://self/user'));
        self::assertTrue($analyzer->concernsResource($document, 'app://self/user?id=another-value'));
        self::assertFalse($analyzer->concernsResource($document, 'app://self/other'));
        $expectedTypes = [
            'cache_write',
            'cdn_instruction',
            'invalidation',
            'conditional_request',
            'miss_reason',
            'initiator',
            'cache_policy',
            'pool_health',
            'cost_observation',
        ];
        foreach ($expectedTypes as $type) {
            self::assertContains($type, array_column($claims, 'type'));
        }
        self::assertStringNotContainsString('very-secret', $serialized);
        self::assertStringNotContainsString('raw-etag-secret', $serialized);
        self::assertStringNotContainsString('pool-key-secret', $serialized);
        self::assertStringNotContainsString('header-value-secret', $serialized);
    }

    private function document(): QueryLogDocument
    {
        $get = [
            ...$this->entry('get', ['uri' => 'app://self/user?id=very-secret&token=very-secret']),
            'events' => [
                $this->entry('cache_error', [
                    'uri' => 'app://self/user?id=very-secret',
                    'operation' => 'read',
                    'error' => 'very-secret exception',
                    'exceptionClass' => 'RuntimeException',
                ]),
                $this->entry('pool_error', [
                    'key' => 'pool-key-secret',
                    'operation' => 'read',
                    'error' => 'very-secret backend',
                    'exceptionClass' => 'RedisException',
                ]),
                $this->entry('cache_policy', [
                    'uri' => 'app://self/user?id=very-secret',
                    'expiry' => 'never',
                    'expirySecond' => null,
                    'expiryAt' => null,
                    'resolvedTtl' => 31536000,
                ]),
                $this->entry('save_etag', [
                    'uri' => 'app://self/user?id=very-secret',
                    'etag' => 'raw-etag-secret',
                    'tags' => ['user_id=very-secret'],
                    'requestedTtl' => 60,
                    'saved' => false,
                ]),
                $this->entry('cdn_headers', [
                    'uri' => 'app://self/user?id=very-secret',
                    'headers' => ['Surrogate-Control' => 'header-value-secret'],
                    'surrogateKeys' => ['user_id=very-secret'],
                ]),
                $this->entry('invalidate', [
                    'tags' => ['user'],
                    'roPool' => 'invalidated',
                    'etagPool' => 'invalidated',
                    'cdn' => 'failed',
                    'durationMs' => 1.5,
                ]),
            ],
            'close' => $this->entry('cache_miss', ['layer' => 'resource', 'durationMs' => 4.5]),
        ];
        $conditional = [
            ...$this->entry('conditional_request', ['ifNoneMatch' => 'raw-etag-secret']),
            'close' => $this->entry('cache_hit', ['layer' => 'etag', 'durationMs' => 0.1]),
        ];
        $command = [
            ...$this->entry('command', [
                'method' => 'onPut',
                'annotations' => [],
                'source' => 'CommandInterceptor',
            ]),
            'close' => $this->entry('command_result', ['code' => 200]),
        ];
        $json = json_encode([
            '$schema' => self::ROOT_SCHEMA,
            'open' => [$get, $conditional, $command],
        ], JSON_THROW_ON_ERROR);
        $upstream = new class implements SemanticLogValidatorInterface {
            public function validate(string $file, string $schemaDir, bool $failOnDiagnostics = false): void
            {
            }
        };
        $validator = new QueryLogValidator(__DIR__, $upstream);

        return $validator->validate(new QueryLogSession(
            '0123456789abcdef01234567',
            '2026-09-16T12:00:00.000000Z',
            $json,
            'var/query-log.jsonl',
        ));
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
}
