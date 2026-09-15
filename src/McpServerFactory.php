<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;

final class McpServerFactory
{
    public static function create(SemanticTools $tools): Server
    {
        $annotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $builder = Server::builder()->setServerInfo(
            'bear-sunday-mcp-server',
            Version::CURRENT,
            'Read-only bridge to BEAR.Sunday Semantic API v1 over Phpactor LSP.',
        );

        $builder->addTool(
            [$tools, 'projectInfo'],
            name: 'bear_project_info',
            title: 'BEAR project information',
            description: 'Report BEAR semantic capabilities, versions, workspace metadata, and Resource count.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ]),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'resourceList'],
            name: 'bear_resource_list',
            title: 'List BEAR Resources',
            description: 'List deterministic Resource URI candidates known to the workspace.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'scheme' => [
                    'type' => 'string',
                    'enum' => ['app', 'page'],
                    'description' => 'Optional Resource URI scheme filter.',
                ],
                'prefix' => [
                    'type' => 'string',
                    'maxLength' => 2048,
                    'description' => 'Optional URI path prefix without the scheme.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Maximum number of items to return.',
                ],
            ]),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'resourceDescribe'],
            name: 'bear_resource_describe',
            title: 'Describe a BEAR Resource',
            description: 'Resolve a Resource URI and return methods, Link/Embed relations, templates, and schemas.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'uri' => self::uriSchema(),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
                'incomingLimit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Maximum incoming relations to return.',
                ],
            ], ['uri']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'schemaLookup'],
            name: 'bear_schema_lookup',
            title: 'Describe a Resource schema',
            description: 'Return bounded request or response JSON Schema facts for a Resource URI; '
                . 'raw JSON is never returned.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'kind' => [
                    'type' => 'string',
                    'enum' => ['request', 'response'],
                    'description' => 'Schema kind. Resource convention lookup currently supports response schemas.',
                ],
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['resourceUri']),
            outputSchema: self::envelopeSchema(),
        );

        return $builder->build();
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function objectSchema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function pathSchema(string $description): array
    {
        return [
            'type' => 'string',
            'maxLength' => 4096,
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function uriSchema(): array
    {
        return [
            'type' => 'string',
            'maxLength' => 2048,
            'pattern' => '^(app|page)://',
            'description' => 'BEAR Resource URI.',
        ];
    }

    /** @return array<string, mixed> */
    private static function envelopeSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status', 'data', 'candidates', 'provenance'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        'ok',
                        'not_found',
                        'ambiguous',
                        'invalid_input',
                        'unsupported',
                        'parse_error',
                        'engine_unavailable',
                        'outside_workspace',
                        'timeout',
                    ],
                ],
                'data' => ['type' => ['object', 'null']],
                'candidates' => ['type' => 'array'],
                'provenance' => ['type' => 'array'],
                'error' => ['type' => 'object'],
            ],
            'additionalProperties' => true,
        ];
    }
}
