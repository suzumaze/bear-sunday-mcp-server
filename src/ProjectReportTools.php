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

    public function appContextList(?string $contextPath = null, int $limit = 50, int $offset = 0): CallToolResult
    {
        return self::result($this->tools->appContextList($contextPath, $limit, $offset));
    }

    public function diBindingLookup(
        string $applicationContext,
        ?string $type = null,
        ?string $name = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
        bool $overridesOnly = false,
        bool $resourcesOnly = false,
    ): CallToolResult {
        return self::result($this->tools->diBindingLookup(
            $applicationContext,
            $type,
            $name,
            $contextPath,
            $limit,
            $offset,
            $overridesOnly,
            $resourcesOnly,
        ));
    }

    public function aopApplications(
        string $applicationContext,
        ?string $uri = null,
        ?string $interceptor = null,
        ?string $attribute = null,
        ?string $method = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): CallToolResult {
        return self::result($this->tools->aopApplications(
            $applicationContext,
            $uri,
            $interceptor,
            $attribute,
            $method,
            $contextPath,
            $limit,
            $offset,
        ));
    }

    public function attributeCatalog(
        ?string $applicationContext = null,
        ?string $attribute = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): CallToolResult {
        return self::result($this->tools->attributeCatalog(
            $applicationContext,
            $attribute,
            $contextPath,
            $limit,
            $offset,
        ));
    }

    public function diModuleTreeRead(?string $applicationContext = null): CallToolResult
    {
        return self::result($this->tools->diModuleGraph($applicationContext));
    }

    public function diModuleDeclarations(
        string $module,
        ?string $applicationContext = null,
        ?string $contextPath = null,
        int $limit = 50,
        int $offset = 0,
    ): CallToolResult {
        return self::result($this->tools->diModuleDeclarations(
            $module,
            $applicationContext,
            $contextPath,
            $limit,
            $offset,
        ));
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
