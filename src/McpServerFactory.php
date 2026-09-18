<?php

declare(strict_types=1);

namespace Suzumaze\BearSundayMcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;

final class McpServerFactory
{
    public static function create(SemanticTools $tools, StandardLspTools $lspTools): Server
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
            [$tools, 'resourceAttributes'],
            name: 'bear_resource_attributes',
            title: 'Describe BEAR Resource attributes',
            description: 'Return allowlisted class and Resource-method attributes with bounded static arguments; '
                . 'dynamic expressions are marked rather than evaluated. Only arguments written in source are '
                . 'returned; omitted constructor defaults are not expanded.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['resourceUri']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'resourceAttributeIndex'],
            name: 'bear_resource_attribute_index',
            title: 'Index BEAR Resource attributes',
            description: 'List bounded Resource attribute facts with an independent semantic status per Resource. '
                . 'Only arguments written in source are returned; omitted constructor defaults are not expanded.',
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
                'limit' => self::limitSchema('Maximum number of Resources to inspect.'),
            ]),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'contractCompare'],
            name: 'bear_contract_compare',
            title: 'Compare BEAR contract surfaces',
            description: 'Compare exact name presence across a Resource request surface, JSON Schema, and ALPS. '
                . 'This does not claim type, meaning, or behavioral compatibility.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'method' => self::identifierSchema('Exact Resource method name such as onGet or onPost.'),
                'schemaKind' => [
                    'type' => 'string',
                    'enum' => ['request', 'response'],
                    'description' => 'Contract surface to compare.',
                ],
                'descriptorId' => self::identifierSchema('Optional explicit ALPS descriptor ID override.'),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['resourceUri']),
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
        $builder->addTool(
            [$tools, 'routeLookup'],
            name: 'bear_route_lookup',
            title: 'Resolve a BEAR route',
            description: 'Resolve an explicit Aura Router route name to its Page Resource; HTTP paths are not guessed.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'route' => self::identifierSchema('Explicit Aura Router route name.'),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['route']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'sqlLookup'],
            name: 'bear_sql_lookup',
            title: 'Resolve a BEAR SQL query',
            description: 'Resolve a static BEAR SQL query ID to its workspace-relative SQL file '
                . 'without returning SQL text.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'queryId' => self::identifierSchema('Static DbQuery or @Query identifier.'),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['queryId']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'templateLookup'],
            name: 'bear_template_lookup',
            title: 'Resolve a BEAR template reference',
            description: 'Resolve one explicit Twig or Qiq template name; relative Qiq names require contextPath.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'engine' => self::templateEngineSchema(),
                'name' => self::identifierSchema('Static template name supported by the BEAR extension.'),
                'contextPath' => self::pathSchema('Optional workspace-relative source template path.'),
            ], ['engine', 'name']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'templateForResource'],
            name: 'bear_template_for_resource',
            title: 'Resolve a Resource template',
            description: 'Resolve a convention-based Twig or Qiq template for a BEAR Resource URI. When the '
                . 'Resource exists but no template does, not_found has no success data and includes the Resource '
                . 'and searched paths in partial.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'engine' => self::templateEngineSchema(),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['resourceUri', 'engine']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'alpsDescriptorLookup'],
            name: 'bear_alps_descriptor_lookup',
            title: 'Describe an ALPS descriptor',
            description: 'Describe an explicit descriptor and its local relationships in the JSON ALPS profile '
                . 'selected by apidoc.xml.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'descriptorId' => self::identifierSchema('Exact ALPS descriptor ID.'),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
            ], ['descriptorId']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'resourceReferences'],
            name: 'bear_resource_references',
            title: 'Find BEAR Resource references',
            description: 'Find bounded, deterministic static URI and Route references to a Resource.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
                'limit' => self::limitSchema('Maximum number of references to return.'),
            ], ['resourceUri']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$tools, 'resourceIncomingRelations'],
            name: 'bear_resource_incoming_relations',
            title: 'Find incoming BEAR Resource relations',
            description: 'Find bounded, deterministic Link and Embed relations targeting a Resource.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'resourceUri' => self::uriSchema(),
                'contextPath' => self::pathSchema('Optional workspace-relative context path.'),
                'limit' => self::limitSchema('Maximum number of incoming relations to return.'),
            ], ['resourceUri']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'definition'],
            name: 'lsp_definition',
            title: 'Go to definition with Phpactor',
            description: 'Run standard textDocument/definition at a position in a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'line' => self::lineSchema(),
                'character' => self::characterSchema(),
                'limit' => self::limitSchema('Maximum workspace locations to return.'),
            ], ['path', 'line', 'character']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'typeDefinition'],
            name: 'lsp_type_definition',
            title: 'Go to type definition with Phpactor',
            description: 'Run standard textDocument/typeDefinition at a position in a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'line' => self::lineSchema(),
                'character' => self::characterSchema(),
                'limit' => self::limitSchema('Maximum workspace locations to return.'),
            ], ['path', 'line', 'character']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'references'],
            name: 'lsp_references',
            title: 'Find references with Phpactor',
            description: 'Run standard textDocument/references at a position in a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'line' => self::lineSchema(),
                'character' => self::characterSchema(),
                'includeDeclaration' => [
                    'type' => 'boolean',
                    'description' => 'Whether the declaration should be included in the standard LSP result.',
                ],
                'limit' => self::limitSchema('Maximum workspace locations to return.'),
            ], ['path', 'line', 'character']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'hover'],
            name: 'lsp_hover',
            title: 'Inspect hover information with Phpactor',
            description: 'Run standard textDocument/hover at a position in a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'line' => self::lineSchema(),
                'character' => self::characterSchema(),
            ], ['path', 'line', 'character']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'completion'],
            name: 'lsp_completion',
            title: 'Complete with Phpactor',
            description: 'Run standard textDocument/completion at a position in a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'line' => self::lineSchema(),
                'character' => self::characterSchema(),
                'limit' => self::limitSchema('Maximum completion items to return.'),
            ], ['path', 'line', 'character']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'documentSymbols'],
            name: 'lsp_document_symbols',
            title: 'List document symbols with Phpactor',
            description: 'Run standard textDocument/documentSymbol for a saved workspace file.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'limit' => self::limitSchema('Maximum document symbols to return.'),
            ], ['path']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'documentLinks'],
            name: 'lsp_document_links',
            title: 'List document links with Phpactor',
            description: 'Run standard textDocument/documentLink and return only targets in the configured workspace.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'path' => self::documentPathSchema(),
                'limit' => self::limitSchema('Maximum resolved document links to return.'),
            ], ['path']),
            outputSchema: self::envelopeSchema(),
        );
        $builder->addTool(
            [$lspTools, 'workspaceSymbols'],
            name: 'lsp_workspace_symbols',
            title: 'Search workspace symbols with Phpactor',
            description: 'Search Phpactor\'s workspace index for class, function, and constant records in the '
                . 'configured workspace. Methods are excluded, index freshness is unknown, and an empty result '
                . 'is not proof that a saved symbol does not exist.',
            annotations: $annotations,
            inputSchema: self::objectSchema([
                'query' => [
                    'type' => 'string',
                    'maxLength' => 512,
                    'description' => 'Symbol query. An empty query requests the server default inventory.',
                ],
                'limit' => self::limitSchema('Maximum workspace symbols to return.'),
            ]),
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
    private static function documentPathSchema(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 4096,
            'description' => 'Workspace-relative path to a saved document.',
        ];
    }

    /** @return array<string, mixed> */
    private static function lineSchema(): array
    {
        return [
            'type' => 'integer',
            'minimum' => 0,
            'description' => 'Zero-based LSP line number.',
        ];
    }

    /** @return array<string, mixed> */
    private static function characterSchema(): array
    {
        return [
            'type' => 'integer',
            'minimum' => 0,
            'description' => 'Zero-based UTF-16 LSP character offset.',
        ];
    }

    /** @return array<string, mixed> */
    private static function identifierSchema(string $description): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 2048,
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function templateEngineSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => ['twig', 'qiq'],
            'description' => 'Template engine.',
        ];
    }

    /** @return array<string, mixed> */
    private static function limitSchema(string $description): array
    {
        return [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 200,
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function envelopeSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['status', 'candidates', 'provenance'],
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
                'partial' => [
                    'type' => ['object', 'null'],
                    'description' => 'Optional facts proven by a failed semantic query; status remains authoritative.',
                ],
                'candidates' => ['type' => 'array'],
                'provenance' => ['type' => 'array'],
                'error' => ['type' => 'object'],
            ],
            'additionalProperties' => true,
        ];
    }
}
