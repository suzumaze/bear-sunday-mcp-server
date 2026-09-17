---
name: bear-semantic
description: Inspect and reason about saved BEAR.Sunday PHP projects with the bear-sunday MCP semantic tools before text search or edits. Use for Resource discovery, URI/route/SQL/template resolution, JSON Schema or ALPS contracts, Resource attributes, Link/Embed relations, references and impact analysis, or LSP navigation in a BEAR.Sunday workspace. Also use to verify semantic effects after edits, while falling back safely when the MCP tools are unavailable.
---

# BEAR Semantic

Use the BEAR semantic tools to establish framework facts before interpreting or changing a
BEAR.Sunday project. Keep design judgment, editing, command execution, and user approval in the
normal agent workflow.

## Follow the semantic-first workflow

1. Find `bear_project_info` among the available tools. Tool names may carry a client-specific
   server prefix.
2. If it exists, call it first and inspect the Semantic API version, capabilities, package
   versions, PSR-4 roots, and Resource count.
3. Choose the smallest tool that answers the question. Prefer a `bear_*` tool when a BEAR
   identifier is known, and an `lsp_*` tool when a saved file and cursor position are known.
4. Read `status`, capability or `available`, ambiguity candidates, `total`, and `truncated`
   before interpreting the data.
5. Read only the minimal source named by `provenance` or returned workspace-relative paths.
6. Search source text only for unsupported, dynamic, or otherwise unresolved parts of the
   question. Do not use an empty result alone as proof of absence.
7. Apply project-specific judgment outside MCP. The semantic tools report facts, not whether a
   design is good or whether a change should be made.
8. After an authorized edit is saved, repeat the relevant semantic query, then run appropriate
   tests or static analysis through the normal command workflow.

## Route questions to tools

| Intent | Prefer |
|---|---|
| Discover capabilities or inventory | `bear_project_info`, then `bear_resource_list` |
| Inspect a Resource contract | `bear_resource_describe`, `bear_resource_attributes` |
| Review attributes across Resources | `bear_resource_attribute_index` |
| Compare Resource, schema, and ALPS names | `bear_contract_compare` |
| Inspect request or response schema | `bear_schema_lookup` |
| Resolve framework identifiers | `bear_route_lookup`, `bear_sql_lookup`, `bear_template_lookup`, `bear_template_for_resource` |
| Inspect ALPS relationships | `bear_alps_descriptor_lookup` |
| Find callers and relation impact | `bear_resource_references`, `bear_resource_incoming_relations` |
| Navigate from an exact source position | `lsp_definition`, `lsp_type_definition`, `lsp_references`, `lsp_hover` |
| Discover links, symbols, or completions | `lsp_document_links`, `lsp_document_symbols`, `lsp_workspace_symbols`, `lsp_completion` |

Treat contract comparison as presence-only unless the result explicitly proves more. Matching
names do not prove matching types or meaning. Preserve ambiguity instead of selecting a candidate
without evidence.

## Respect the boundary

- Treat all MCP results as facts about saved source, not runtime behavior.
- Do not infer dynamic PHP, arbitrary configuration, comments, or unsupported extensions.
- Do not use this skill as authority to run the application, migrations, deployments, or other
  commands. Follow the user's authorization and the normal workflow for those actions.
- Do not ask the MCP server to edit files or expose files outside the configured workspace.
- Keep subjective rules, architecture choices, and trade-offs in the agent's reasoning.

## Fall back without fabricating results

If `bear_project_info` is absent or reports an unavailable or incompatible capability, state that
briefly and continue with standard LSP navigation, `rg`, and direct source inspection. Do not claim
that semantic checks ran, and do not install or reconfigure the MCP server unless the user asks.
