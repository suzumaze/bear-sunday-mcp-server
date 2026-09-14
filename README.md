# BEAR.Sunday MCP Server

Read-only MCP adapter for the BEAR.Sunday Semantic API exposed by
[`suzumaze/bear-phpactor-extension`](https://github.com/suzumaze/bear-phpactor-extension)
through the Phpactor Language Server.

```text
MCP client
    ↓ stdio MCP
bear-sunday-mcp-server
    ↓ stdio LSP (bear/* requests)
Phpactor + bear-phpactor-extension
    ↓
saved files in one BEAR.Sunday workspace
```

The adapter contains no Resource URI, Router, SQL, template, ALPS, or JSON Schema
resolution rules. Phpactor remains the single semantic implementation used by both IDEs
and AI clients.

## Status

The initial M1 surface is implemented against BEAR Semantic API version 1. It is intended
for review and integration testing before the first tagged release.

## Requirements

- PHP 8.2 or newer
- Phpactor with `suzumaze/bear-phpactor-extension` 0.1.5 or newer in the same Composer environment
- An MCP host that supports stdio servers

The MCP SDK is fixed to the compatible `0.8.x` line because its public API is not yet 1.0.

## Install and run

```console
composer install
vendor/bin/bear-sunday-mcp \
  --workspace=/absolute/path/to/bear-project \
  --phpactor=/absolute/path/to/phpactor
```

`--workspace` is required and canonicalized once at startup. `--phpactor` may be omitted.
The adapter then checks `PHPACTOR_BIN`, `WORKSPACE/vendor/bin/phpactor`, and finally the
`phpactor` command on `PATH`. A configured command is passed directly to `proc_open` as an
argument array; shell command strings and appended arguments are rejected.

For MCP hosts that use an `mcpServers` JSON object:

```json
{
  "mcpServers": {
    "bear-sunday": {
      "command": "/absolute/path/to/vendor/bin/bear-sunday-mcp",
      "args": [
        "--workspace=/absolute/path/to/bear-project",
        "--phpactor=/absolute/path/to/phpactor"
      ]
    }
  }
}
```

Use the Phpactor binary configured by
[`phpactor-setup-for-bear-sunday`](https://github.com/suzumaze/phpactor-setup-for-bear-sunday)
or another Phpactor installation that actually loads the BEAR extension.

## Tools

| MCP tool | BEAR Semantic API v1 request | Purpose |
|---|---|---|
| `bear_project_info` | `bear/project/info` | API version, capabilities, package versions, PSR-4 roots, and Resource count |
| `bear_resource_list` | `bear/resource/list` | Deterministic Resource URI inventory with scheme, prefix, and limit filters |
| `bear_resource_describe` | `bear/resource/describe` | Resource methods, Link/Embed relations, templates, and schemas |
| `bear_schema_lookup` | `bear/schema/describeForResource` | Bounded request/response Schema facts without raw JSON |

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

- All four MCP tools declare `readOnlyHint: true`, `destructiveHint: false`, and `openWorldHint: false`.
- No MCP tool accepts a command, executable path, workspace root, URL, or arbitrary LSP method.
- The workspace root and Phpactor command are fixed before the MCP server starts.
- The adapter never runs the BEAR application, renders templates, edits files, or accesses the network.
- Semantic responses come only from saved workspace files through the core's canonical path and symlink checks.
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
  vendor/bin/phpunit --filter RealPhpactorTest
```

The real test verifies Semantic API version 1 and confirms that an outside-workspace context
path is rejected without exposing the outside path.

## Deferred scope

Route, SQL, template, ALPS, references, and position-based navigation tools are intentionally
deferred to small follow-up changes. The core already exposes the relevant LSP requests; each
future MCP tool should remain a name/schema mapping rather than duplicate semantic logic.

## License

MIT
