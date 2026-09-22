---
name: bear-semantic
description: Inspect and reason about saved BEAR.Sunday PHP projects with the bear-sunday MCP semantic tools before text search or edits. Use for Resource discovery, URI/route/SQL/template resolution, JSON Schema or ALPS contracts and adoption coverage, Resource attributes, Link/Embed relations, references and impact analysis, or LSP navigation in a BEAR.Sunday workspace. Also use to verify semantic effects after edits, while falling back safely when the MCP tools are unavailable.
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
3. For a project-wide review, call `bear_project_diagnostics` when the capability is available.
   Inspect `total`, `truncated`, `resourceScanTruncated`, and `skippedChecks`; zero returned items
   do not prove that a skipped or truncated check is clean. For a complete audit, advance
   `offset` by the number of returned items until `truncated` is false; for an overview,
   a small first page can preserve scan metadata without fetching all findings.
4. For JSON Schema or ALPS adoption planning, call `bear_contract_coverage` when the capability
   is available with `gapsOnly: true`. Treat `absent` as an optional adoption candidate, not an
   error; separate it from `dynamic`, `unresolved`, and `not_applicable`, inspect all scan bounds,
   and advance `offset` until `truncated` is false when the user needs the complete list.
   When only an overview is needed, use a small first page (for example `limit: 20`):
   `summary`, `total`, and scan metadata still cover the whole analyzed project. Fetch
   additional pages only for requested detail; never describe a partial page as a complete list.
5. Choose the smallest tool that answers the question. Prefer a `bear_*` tool when a BEAR
   identifier is known, and an `lsp_*` tool when a saved file and cursor position are known.
6. Read `status`, capability or `available`, ambiguity candidates, `total`, and `truncated`
   before interpreting the data. For `bear_resource_list` and `bear_resource_attribute_index`,
   advance `offset` by the number of returned items while `truncated` is true.
7. When `status` is not `ok`, expect no successful `data` (null members are omitted on the
   stdio wire) and inspect an optional `partial` member only as narrower facts proven before
   the failure. In particular, a missing Resource template may preserve the resolved Resource
   and searched convention paths in `partial`.
8. Read only the minimal source named by `provenance` or returned workspace-relative paths.
9. Search source text only for unsupported, dynamic, or otherwise unresolved parts of the
   question. Do not use an empty result alone as proof of absence.
10. Apply project-specific judgment outside MCP. The semantic tools report facts, not whether a
   design is good or whether a change should be made.
11. After an authorized edit is saved, repeat the relevant semantic query, then run appropriate
   tests or static analysis through the normal command workflow.

## Route questions to tools

| Intent | Prefer |
|---|---|
| Discover capabilities or inventory | `bear_project_info`, then `bear_resource_list` |
| Audit project-wide static inconsistencies | `bear_project_diagnostics` |
| Plan JSON Schema and ALPS adoption | `bear_contract_coverage` |
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

Treat contract coverage as planning evidence, not a maturity score or a mandate to reach 100%.
Before proposing files, select a small coherent Resource workflow based on the user's goal, inspect
its description and existing Schema/ALPS conventions, and separate `absent` adoption candidates
from `dynamic` or `unresolved` declarations. Do not bulk-generate placeholder contracts merely to
increase the covered count.

Treat Resource attribute arguments as explicit source syntax only unless `argumentPolicy` says
otherwise. An omitted argument does not prove that its constructor has no default. Do not invent
or hardcode installed-package defaults.

Treat `lsp_workspace_symbols` as a Phpactor index query for class, function, and constant records.
It does not search methods, its index freshness is unknown, and an empty result is inconclusive.
Use `lsp_document_symbols` for a known file and fall back to source search for methods or saved
files that may not yet be indexed.

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
