<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Workspace;
use Suzumaze\BearSundayMcp\WorkspaceFileException;

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

    public function testReadsOnlyBoundedWorkspaceDocuments(): void
    {
        $workspace = Workspace::fromPath(__DIR__ . '/../Fixture/workspace');
        $document = $workspace->readDocument('src/Resource/App/User.php');

        self::assertSame('src/Resource/App/User.php', $document->relativePath);
        self::assertSame('php', $document->languageId);
        self::assertStringStartsWith('file://', $document->uri);
        self::assertStringContainsString('final class User', $document->contents);
        self::assertSame($document->relativePath, $workspace->relativeExistingFile($document->absolutePath));
    }

    public function testRejectsTraversalAndMissingDocuments(): void
    {
        $workspace = Workspace::fromPath(__DIR__ . '/../Fixture/workspace');

        try {
            $workspace->readDocument('../outside.php');
            self::fail('Traversal must be rejected.');
        } catch (WorkspaceFileException $exception) {
            self::assertSame('invalid_input', $exception->status);
        }

        try {
            $workspace->readDocument('src/Missing.php');
            self::fail('Missing files must return a stable status.');
        } catch (WorkspaceFileException $exception) {
            self::assertSame('not_found', $exception->status);
        }
    }

    public function testRejectsASymlinkOutsideTheWorkspace(): void
    {
        $temporary = sys_get_temp_dir() . '/bear-mcp-workspace-' . bin2hex(random_bytes(8));
        $workspaceRoot = $temporary . '/workspace';
        $outside = $temporary . '/outside.php';
        try {
            self::assertTrue(mkdir($workspaceRoot, 0777, true));
            self::assertNotFalse(file_put_contents($outside, '<?php echo "outside";'));
            self::assertTrue(symlink($outside, $workspaceRoot . '/escape.php'));

            try {
                Workspace::fromPath($workspaceRoot)->readDocument('escape.php');
                self::fail('A symlink outside the workspace must be rejected.');
            } catch (WorkspaceFileException $exception) {
                self::assertSame('outside_workspace', $exception->status);
            }
        } finally {
            if (is_link($workspaceRoot . '/escape.php')) {
                unlink($workspaceRoot . '/escape.php');
            }
            if (is_file($outside)) {
                unlink($outside);
            }
            if (is_dir($workspaceRoot)) {
                rmdir($workspaceRoot);
            }
            if (is_dir($temporary)) {
                rmdir($temporary);
            }
        }
    }

    public function testResolvesConfiguredQueryLogPathsInsideTheWorkspace(): void
    {
        $workspace = Workspace::fromPath(__DIR__ . '/../Fixture/workspace');

        self::assertSame(
            realpath(__DIR__ . '/../Fixture/workspace/var/query-log'),
            $workspace->configuredDirectory('var/query-log'),
        );
        self::assertSame(
            realpath(__DIR__ . '/../Fixture/workspace/var/query-log.jsonl'),
            $workspace->configuredFile('var/query-log.jsonl'),
        );
    }

    #[DataProvider('unsafeConfiguredPathProvider')]
    public function testRejectsUnsafeConfiguredPaths(string $path): void
    {
        $workspace = Workspace::fromPath(__DIR__ . '/../Fixture/workspace');

        $this->expectException(\InvalidArgumentException::class);
        $workspace->configuredDirectory($path);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeConfiguredPathProvider(): iterable
    {
        yield 'traversal' => ['../query-log'];
        yield 'empty segment' => ['var//query-log'];
        yield 'current directory' => ['./var/query-log'];
    }
}
