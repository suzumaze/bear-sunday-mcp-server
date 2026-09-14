<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Workspace;

#[CoversClass(Workspace::class)]
final class WorkspaceTest extends TestCase
{
    public function testCanonicalizesAnExistingDirectory(): void
    {
        self::assertSame(realpath(__DIR__ . '/..'), Workspace::fromPath(__DIR__ . '/..')->root);
    }

    public function testRejectsAMissingDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Workspace::fromPath(__DIR__ . '/missing-workspace');
    }

    public function testRejectsAFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Workspace::fromPath(__FILE__);
    }
}
