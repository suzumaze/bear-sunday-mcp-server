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
