<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\Lsp\IndexScope;

#[CoversClass(IndexScope::class)]
final class IndexScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/index-scope-' . bin2hex(random_bytes(4));
        mkdir($base . '/app/.git/worktrees', 0777, true);
        $this->root = (string) realpath($base . '/app');
    }

    protected function tearDown(): void
    {
        $base = dirname($this->root);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }

    public function testExcludesGeneratedClassesAndClaudeWorktreesWithoutGitRecords(): void
    {
        $config = IndexScope::configuration($this->root);

        self::assertSame('%cache%/bear-sunday-mcp-index/%project_id%', $config['indexer.index_path']);
        self::assertSame([
            '/vendor/**/Tests/**/*',
            '/vendor/**/tests/**/*',
            '/vendor/composer/**/*',
            '/vendor/rector/rector/stubs-rector',
            '/var/tmp/**/*',
            '/.claude/worktrees/**/*',
        ], $config['indexer.exclude_patterns']);
    }

    public function testExcludesEveryWorktreeGitRecordsInsideTheWorkspace(): void
    {
        $this->addWorktree('inside', $this->root . '/wt/feature');
        $this->addWorktree('claude', $this->root . '/.claude/worktrees/session');
        $this->addWorktree('outside', dirname($this->root) . '/sibling');
        $this->addWorktree('glob', $this->root . '/wt/odd[name]');
        $this->addWorktree('pruned', $this->root . '/wt/deleted', create: false);

        $patterns = IndexScope::configuration($this->root)['indexer.exclude_patterns'];

        // Outside the workspace nothing is indexed anyway; a deleted worktree has nothing to index;
        // a glob character would make the pattern match more than the worktree.
        $worktreePatterns = array_values(array_filter(
            array_slice($patterns, 5),
            static fn (string $pattern): bool => $pattern !== '/.claude/worktrees/**/*',
        ));
        sort($worktreePatterns);
        self::assertSame(['/.claude/worktrees/session/**/*', '/wt/feature/**/*'], $worktreePatterns);
    }

    public function testFindsNestedWorktreesWhenTheWorkspaceIsItselfAWorktree(): void
    {
        $linked = $this->root . '/.git/worktrees/self';
        mkdir($linked);
        file_put_contents($linked . '/commondir', '../..');
        $workspace = dirname($this->root) . '/linked';
        mkdir($workspace);
        file_put_contents($workspace . '/.git', 'gitdir: ' . $linked . "\n");
        $this->addWorktree('nested', $workspace . '/wt/nested');

        $patterns = IndexScope::configuration((string) realpath($workspace))['indexer.exclude_patterns'];

        self::assertContains('/wt/nested/**/*', $patterns);
    }

    private function addWorktree(string $name, string $directory, bool $create = true): void
    {
        $record = $this->root . '/.git/worktrees/' . $name;
        if (!is_dir($record)) {
            mkdir($record);
        }
        if ($create) {
            mkdir($directory, 0777, true);
            file_put_contents($directory . '/.git', 'gitdir: ' . $record . "\n");
        }
        file_put_contents($record . '/gitdir', $directory . "/.git\n");
    }
}
