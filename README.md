# BEAR.Sunday MCP Server

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

Version 0.3.0 provides fourteen read-only tools against BEAR Semantic API version 1 and
Phpactor's standard LSP. In
addition to project, Resource, and schema facts, it resolves explicit Route names, SQL
query IDs, Twig/Qiq template names, Resource templates, and ALPS descriptors. It also finds
static Resource references and incoming Link/Embed relations. Definition, References, and
Hover can also be queried at a position in a saved workspace file.

## Requirements

- PHP 8.2 or newer
- Phpactor with `suzumaze/bear-phpactor-extension` 0.1.5 or newer installed in Phpactor's
  Composer environment
- An MCP host that supports stdio servers

The MCP SDK is fixed to the compatible `0.8.x` line because its public API is not yet 1.0.

## Install

Install the released server into a dedicated directory:

```console
composer create-project --no-dev --prefer-dist \
  suzumaze/bear-sunday-mcp-server \
  /absolute/path/to/bear-sunday-mcp-server \
  '^0.2'
```

For development from the repository instead:

```console
git clone https://github.com/suzumaze/bear-sunday-mcp-server.git \
  /absolute/path/to/bear-sunday-mcp-server
cd /absolute/path/to/bear-sunday-mcp-server
composer install
```

Use the Phpactor binary configured by
[`phpactor-setup-for-bear-sunday`](https://github.com/suzumaze/phpactor-setup-for-bear-sunday)
or another Phpactor installation that actually loads the BEAR extension. You do not need a
second Phpactor installation when the setup package already provides one.

## Run directly

```console
/absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project \
  --phpactor=/absolute/path/to/phpactor
```

`--workspace` is required and canonicalized once at startup. `--phpactor` may be omitted.
The adapter then checks `PHPACTOR_BIN`, `WORKSPACE/vendor/bin/phpactor`, and finally the
`phpactor` command on `PATH`. A configured command is passed directly to `proc_open` as an
argument array; shell command strings and appended arguments are rejected.

The process normally appears to wait silently because MCP messages use stdin and stdout.

## Configure Codex

Register one BEAR.Sunday workspace with Codex CLI:

```console
codex mcp add bear-sunday -- \
  /absolute/path/to/bear-sunday-mcp-server/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project \
  --phpactor=/absolute/path/to/phpactor
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
    "--phpactor=/absolute/path/to/phpactor",
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
  --workspace=/absolute/path/to/bear-project \
  --phpactor=/absolute/path/to/phpactor
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
        "--workspace=/absolute/path/to/bear-project",
        "--phpactor=/absolute/path/to/phpactor"
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
directly:

```text
command: /absolute/path/to/phpactor
args:
  - language-server
  - --working-dir=/absolute/path/to/bear-project
```

Standard clients can use Definition, Type Definition, References, Hover, Completion, and
Document Link. A client able to send custom requests can also call the read-only `bear/*`
Semantic API directly. MCP is only the adapter that presents selected custom requests as
named AI tools.

## Try it from an AI client

After connecting, ask the client for facts rather than naming tools explicitly:

```text
List the Resources in this BEAR.Sunday project.
Describe app://self/user, including methods, Link/Embed relations, templates, and schemas.
Show the request schema for app://self/user.
Resolve the /thing/detail route to its Page Resource.
Find the SQL file for query ID point_distance.
Find the Qiq template for app://self/user.
Describe the ALPS descriptor goArticle and its relationships.
Find all static references to app://self/user.
Find Link and Embed relations targeting app://self/user.
At app://self/user in src/Resource/App/Dashboard.php, show its definition, references, and hover.
```

## Tools

| MCP tool | BEAR Semantic API v1 request | Purpose |
|---|---|---|
| `bear_project_info` | `bear/project/info` | API version, capabilities, package versions, PSR-4 roots, and Resource count |
| `bear_resource_list` | `bear/resource/list` | Deterministic Resource URI inventory with scheme, prefix, and limit filters |
| `bear_resource_describe` | `bear/resource/describe` | Resource methods, Link/Embed relations, templates, and schemas |
| `bear_schema_lookup` | `bear/schema/describeForResource` | Bounded request/response Schema facts without raw JSON |
| `bear_route_lookup` | `bear/route/resolve` | Explicit Aura Router route name to Page Resource |
| `bear_sql_lookup` | `bear/sql/resolve` | Static SQL query ID to workspace-relative SQL file |
| `bear_template_lookup` | `bear/template/resolve` | Explicit Twig or Qiq template name to file |
| `bear_template_for_resource` | `bear/template/forResource` | Convention-based Twig or Qiq template for a Resource URI |
| `bear_alps_descriptor_lookup` | `bear/alps/describeDescriptor` | ALPS descriptor facts and explicit local relationships |
| `bear_resource_references` | `bear/resource/references` | Static Resource URI and Route references with bounded source ranges |
| `bear_resource_incoming_relations` | `bear/resource/incomingRelations` | Link/Embed relations targeting a Resource URI |
| `lsp_definition` | `textDocument/definition` | Definition locations at a saved workspace position |
| `lsp_references` | `textDocument/references` | Reference locations at a saved workspace position |
| `lsp_hover` | `textDocument/hover` | Hover content at a saved workspace position |

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

## Safety model

- All MCP tools declare `readOnlyHint: true`, `destructiveHint: false`, and `openWorldHint: false`.
- No MCP tool accepts a command, executable path, workspace root, URL, or arbitrary LSP method.
- The workspace root and Phpactor command are fixed before the MCP server starts.
- The adapter never runs the BEAR application, renders templates, edits files, or accesses the network.
- Semantic responses come only from saved workspace files through the core's canonical path and symlink checks.
- Position tools reject traversal and outside-workspace symlinks, read at most 1 MiB per saved document,
  and temporarily open that exact snapshot through standard LSP.
- Position results expose workspace-relative locations only, with at most 200 locations and 64 KiB of Hover text.
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

Other standard LSP methods such as Completion, Type Definition, Document Link, and Symbols
remain directly available to native LSP clients. They can be added to MCP only with bounded,
method-specific result schemas; the adapter will not expose an arbitrary LSP passthrough.

## License

MIT
