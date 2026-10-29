<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Lsp;

/**
 * Keep Phpactor's index to the checkout the server was started for.
 *
 * Phpactor stores one file path per class name, so a git worktree inside the workspace (another branch's
 * copy of the same classes) overwrites the workspace's own entries. BEAR's generated DI/AOP classes under
 * var/tmp/ only add noise. The index lives in its own directory so entries written by an earlier,
 * unrestricted index are never reused.
 */
final class IndexScope
{
    /** Phpactor's defaults, which an explicit list replaces. */
    private const PHPACTOR_DEFAULT_EXCLUDES = [
        '/vendor/**/Tests/**/*',
        '/vendor/**/tests/**/*',
        '/vendor/composer/**/*',
        '/vendor/rector/rector/stubs-rector',
    ];

    /** Claude Code's worktree directory, excluded even when git no longer lists a worktree there. */
    private const KNOWN_WORKTREE_DIRECTORIES = ['.claude/worktrees'];

    private function __construct()
    {
    }

    /** @return array{'indexer.index_path': string, 'indexer.exclude_patterns': list<string>} */
    public static function configuration(string $workspaceRoot): array
    {
        $directories = [...self::KNOWN_WORKTREE_DIRECTORIES, ...self::gitWorktreesInside($workspaceRoot)];
        $patterns = [...self::PHPACTOR_DEFAULT_EXCLUDES, '/var/tmp/**/*'];
        foreach (array_unique($directories) as $directory) {
            $patterns[] = '/' . $directory . '/**/*';
        }

        return [
            'indexer.index_path' => '%cache%/bear-sunday-mcp-index/%project_id%',
            'indexer.exclude_patterns' => $patterns,
        ];
    }

    /**
     * Read git's own worktree records instead of running git: each linked worktree has
     * <common dir>/worktrees/<name>/gitdir naming its "<worktree>/.git" file.
     *
     * @return list<string> Workspace-relative directories.
     */
    private static function gitWorktreesInside(string $workspaceRoot): array
    {
        $commonDir = self::gitCommonDir($workspaceRoot);
        if ($commonDir === null) {
            return [];
        }
        $records = glob($commonDir . '/worktrees/*/gitdir');
        if ($records === false) {
            return [];
        }

        $root = rtrim($workspaceRoot, '/') . '/';
        $directories = [];
        foreach ($records as $record) {
            $gitFile = trim((string) @file_get_contents($record));
            $worktree = realpath(dirname($gitFile));
            if ($worktree === false || !str_starts_with($worktree . '/', $root) || $worktree . '/' === $root) {
                continue;
            }
            $relative = substr($worktree, strlen($root));
            // A glob metacharacter in the name would widen the pattern beyond this directory.
            if (strpbrk($relative, '*?[]{}') === false) {
                $directories[] = $relative;
            }
        }

        return $directories;
    }

    private static function gitCommonDir(string $workspaceRoot): ?string
    {
        $dotGit = $workspaceRoot . '/.git';
        if (is_dir($dotGit)) {
            return $dotGit;
        }
        // The workspace is itself a linked worktree; its main checkout lies outside the workspace,
        // but worktrees nested in this one would still be listed in the common directory.
        if (!is_file($dotGit)) {
            return null;
        }
        if (preg_match('/^gitdir:\s*(.+)$/m', (string) @file_get_contents($dotGit), $match) !== 1) {
            return null;
        }
        $gitDir = trim($match[1]);
        if (!str_starts_with($gitDir, '/')) {
            $gitDir = $workspaceRoot . '/' . $gitDir;
        }
        $common = trim((string) @file_get_contents($gitDir . '/commondir'));
        if ($common === '') {
            return null;
        }
        $commonDir = realpath(str_starts_with($common, '/') ? $common : $gitDir . '/' . $common);

        return $commonDir === false ? null : $commonDir;
    }
}
