<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\QueryLog;

use Suzumaze\BearSundayMcp\Workspace;

/**
 * Reads local QueryRepository session artifacts without interpreting events.
 */
final class QueryLogSource
{
    private const MAX_SESSION_BYTES = 1_048_576;
    private const MAX_SCAN_BYTES = 4_194_304;
    private const MAX_SESSIONS = 100;
    private const SESSION_PATTERN = '/^(\d{8})-(\d{6})-(\d{6})\.json$/D';

    private function __construct(
        private string $absolutePath,
        private string $relativePath,
        private bool $directory,
    ) {
    }

    public static function directory(Workspace $workspace, string $relativePath): self
    {
        $absolute = $workspace->configuredDirectory($relativePath);

        return new self($absolute, self::relative($workspace, $absolute), true);
    }

    public static function jsonLinesFile(Workspace $workspace, string $relativePath): self
    {
        $absolute = $workspace->configuredFile($relativePath);

        return new self($absolute, self::relative($workspace, $absolute), false);
    }

    public function sessions(int $limit): QueryLogBatch
    {
        if ($limit < 1 || $limit > self::MAX_SESSIONS) {
            throw new \InvalidArgumentException('Query log limit must be between 1 and 100');
        }

        return $this->directory ? $this->directorySessions($limit) : $this->jsonLineSessions($limit);
    }

    public function find(string $sessionId): ?QueryLogSession
    {
        if (preg_match('/^[a-f0-9]{24}$/D', $sessionId) !== 1) {
            throw new \InvalidArgumentException('Invalid query log session ID');
        }
        foreach ($this->sessions(self::MAX_SESSIONS)->sessions as $session) {
            if (hash_equals($session->id, $sessionId)) {
                return $session;
            }
        }

        return null;
    }

    private function directorySessions(int $limit): QueryLogBatch
    {
        $files = glob($this->absolutePath . '/*.json');
        if ($files === false) {
            $files = [];
        }
        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => preg_match(self::SESSION_PATTERN, basename($file)) === 1,
        ));
        rsort($files, SORT_STRING);
        $total = count($files);
        $sessions = [];
        foreach (array_slice($files, 0, min($limit, self::MAX_SESSIONS)) as $file) {
            $size = filesize($file);
            $json = $size !== false && $size <= self::MAX_SESSION_BYTES ? file_get_contents($file) : false;
            $name = basename($file);
            preg_match(self::SESSION_PATTERN, $name, $matches);
            $sessions[] = new QueryLogSession(
                $this->sessionId($this->relativePath . '/' . $name),
                isset($matches[1], $matches[2], $matches[3])
                    ? sprintf(
                        '%s-%s-%sT%s:%s:%s.%sZ',
                        substr($matches[1], 0, 4),
                        substr($matches[1], 4, 2),
                        substr($matches[1], 6, 2),
                        substr($matches[2], 0, 2),
                        substr($matches[2], 2, 2),
                        substr($matches[2], 4, 2),
                        $matches[3],
                    )
                    : null,
                is_string($json) ? $json : '',
                $this->relativePath . '/' . $name,
            );
        }

        return new QueryLogBatch($sessions, $total, $total > $limit);
    }

    private function jsonLineSessions(int $limit): QueryLogBatch
    {
        $size = filesize($this->absolutePath);
        if ($size === false || $size === 0) {
            return new QueryLogBatch([], 0, false);
        }
        $start = max(0, $size - self::MAX_SCAN_BYTES);
        $handle = fopen($this->absolutePath, 'rb');
        if ($handle === false || fseek($handle, $start) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return new QueryLogBatch([], 0, false);
        }
        $contents = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($contents)) {
            return new QueryLogBatch([], 0, false);
        }
        if ($start > 0) {
            $newline = strpos($contents, "\n");
            $contents = $newline === false ? '' : substr($contents, $newline + 1);
        }
        $lines = array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''));
        $total = count($lines);
        $selectedIndexes = array_slice(array_reverse(array_keys($lines)), 0, min($limit, self::MAX_SESSIONS));
        $sessions = [];
        foreach ($selectedIndexes as $lineIndex) {
            $line = $lines[$lineIndex];
            $json = strlen($line) <= self::MAX_SESSION_BYTES ? $line : '';
            $sessions[] = new QueryLogSession(
                $this->sessionId($this->relativePath . "\0" . $lineIndex . "\0" . hash('sha256', $line)),
                null,
                $json,
                $this->relativePath,
            );
        }

        return new QueryLogBatch($sessions, $total, $total > $limit || $start > 0);
    }

    private function sessionId(string $identity): string
    {
        return substr(hash('sha256', $identity), 0, 24);
    }

    private static function relative(Workspace $workspace, string $absolute): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($workspace->root) + 1));
    }
}
