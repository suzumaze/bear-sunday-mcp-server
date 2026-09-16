# Use cases

The BEAR.Sunday MCP server gives an AI client structured facts about one saved
workspace. It complements text search: use semantic tools first for supported BEAR
concepts, then inspect the returned files or fall back to search for code outside the
semantic model.

## Why this is different from grep

| Question | Text search | Semantic MCP result |
|---|---|---|
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

## 1. Learn an unfamiliar project

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

This establishes the Semantic API version, project capabilities, Resource inventory,
methods, relations, templates, and schemas without executing the application.

## 2. Estimate the impact of changing a Resource

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

## 3. Trace a request surface

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

## 4. Audit Resource attributes

Ask:

```text
Audit App Resources for Cacheable, Purge, Refresh, Link, Embed, JsonSchema, and Alps
attributes. Separate static arguments from dynamic expressions and show per-file failures.
```

Use `bear_resource_attribute_index` for the bounded workspace view, then
`bear_resource_attributes` for one Resource. The index has `total`/`truncated` and an
independent `status` per Resource, so one malformed file does not erase facts from the
others. Only the documented FQNs are recognized; application PHP is never evaluated.

## 5. Compare contract name presence

Ask:

```text
For app://self/user onPost, compare request-name presence across Resource parameters,
the request JSON Schema, and its ALPS operation descriptor.
```

Use `bear_contract_compare` with `schemaKind: request`. Each surface reports its own
`status`, `subject`, and names. A comparison appears only when two or more surfaces are
available. Equal names are evidence of spelling presence only—not type, constraint,
meaning, or runtime compatibility. For response comparison the Resource body surface is
currently `unsupported`; Schema and an ALPS `rt` representation can still be compared.

## 6. Navigate from an exact source position

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

## 7. Validate an AI-generated change

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

## 8. Interpret results safely

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
