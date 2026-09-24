# Use cases

The BEAR.Sunday MCP server gives an AI client structured facts about one saved
workspace. It complements text search: use semantic tools first for supported BEAR
concepts, then inspect the returned files or fall back to search for code outside the
semantic model.

## Why this is different from grep

| Question | Text search | Semantic MCP result |
|---|---|---|
| What is statically inconsistent across the project? | Many independent searches with no coverage signal | Bounded diagnostics with scan counts, truncation, and skipped checks |
| Where are JSON Schema or ALPS contracts not adopted yet? | Attribute and convention searches with no applicability or completeness signal | Per-method surface states and a bounded project summary |
| Which DI/AOP declarations are statically visible? | Fluent-call fragments that require manual alias and chain interpretation | Direct binding declarations, matcher trees, and reasoned unresolved forms |
| What implements a Resource URI? | Matching string or class fragments | Normalized URI, FQN, and workspace-relative path |
| What is the Resource interface? | Separate searches for `on*` methods | Public `on*` methods and declared parameter types |
| Where is the Resource used? | Every matching string | Static references that resolve to the same canonical Resource |
| Who links to or embeds it? | Attribute text requiring manual interpretation | Typed incoming and outgoing Link/Embed relations |
| What file implements a route, SQL ID, or template? | Candidate text matches | A resolved target or an explicit semantic failure status |
| What does an ALPS descriptor relate to? | JSON text matches | Explicit local descriptor relationships |
| Which cache or Resource attributes are present? | Short-name matches, aliases, comments, and dynamic values mixed together | FQN-allowlisted class/method facts with typed static arguments and explicit `dynamic` markers |
| Did Resource, Schema, and ALPS names drift? | Separate searches and manual set comparison | Per-surface status plus a deterministic presence matrix |

The server does not claim that every search problem is semantic. Comments, arbitrary
configuration, unsupported framework extensions, and dynamically constructed values still
require source inspection or text search.

## 1. Audit project-wide static inconsistencies

Ask:

```text
Audit this project for statically provable inconsistencies. Separate findings from checks
that were skipped or truncated.
```

Use `bear_project_diagnostics`, then inspect `total`, `offset`, `truncated`, `scannedFiles`,
`scannedResources`, `resourceScanTruncated`, and `skippedChecks` before interpreting the
items. The outer query remains `ok` when an individual saved file or explicit reference is
broken; those failures are diagnostic items. Findings are static evidence, not runtime or
architectural judgments. A small initial `limit` (for example 20) is useful for an overview;
the scan count and `total` still cover the whole project. For a complete audit, continue
with the next `offset` until `truncated` is false, advancing by the returned item count.

## 2. Plan JSON Schema and ALPS adoption

Ask:

```text
Show the project's contract coverage. Separate absent adoption opportunities from dynamic or
unresolved declarations, and suggest a small first batch without treating gaps as errors.
```

Use `bear_contract_coverage` with `gapsOnly: true` and a small initial `limit` (for example 20),
then inspect `total`, `matchingTotal`,
`offset`, `truncated`, `scannedResources`, `analyzedResources`, and `resourceScanTruncated`.
Optionally select `scheme: "page"` or `"app"` before pagination; `summary.schemes`
still counts the complete project. These are URI families, not proven HTML/JSON
representations or public/private exposure. An `absent` surface is not a requirement
violation; choose which boundaries merit a contract using project context.
The `summary` is computed across all analyzed methods regardless of page size or `gapsOnly`.
Fetch more pages when the question needs more individual methods; for a complete list,
continue with the next `offset` while `truncated` is true, advancing by the returned item count.
Each Resource method reports request Schema,
response Schema, and ALPS states as `available`, `absent`, `dynamic`, `unresolved`, or
`not_applicable`. In the UI, request-Schema `not_applicable` is labelled “No request fields”.
`covered` only means every applicable surface is statically available; it is
not a code-quality score. Prefer `absent` surfaces as adoption candidates, inspect source for
`dynamic`, and resolve explicit broken references reported as `unresolved` before generating
new artifacts. Do not bulk-generate placeholders to raise `coveredMethods`; select one coherent
Resource workflow and follow the project's existing Schema and ALPS conventions.

An MCP Apps-compatible host can render the same result as an interactive, read-only view. Its
summary bars and table filters are presentation of the existing `structuredContent`, not a second
coverage calculation. `absent` remains an optional adoption opportunity rather than an error,
while `resourceScanTruncated` and result truncation are shown as incomplete-coverage warnings.
Select a Resource method to inspect its three surface facts. The source action sends the
workspace-relative path to the host assistant via `ui/message`; whether that opens an editor is
host-dependent. Copying the path remains available when the host cannot handle that request.
Text-only hosts continue to receive the same semantic result.

## 3. Learn an unfamiliar project

Ask:

```text
Summarize this BEAR.Sunday project. List its Resource counts and the first Page and App
Resources, then describe the Resources that look like entry points.
```

Typical tool sequence:

1. `bear_project_info`
2. `bear_resource_list` with `scheme: page`
3. `bear_resource_list` with `scheme: app`
4. `bear_resource_describe` for selected URIs

This establishes the semantic protocol, advertised requests, project capabilities, Resource inventory,
methods, relations, templates, and schemas without executing the application.
Advance `offset` by the returned item count while a Resource list page reports
`truncated: true`; the 200-item maximum is a page-size bound, not an inventory cutoff.

## 4. Estimate the impact of changing a Resource

Ask:

```text
Before I change app://self/user, show its public methods, outgoing Link/Embed relations,
incoming relations, static references, templates, and schemas.
```

Typical tool sequence:

1. `bear_resource_describe`
2. `bear_resource_references`
3. `bear_resource_incoming_relations`

Use the returned paths and ranges to open only the relevant files. A bounded result reports
`total` and `truncated`; do not treat the returned page as the complete set when
`truncated` is true.

## 5. Trace a request surface

Ask one concrete question at a time:

```text
Resolve route /article/detail to its Page Resource.
Find the Twig template for page://self/article/detail.
Show the response schema for app://self/article.
Find the SQL file for query ID article_detail.
Describe the ALPS descriptor goArticle.
```

The corresponding tools are `bear_route_lookup`, `bear_template_for_resource`,
`bear_schema_lookup`, `bear_sql_lookup`, and `bear_alps_descriptor_lookup`.

Resolution is deliberately conservative. Dynamic expressions, custom loaders, external
ALPS links, and ambiguous conventions are not guessed.

## 6. Audit Resource attributes

Ask:

```text
Audit App Resources for Cacheable, Purge, Refresh, Link, Embed, JsonSchema, and Alps
attributes. Separate static arguments from dynamic expressions and show per-file failures.
```

Use `bear_resource_attribute_index` for the bounded workspace view, then
`bear_resource_attributes` for one Resource. The index has `total`/`truncated` and an
independent `status` per Resource, so one malformed file does not erase facts from the
others. Only the documented FQNs are recognized; application PHP is never evaluated.
Both tools return an explicit-only argument policy. An omitted attribute argument does
not mean that the constructor has no default, and installed-package defaults are not
expanded or guessed.

## 7. Inspect DI and AOP declarations

Ask:

```text
List direct static Ray.Di bindings and Ray.Aop interceptor declarations. Keep dynamic or
unsupported forms unresolved, and do not infer the active context or runtime weaving.
```

Use `bear_di_bindings` and `bear_aop_pointcuts`. Both return saved-source declaration
inventories with stable pagination and optional exact type/interceptor filters. A resolved
declaration proves only that its syntax was read. It does not prove which application context
installs the module, which binding wins after overrides, whether a matcher selects a method, or
whether an interceptor is woven at runtime. Inspect `unresolved` items in source rather than
turning them into guessed facts.

## 8. Compare contract name presence

Ask:

```text
For app://self/user onPost, compare request-name presence across Resource parameters,
the request JSON Schema, and its ALPS operation descriptor.
```

Use `bear_contract_compare` with `schemaKind: request`. Each surface reports its own
`status`, `subject`, and names. A comparison appears only when two or more surfaces are
available. Equal names are evidence of spelling presence only—not type, constraint,
meaning, or runtime compatibility. For response comparison the Resource body surface is
available only when straight-line source proves a complete literal-key `$this->body` shape;
dynamic or conditional construction remains `unsupported`.

## 9. Navigate from an exact source position

When the client already knows a saved file and cursor position, use the standard LSP tools:

- `lsp_definition`
- `lsp_type_definition`
- `lsp_references`
- `lsp_hover`
- `lsp_completion`
- `lsp_document_links`
- `lsp_document_symbols`
- `lsp_workspace_symbols`

Line and character are zero-based. Character positions use the LSP UTF-16 convention.
Identifier-based BEAR tools are preferable when the client has a Resource URI or another
BEAR identifier but no reliable cursor position.

`lsp_workspace_symbols` is narrower than document symbols. Phpactor's workspace provider
returns indexed class, function, and constant records, not methods. Its index freshness is
reported as unknown, so an empty result is not proof of absence and a newly saved file may
not be indexed yet. For a known file, use `lsp_document_symbols`; for method discovery or
an inconclusive empty result, fall back to source search.

## 10. Validate an AI-generated change

The server never edits files, but it can verify that a saved change is visible through the
same semantic layer used by the IDE:

1. Generate or edit files with the normal coding workflow.
2. Save them.
3. Confirm the Resource appears in `bear_resource_list`.
4. Confirm its methods and relations with `bear_resource_describe`.
5. Re-read supported attributes with `bear_resource_attributes`.
6. Run `bear_contract_compare` for the changed request/response surface.
7. Resolve its schema and template.
8. Check references or document links from the call site.
9. Run the project's tests and static analysis separately.

This catches convention and resolution mistakes. It does not prove runtime behavior.

## 11. Interpret results safely

Every BEAR Semantic API result preserves the same envelope:

```json
{
  "status": "ok",
  "data": {},
  "candidates": [],
  "provenance": []
}
```

- `status` distinguishes success, absence, ambiguity, invalid input, parse errors, and an
  unavailable engine.
- `data` is present and non-null only for `status: ok`; null members are omitted on the
  stdio wire. An optional `partial` on a failure contains
  narrower facts proven before that failure and must not be treated as success. A missing
  Resource template can preserve the resolved Resource and searched paths this way.
- `candidates` preserves bounded alternatives instead of selecting one by guesswork.
- `provenance` identifies saved workspace files and ranges that support the result.
- Paths are workspace-relative. The adapter does not expose files outside its fixed root.

An empty list is not always equivalent to "the concept does not exist." Check `status`,
capabilities, `available`, and `truncated` before drawing a conclusion.

## Boundaries

The MCP server is read-only and operates on saved files. It does not:

- execute the BEAR application or arbitrary PHP;
- render templates;
- edit or generate code;
- run Composer, tests, migrations, or deployment checks;
- resolve dynamic expressions by guessing;
- inspect runtime cache behavior;
- replace general source search for unsupported concepts.

Those activities belong to an agent workflow or a specialized runtime evidence provider.
