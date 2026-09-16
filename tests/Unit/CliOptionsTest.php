<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\CliOptions;

#[CoversClass(CliOptions::class)]
final class CliOptionsTest extends TestCase
{
    public function testParsesSeparatedAndEqualsOptions(): void
    {
        $options = CliOptions::parse([
            '--workspace',
            '/workspace',
            '--phpactor=/bin/phpactor',
            '--timeout',
            '3.5',
        ]);

        self::assertSame('/workspace', $options->workspace);
        self::assertSame('/bin/phpactor', $options->phpactor);
        self::assertSame(3.5, $options->timeout);
        self::assertNull($options->queryLogDir);
        self::assertNull($options->queryLogFile);
    }

    public function testParsesOneQueryLogSource(): void
    {
        $directory = CliOptions::parse(['--workspace=/workspace', '--query-log-dir=var/log/query']);
        $file = CliOptions::parse(['--workspace=/workspace', '--query-log-file=var/log/query.jsonl']);

        self::assertSame('var/log/query', $directory->queryLogDir);
        self::assertNull($directory->queryLogFile);
        self::assertNull($file->queryLogDir);
        self::assertSame('var/log/query.jsonl', $file->queryLogFile);
    }

    public function testRejectsMultipleQueryLogSources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CliOptions::parse([
            '--workspace=/workspace',
            '--query-log-dir=var/log/query',
            '--query-log-file=var/log/query.jsonl',
        ]);
    }

    public function testRequiresWorkspace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CliOptions::parse([]);
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CliOptions::parse(['--workspace=/workspace', '--execute=anything']);
    }

    public function testBoundsTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CliOptions::parse(['--workspace=/workspace', '--timeout=61']);
    }
}
