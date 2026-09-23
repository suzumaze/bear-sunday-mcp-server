# BEAR.Sunday MCP Server

New to the project? Start with the
[Feynman README（専門用語なしの日本語解説）](README.feynman.md).

Read-only MCP adapter for the BEAR.Sunday Semantic API exposed by
[`suzumaze/bear-phpactor-extension`](https://github.com/suzumaze/bear-phpactor-extension)
through the Phpactor Language Server.

```text
MCP client
    ↓ stdio MCP
bear-sunday-mcp-server
    ↓ stdio LSP (bear/* and standard requests)
Phpactor + bear-phpactor-extension
    ↓
saved files in one BEAR.Sunday workspace
```

The adapter contains no Resource URI, Router, SQL, template, ALPS, or JSON Schema
resolution rules. Phpactor remains the single semantic implementation used by both IDEs
and AI clients.

## Status

The server provides twenty-four read-only tools against BEAR Semantic API version 1 and
Phpactor's standard LSP. In addition to project, Resource, and schema facts, it resolves
explicit Route names, SQL query IDs, Twig/Qiq template names, Resource templates, and ALPS
descriptors. It also exposes allowlisted Resource attribute facts, a bounded attribute
inventory, presence-only contract comparison, static Resource references, and incoming
Link/Embed relations. Project-level contract coverage distinguishes absent, dynamic,
unresolved, available, and non-applicable JSON Schema and ALPS surfaces without treating
optional adoption gaps as errors.
Project diagnostics and contract coverage use stable offset pagination and an approximate
serialized-byte budget: a page can be shorter than its requested count. Advance `offset` by
the actual returned item count while `truncated` is true. Diagnostics accepts `limit` 1–200
(default 100); contract coverage accepts 1–100 (default 100). A single oversized item may
exceed the budget. `gapsOnly` selects adoption gaps without returning covered rows.
Use `scheme: "page"` or `"app"` to select a source URI family before pagination.
For a first overview, request a small page (for example `limit: 10` or `20`).
`total`, scan metadata, and contract-coverage `summary` still describe the whole scan;
fetch further pages only when the question requires their rows. This reduces returned
content, not scanning work or the host's tool-definition overhead. A complete audit
must follow `truncated` to the end, advancing by the actual returned item count.
Project-report text fallbacks retain the same full result as `structuredContent`, encoded
as compact JSON to avoid the additional whitespace of SDK pretty printing. Hosts may
surface one or both representations, so this reduces wire bytes but does not promise a
specific model-token saving.
The scheme alone does not establish whether a Resource is publicly exposed or rendered
as JSON; `absent` does not mean a Schema is required.
On hosts that support the open MCP Apps UI extension, `bear_contract_coverage` also renders a
coverage view with surface distributions, App/Page URI subtotals, Resource-method filtering, inline contract details,
and prominent scan-truncation warnings. Selecting a method can ask the host assistant to inspect
its workspace-relative source path; editor navigation depends on the host. The path can also be
copied. The view loads no external resources and does not replace the tool's text and
`structuredContent` response on other hosts.
Definition, Type Definition, References, and Hover can be queried at a position in a saved
workspace file; Completion, Document Links, document symbols, and workspace symbol search
are also available. Workspace symbol search covers Phpactor's indexed class, function, and
constant records, not methods, and does not claim that its index is current.

See [Project status](docs/project-status.md) ([日本語](docs/project-status.ja.md)) for the
implemented boundary, real-workspace verification evidence, and deferred experiments.
See the [tool table below](#tools) ([日本語の全24ツール一覧](docs/tools.ja.md)) for the
complete inventory, and [Use cases](docs/use-cases.md) ([日本語](docs/use-cases.ja.md))
for task-oriented workflows.

## Requirements

- PHP 8.2 or newer
- PHP extensions required by Phpactor: `mbstring`, `posix`, and `tokenizer`
- An MCP host that supports stdio servers

The standard installation bundles Phpactor 2026.07.22.0 and
`suzumaze/bear-phpactor-extension` 0.1.8 or newer. If you override `--phpactor`
with an older compatible installation, `bear_contract_coverage` needs its
`contractCoverage` capability; otherwise that tool returns `engine_unavailable`.

The MCP SDK is fixed to the compatible `0.8.x` line because its public API is not yet 1.0.

## Install

Install the released server into a dedicated directory:

```console
composer create-project --no-dev --prefer-dist \
  suzumaze/bear-sunday-mcp-server \
  /absolute/path/to/bear-sunday-mcp-server \
  '^0.10'
```

This installs the MCP adapter, Phpactor, and the BEAR extension into one dedicated
Composer environment. Composer's install script generates `.phpactor.json` there;
the adapter applies its extension list when launching the bundled Phpactor. No
packages or configuration files are added to the BEAR.Sunday workspace. If
Composer scripts were disabled, run `composer run phpactor:init` in the server
directory before starting it.

The MCP host still needs its own one-time server registration below. To reuse an
existing Phpactor installation instead of the bundled binary, pass `--phpactor`
or set `PHPACTOR_BIN`; that installation must load the BEAR extension itself.

For development from the repository instead:

```console
git clone https://github.com/suzumaze/bear-sunday-mcp-server.git \
  /absolute/path/to/bear-sunday-mcp-server
cd /absolute/path/to/bear-sunday-mcp-server
composer install
```

## Run directly

```console
/absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project
```

`--workspace` is required and canonicalized once at startup. `--phpactor` may be omitted.
The adapter checks `PHPACTOR_BIN`, the bundled `vendor/bin/phpactor`,
`WORKSPACE/vendor/bin/phpactor`, and finally `phpactor` on `PATH`, in that order.
An explicit `--phpactor` takes precedence over all of them. A configured command
is passed directly to `proc_open` as an argument array; shell command strings
and appended arguments are rejected.

The process normally appears to wait silently because MCP messages use stdin and stdout.

## Configure Codex

Register one BEAR.Sunday workspace with Codex CLI:

```console
codex mcp add bear-sunday -- \
  /absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project
codex mcp list
```

Codex CLI, the Codex app, and the Codex IDE extension on the same host share MCP
configuration. Restart the app or IDE extension after adding the server; use `/mcp` in the
CLI to inspect it. See the
[`Codex MCP documentation`](https://learn.chatgpt.com/docs/extend/mcp).

The equivalent `~/.codex/config.toml` entry is:

```toml
[mcp_servers.bear_sunday]
command = "/absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp"
args = [
    "--workspace=/absolute/path/to/bear-project",
]
startup_timeout_sec = 20
tool_timeout_sec = 60
enabled = true
```

## Configure Claude Code

Register the server for one local project without committing machine-specific paths:

```console
cd /absolute/path/to/bear-project
claude mcp add --scope local --transport stdio bear-sunday -- \
  /absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project
claude mcp list
claude mcp get bear-sunday
```

Use `/mcp` inside Claude Code to inspect the connection and available tools. See the
[`Claude Code MCP documentation`](https://code.claude.com/docs/en/mcp).

For a trusted project shared by a team, use `--scope project` or commit an `.mcp.json` file.
Do not commit personal absolute paths; use paths valid for every team member or document the
required substitution.

## Configure another MCP client

For MCP hosts that use an `mcpServers` JSON object:

```json
{
  "mcpServers": {
    "bear-sunday": {
      "type": "stdio",
      "command": "/absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp",
      "args": [
        "--workspace=/absolute/path/to/bear-project"
      ]
    }
  }
}
```

The initial transport is local stdio. Browser-only Claude.ai or ChatGPT sessions cannot
start this local process directly; supporting those environments would require a separately
secured Streamable HTTP deployment.

Each configured server is fixed to one workspace. Give entries distinct names, such as
`bear-project-a` and `bear-project-b`, when using multiple BEAR.Sunday projects.

## Connect a generic LSP client

An editor, CLI, or AI client with native LSP support can skip MCP and start Phpactor
directly. Unlike the bundled MCP launch, this requires the editor's Phpactor
environment to register the BEAR extension (see
[`bear-phpactor-extension`](https://github.com/suzumaze/bear-phpactor-extension)):

```text
command: /absolute/path/to/phpactor
args:
  - language-server
  - --working-dir=/absolute/path/to/bear-project
```

Standard clients can use Definition, Type Definition, References, Hover, Completion,
Document Link, Document Symbols, and Workspace Symbols. A client able to send custom
requests can also call the read-only `bear/*` Semantic API directly. MCP is only the adapter
that presents selected custom requests as named AI tools.

## Try it from an AI client

After connecting, ask the client for facts rather than naming tools explicitly:

For task-oriented walkthroughs, result interpretation, and a comparison with text search,
see [Use cases](docs/use-cases.md) ([日本語](docs/use-cases.ja.md)).

```text
List the Resources in this BEAR.Sunday project.
Audit this project for statically provable inconsistencies and report any skipped checks.
Describe app://self/user, including methods, Link/Embed relations, templates, and schemas.
Show the request schema for app://self/user.
Resolve the /thing/detail route to its Page Resource.
Find the SQL file for query ID point_distance.
Find the Qiq template for app://self/user.
Describe the ALPS descriptor goArticle and its relationships.
Find all static references to app://self/user.
Find Link and Embed relations targeting app://self/user.
Show the supported attributes on app://self/user, mark dynamic arguments explicitly, and
do not infer omitted constructor defaults.
Audit cache, Link, Embed, Schema, and ALPS attributes across App Resources.
Compare onPost request-name presence for app://self/user across Resource, Schema, and ALPS.
At app://self/user in src/Resource/App/Dashboard.php, show its definition, references, and hover.
Complete the Resource URI at zero-based line 11, character 28 in src/Client.php.
List the symbols in src/Resource/App/Dashboard.php.
Find indexed workspace class, function, or constant symbols matching Dashboard.
Find the type definition of the User Resource class.
List resolved Resource URI and template links in src/Resource/App/Dashboard.php.
```

## Optional agent skill

MCP exposes the tools, while the bundled [`bear-semantic` skill](skills/bear-semantic/SKILL.md)
teaches an agent when to prefer them over text search, how to interpret bounded results, and how
to verify an authorized edit. Install the `skills/bear-semantic` directory using the normal skill
mechanism of your AI client. It is optional and does not become a Composer dependency of the
BEAR.Sunday application.

The skill checks `bear_project_info` first and falls back to standard LSP navigation, `rg`, and
source inspection when this MCP server is unavailable. Tool schemas and safety rules remain in
the server, so the MCP interface is usable without the skill.

## Tools

The complete Japanese reference is available in [MCPツール一覧](docs/tools.ja.md).

| MCP tool | BEAR Semantic API v1 request | Purpose |
|---|---|---|
| `bear_project_info` | `bear/project/info` | API version, capabilities, package versions, PSR-4 roots, and Resource count |
| `bear_project_diagnostics` | `bear/project/diagnostics` | Paginated project-wide static inconsistencies, scan coverage, and skipped checks |
| `bear_contract_coverage` | `bear/project/contractCoverage` | Paginated Resource-method contract adoption, gap selection, complete summary, and scan bounds |
| `bear_resource_list` | `bear/resource/list` | Deterministic paginated Resource URI inventory with scheme and prefix filters |
| `bear_resource_describe` | `bear/resource/describe` | Resource methods, Link/Embed relations, templates, and schemas |
| `bear_resource_attributes` | `bear/resource/attributes` | Allowlisted class/method attributes with explicit source arguments and dynamic markers; constructor defaults are not expanded |
| `bear_resource_attribute_index` | `bear/resource/attributeIndex` | Paginated workspace attribute facts with per-Resource status and explicit-only argument policy |
| `bear_contract_compare` | `bear/contract/compare` | Exact name presence across Resource request parameters, JSON Schema, and ALPS; no type/meaning claim |
| `bear_schema_lookup` | `bear/schema/describeForResource` | Bounded request/response Schema facts without raw JSON |
| `bear_route_lookup` | `bear/route/resolve` | Explicit Aura Router route name to Page Resource |
| `bear_sql_lookup` | `bear/sql/resolve` | Static SQL query ID to workspace-relative SQL file |
| `bear_template_lookup` | `bear/template/resolve` | Explicit Twig or Qiq template name to file |
| `bear_template_for_resource` | `bear/template/forResource` | Convention-based Twig or Qiq template; missing templates have no success `data` and retain resolved Resource and searched paths in `partial` |
| `bear_alps_descriptor_lookup` | `bear/alps/describeDescriptor` | ALPS descriptor facts and explicit local relationships |
| `bear_resource_references` | `bear/resource/references` | Static Resource URI and Route references with bounded source ranges |
| `bear_resource_incoming_relations` | `bear/resource/incomingRelations` | Link/Embed relations targeting a Resource URI |
| `lsp_definition` | `textDocument/definition` | Definition locations at a saved workspace position |
| `lsp_type_definition` | `textDocument/typeDefinition` | Type-definition locations, including Resource convention JSON Schemas |
| `lsp_references` | `textDocument/references` | Reference locations at a saved workspace position |
| `lsp_hover` | `textDocument/hover` | Hover content at a saved workspace position |
| `lsp_completion` | `textDocument/completion` | Bounded completion items at a saved workspace position |
| `lsp_document_links` | `textDocument/documentLink` | Resolved Resource URI and template links in a saved document |
| `lsp_document_symbols` | `textDocument/documentSymbol` | Flattened symbol inventory for a saved workspace file |
| `lsp_workspace_symbols` | `workspace/symbol` | Phpactor-indexed class/function/constant search; methods excluded and index freshness unknown |

Every result keeps the core envelope unchanged:

```json
{
  "status": "ok",
  "data": {},
  "candidates": [],
  "provenance": []
}
```

Failures are tool results with stable semantic statuses rather than PHP exception traces.
An unsupported Semantic API major version returns `unsupported`. Missing Phpactor or a
missing custom LSP method returns `engine_unavailable`.

`data` is present and non-null only when `status` is `ok`; Phpactor's stdio serializer
omits null object members. A failed semantic query may include an optional `partial`
object containing narrower facts established before the failure. For example,
`bear_template_for_resource` returns `not_found` without success `data` and preserves the
resolved Resource and searched convention paths in `partial` when only the template is
missing. Clients must continue to use `status`, not the presence of `partial`, for success
or failure.

`lsp_workspace_symbols.data.coverage` records the exact boundary: Phpactor class,
function, and constant index records are included; methods are not. Its provenance uses
`freshness: "unknown"` because a saved workspace file does not prove that Phpactor has
indexed a newly created file. Treat `not_found` as inconclusive and use
`lsp_document_symbols` for a known file or source search for unsupported member discovery.

## Safety model

- All MCP tools declare `readOnlyHint: true`, `destructiveHint: false`, and `openWorldHint: false`.
- No MCP tool accepts a command, executable path, workspace root, URL, or arbitrary LSP method.
- The workspace root and Phpactor command are fixed before the MCP server starts.
- The adapter never runs the BEAR application, renders templates, edits files, or accesses the network.
- Semantic responses come only from saved workspace files through the core's canonical path and symlink checks.
- Position tools reject traversal and outside-workspace symlinks, read at most 1 MiB per saved document,
  and temporarily open that exact snapshot through standard LSP.
- Standard LSP results expose workspace-relative paths only, with at most 200 result items,
  64 KiB of Hover text, and 8 KiB per Completion or Symbol text field.
- MCP and LSP frames, paths, result counts, timeouts, and retained child-process stderr are bounded.
- Malformed tool input is rejected by JSON Schema without terminating the server.

## JetBrains prior art

The read-only BEAR semantic tools in
[`bearsunday/idea-php-bearsunday-plugin`](https://github.com/bearsunday/idea-php-bearsunday-plugin)
are important prior art. They use JetBrains indexes and the IDE MCP server. This package is
different in one deliberate way: it is a headless stdio bridge to Phpactor, so IDE navigation
and MCP queries share the same transport-independent BEAR query layer.

No JetBrains source code is copied into this repository.

## Development

```console
composer check
```

The normal suite uses a fake LSP server and exercises MCP `initialize`, `tools/list`, and
`tools/call` over real stdio processes. To additionally run against a real Phpactor binary:

```console
BEAR_MCP_TEST_PHPACTOR=/absolute/path/to/phpactor \
  vendor/bin/phpunit --filter 'Real(Phpactor|McpEndToEnd)Test'
```

The real tests exercise every published MCP tool through a real Phpactor process, verify
Semantic API version 1, and confirm that an outside-workspace context path is rejected
without exposing the outside path.

## Deferred scope

The original standard-LSP discovery scope is now covered. Additional methods will be added
only when they expose concrete BEAR or Phpactor value through a bounded, method-specific
schema; the adapter will not expose an arbitrary LSP passthrough.

Design notes for integrating agent workflows without moving subjective policy into the
semantic core are documented in [BEAR.Skills integration (Japanese)](docs/bear-skills-integration.ja.md).
The proposed read-only boundary for optional runtime cache evidence is documented in
[BEAR.QueryRepository semantic log integration (Japanese)](docs/query-repository-semantic-log.ja.md).

## License

MIT
