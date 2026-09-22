<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\PhpactorCommandResolver;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(PhpactorCommandResolver::class)]
final class PhpactorCommandResolverTest extends TestCase
{
    public function testUsesBundledPhpactorByDefault(): void
    {
        $binary = dirname(__DIR__, 2) . '/vendor/bin/phpactor';
        self::assertFileExists($binary);
        self::assertSame([$binary], PhpactorCommandResolver::resolve(Workspace::fromPath(__DIR__)));
    }

    public function testAcceptsASimplePathCommandNameWithoutUsingAShell(): void
    {
        self::assertSame(
            ['phpactor'],
            PhpactorCommandResolver::resolve(Workspace::fromPath(__DIR__), 'phpactor'),
        );
    }

    public function testRejectsCommandArgumentsAndShellSyntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhpactorCommandResolver::resolve(Workspace::fromPath(__DIR__), 'phpactor --version; touch file');
    }

    public function testAcceptsAnExecutableAbsolutePath(): void
    {
        self::assertSame(
            [realpath(PHP_BINARY)],
            PhpactorCommandResolver::resolve(Workspace::fromPath(__DIR__), PHP_BINARY),
        );
    }
}
