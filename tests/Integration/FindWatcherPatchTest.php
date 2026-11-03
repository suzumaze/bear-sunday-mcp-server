<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Integration;

use Amp\Loop;
use Phpactor\AmpFsWatch\Watcher\Find\FindWatcher;
use Phpactor\AmpFsWatch\WatcherConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

/**
 * Phpactor stops and restarts its watcher on every reindex (every 300 seconds by default). Without
 * patches/amp-fswatch-one-poll-loop-per-watcher.patch, each restart during a search leaves one more
 * poll loop running for the life of the process, so find(1) runs over the workspace many times at once.
 */
#[CoversNothing]
final class FindWatcherPatchTest extends TestCase
{
    public function testRestartingTheWatcherLeavesOneSearchRunning(): void
    {
        $directory = sys_get_temp_dir() . '/bear-mcp-find-watcher-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $log = $directory . '/searches.log';
        self::assertTrue(touch($log));
        $path = (string) getenv('PATH');
        putenv('PATH=' . dirname(__DIR__) . '/Fixture/slow-find:' . $path);
        putenv('SLOW_FIND_LOG=' . $log);

        $running = [];
        try {
            Loop::run(static function () use ($directory, $log, &$running): \Generator {
                $watcher = new FindWatcher(new WatcherConfig([$directory], 100));
                $process = yield $watcher->watch();
                for ($restart = 0; $restart < 3; $restart++) {
                    yield delay(300);
                    $process->stop();
                    $process = yield $watcher->watch();
                }
                // Searches started before the last restart end within 2 seconds; sample after that.
                yield delay(2500);
                for ($sample = 0; $sample < 6; $sample++) {
                    $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
                    $counts = array_count_values($lines);
                    $running[] = ($counts['start'] ?? 0) - ($counts['end'] ?? 0);
                    yield delay(500);
                }
                $process->stop();
                Loop::stop();
            });
        } finally {
            putenv('PATH=' . $path);
            putenv('SLOW_FIND_LOG');
        }

        self::assertLessThanOrEqual(1, max([0, ...$running]), 'searches running at once: ' . implode(' ', $running));
    }
}
