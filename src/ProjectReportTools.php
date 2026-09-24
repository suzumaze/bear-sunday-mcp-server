<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/** Preserve complete text and structured results without pretty-printing large report pages twice. */
final readonly class ProjectReportTools
{
    public function __construct(private SemanticTools $tools)
    {
    }

    public function projectDiagnostics(int $limit = 100, int $offset = 0): CallToolResult
    {
        return self::result($this->tools->projectDiagnostics($limit, $offset));
    }

    public function contractCoverage(
        int $limit = 100,
        int $offset = 0,
        bool $gapsOnly = false,
        ?string $scheme = null,
    ): CallToolResult {
        return self::result($this->tools->contractCoverage($limit, $offset, $gapsOnly, $scheme));
    }

    public function diBindings(?string $type = null, int $limit = 50, int $offset = 0): CallToolResult
    {
        return self::result($this->tools->diBindings($type, $limit, $offset));
    }

    public function aopPointcuts(?string $interceptor = null, int $limit = 50, int $offset = 0): CallToolResult
    {
        return self::result($this->tools->aopPointcuts($interceptor, $limit, $offset));
    }

    /** @param array<string,mixed> $data */
    private static function result(array $data): CallToolResult
    {
        $text = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );

        return new CallToolResult([new TextContent($text)], structuredContent: $data);
    }
}
