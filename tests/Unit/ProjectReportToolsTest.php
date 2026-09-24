<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp\Tests\Unit;

use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Suzumaze\BearSundayMcp\ProjectReportTools;
use Suzumaze\BearSundayMcp\SemanticTools;
use Suzumaze\BearSundayMcp\Tests\Support\InMemoryLspClient;

#[CoversClass(ProjectReportTools::class)]
final class ProjectReportToolsTest extends TestCase
{
    public function testDenseDiagnosticsKeepFullTextFallbackAndStructuredContent(): void
    {
        $result = self::envelope([
            'items' => array_fill(0, 100, [
                'code' => 'contract_name_mismatch',
                'path' => 'src/Resource/App/Example.php',
                'details' => ['onlyInResource' => ['nameOne', 'nameTwo']],
            ]),
            'total' => 100,
            'truncated' => false,
        ]);
        $reports = self::reports($result);

        $output = $reports->projectDiagnostics(100);

        self::assertSame($result, $output->structuredContent);
        self::assertCount(1, $output->content);
        $content = $output->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        $text = $content->text;
        self::assertSame($result, json_decode($text, true, 64, JSON_THROW_ON_ERROR));
        $pretty = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        self::assertLessThan((int) (strlen($pretty) * 0.75), strlen($text));
    }

    public function testCoverageFailureRemainsReadableWithoutApps(): void
    {
        $failure = [
            'status' => 'engine_unavailable',
            'data' => null,
            'candidates' => [],
            'provenance' => [],
            'error' => ['code' => 'unavailable', 'message' => 'Core capability unavailable'],
        ];

        $output = self::reports($failure)->contractCoverage(limit: 20, gapsOnly: true);

        self::assertSame($failure, $output->structuredContent);
        $content = $output->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertSame($failure, json_decode($content->text, true, 64, JSON_THROW_ON_ERROR));
    }

    public function testArchitectureInventoriesKeepStructuredAndCompactTextResults(): void
    {
        $result = self::envelope([
            'items' => [['state' => 'unresolved', 'reason' => 'binding_chain_unsupported']],
            'total' => 1,
            'truncated' => false,
        ]);
        $reports = self::reports($result);

        $bindings = $reports->diBindings();
        $pointcuts = $reports->aopPointcuts();

        self::assertSame($result, $bindings->structuredContent);
        self::assertSame($result, $pointcuts->structuredContent);
        $bindingContent = $bindings->content[0];
        $pointcutContent = $pointcuts->content[0];
        self::assertInstanceOf(TextContent::class, $bindingContent);
        self::assertInstanceOf(TextContent::class, $pointcutContent);
        self::assertSame(
            $result,
            json_decode($bindingContent->text, true, 64, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            $result,
            json_decode($pointcutContent->text, true, 64, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string,mixed> $result */
    private static function reports(array $result): ProjectReportTools
    {
        $client = new InMemoryLspClient(static fn (string $method): array => $method === 'bear/project/info'
            ? self::envelope([
                'semanticProtocol' => 'bear-semantic',
                'requests' => [
                    'bear/project/info',
                    'bear/project/diagnostics',
                    'bear/project/contractCoverage',
                    'bear/di/bindings',
                    'bear/aop/pointcuts',
                ],
            ])
            : $result);

        return new ProjectReportTools(new SemanticTools($client));
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function envelope(array $data): array
    {
        return [
            'status' => 'ok',
            'data' => $data,
            'candidates' => [],
            'provenance' => [],
        ];
    }
}
