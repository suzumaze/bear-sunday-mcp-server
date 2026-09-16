<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit\QueryLog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogBatch;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogSession;
use Suzumaze\BearSundayMcp\QueryLog\QueryLogSource;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(QueryLogSource::class)]
#[CoversClass(QueryLogBatch::class)]
#[CoversClass(QueryLogSession::class)]
final class QueryLogSourceTest extends TestCase
{
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = Workspace::fromPath(__DIR__ . '/../../Fixture/workspace');
    }

    public function testReadsNewestDevelopmentSessionsAndIgnoresLatestAlias(): void
    {
        $source = QueryLogSource::directory($this->workspace, 'var/query-log');

        $batch = $source->sessions(1);

        self::assertCount(1, $batch->sessions);
        self::assertSame(2, $batch->total);
        self::assertTrue($batch->truncated);
        self::assertSame('2026-09-16T12:34:57.000002Z', $batch->sessions[0]->recordedAt);
        self::assertSame('{"session":"new"}', trim($batch->sessions[0]->json));
        self::assertSame('var/query-log/20260916-123457-000002.json', $batch->sessions[0]->provenancePath);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/D', $batch->sessions[0]->id);
        self::assertEquals($batch->sessions[0], $source->find($batch->sessions[0]->id));
    }

    public function testReadsProductionSessionsNewestFirstWithDistinctOpaqueIds(): void
    {
        $source = QueryLogSource::jsonLinesFile($this->workspace, 'var/query-log.jsonl');

        $batch = $source->sessions(3);

        self::assertCount(3, $batch->sessions);
        self::assertSame(3, $batch->total);
        self::assertFalse($batch->truncated);
        self::assertSame('{"session":"duplicate"}', $batch->sessions[0]->json);
        self::assertSame('{"session":"duplicate"}', $batch->sessions[1]->json);
        self::assertNotSame($batch->sessions[0]->id, $batch->sessions[1]->id);
        self::assertSame('{"session":"old"}', $batch->sessions[2]->json);
        self::assertNull($batch->sessions[0]->recordedAt);
        self::assertSame('var/query-log.jsonl', $batch->sessions[0]->provenancePath);
    }

    public function testRejectsTooSmallLimit(): void
    {
        $source = QueryLogSource::directory($this->workspace, 'var/query-log');

        $this->expectException(\InvalidArgumentException::class);
        $source->sessions(0);
    }

    public function testRejectsTooLargeLimit(): void
    {
        $source = QueryLogSource::directory($this->workspace, 'var/query-log');

        $this->expectException(\InvalidArgumentException::class);
        $source->sessions(101);
    }

    public function testRejectsInvalidSessionIds(): void
    {
        $source = QueryLogSource::directory($this->workspace, 'var/query-log');

        $this->expectException(\InvalidArgumentException::class);
        $source->find('../latest.json');
    }
}
