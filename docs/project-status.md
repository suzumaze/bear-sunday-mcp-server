# Project status

This is the combined implementation, verification, and release status of the Phpactor extension
and MCP server as of 2026-09-21.

See the [complete tool inventory](../README.md#tools) ([Japanese](tools.ja.md)) and the
[task-oriented use cases](use-cases.md) ([Japanese](use-cases.ja.md)).

```mermaid
flowchart TB
    subgraph Core["bear-phpactor-extension"]
        C1["Semantic API v1<br/>Resource, Schema, Route, SQL, Template, ALPS<br/>complete"]
        C2["Standard LSP navigation and discovery<br/>complete"]
        C3["Allowlisted Resource attribute facts<br/>complete"]
        C4["Resource, Schema, and ALPS<br/>name-presence comparison<br/>complete"]
        C5["Project-wide static diagnostics<br/>complete"]
        C6["Project-wide contract adoption coverage<br/>complete on current branch"]
        C1 --> C2
        C1 --> C3 --> C4 --> C5 --> C6
    end

    subgraph MCP["bear-sunday-mcp-server"]
        M1["24 read-only tools<br/>complete on current branch"]
        M0["Optional MCP Apps contract coverage view<br/>complete on current branch"]
        M2["Grep comparison and task-oriented use cases<br/>complete"]
        M3["BEAR.Skills responsibility map<br/>complete"]
        M4["Real Phpactor fixture E2E<br/>24 tools<br/>complete"]
        M5["276-Resource workspace<br/>read-only connection verified"]
        M1 --> M0 --> M2 --> M3 --> M4 --> M5
    end

    C6 --> M1

    subgraph Deferred["Deferred experiment"]
        D1["QueryRepository semantic log<br/>design record only"]
        D2["Await stable reader and version contract"]
        D1 -.-> D2
    end

    M5 --> R1["core v0.1.7 / MCP v0.8.0<br/>released and locally verified"]
    R1 --> S1["bear-semantic skill bundled<br/>project diagnostics workflow included"]
```

## Release status

| Component | Version | Status |
|---|---|---|
| `suzumaze/bear-phpactor-extension` | [`v0.1.7`](https://github.com/suzumaze/bear-phpactor-extension/releases/tag/v0.1.7) | GitHub Release and Packagist published |
| `suzumaze/bear-sunday-mcp-server` | `v0.8.0` | Release with project diagnostics |
| MCP tool inventory | 24 on the current branch; 23 in `v0.8.0` | English and Japanese manuals complete |
| Contract coverage UI | optional MCP Apps view on the current branch | Read-only; structured/text fallback retained |
| `bear-semantic` agent skill | current branch extends the `v0.8.0` skill | Project diagnostics and contract-adoption workflows included |

## What improved beyond grep

Grep returns matching text. The Semantic API resolves saved-source facts: canonical Resource
identity, methods, Link/Embed relations, references, Route/SQL/template/ALPS targets,
allowlisted attributes, contract-name presence, contract-adoption coverage, and standard LSP navigation.

Comments, arbitrary configuration, dynamic expressions, and unsupported framework
extensions remain outside the semantic model and still require source inspection or search.

## Real-workspace evidence

Initial verification used a temporary copy of the customer workspace under `/private/tmp`.
After release, the installed release binaries were also started against the original workspace
in read-only mode. Neither check modified project files, and the original Git worktree remained
clean.

| Check | Result |
|---|---|
| MCP inventory | 22 tools |
| Release handshake | MCP `0.6.0`, core `v0.1.6`, no compatibility issues |
| Project info | `ok`, Semantic API v1 |
| Resource inventory | 276 Resources |
| Bounded list | 20 returned; repeated result was byte-identical |
| Describe / attributes / contract | `ok` |
| References / incoming relations | `ok` |
| Attribute index | `ok`, total 276, bounded result `truncated: true` |
| Schema lookup | `not_found` for the selected Resource, a semantic absence rather than engine failure |

The 0.8.0 release candidate was also verified against BEAR.Kata without executing the
application, Resources, or SQL:

| Check | Result |
|---|---|
| MCP inventory | 23 tools, including `bear_project_diagnostics` |
| Release handshake | MCP `0.8.0`, core `v0.1.7`, Semantic API v1 |
| Project diagnostics | `ok`, 65 items, no result truncation |
| Scan coverage | 245 PHP files, 41 Resources, no Resource scan truncation |
| Skipped checks | none |

The 100-item project-report page budget is based on saved-source measurements against BeMart
(154 Resources, 249 methods), not on an MCP protocol limit. Contract coverage was 64,480 bytes
at 100 items and 128,379 bytes at 200; project diagnostics was 46,397 bytes at 100 and 92,981
bytes at 200. The implementation therefore targets an approximately 64 KiB response page,
matching the existing bounded Hover-text payload budget, and uses stable `offset` pagination.
BeMart contract coverage returned pages of 100, 100, and 49;
diagnostics returned 100, 100, and 69. `gapsOnly` returned all 22 adoption gaps in one page,
including all four unresolved ALPS declarations.

## Current boundary

- The server is read-only and does not execute the BEAR application or arbitrary PHP.
- The optional MCP Apps view loads no external resources, requests no browser permissions, and
  only presents the existing bounded contract-coverage result.
- Workspace roots, Phpactor commands, input paths, and result counts are fixed or bounded.
- Runtime cache logs are neither read nor exposed as MCP tools.
- QueryRepository log integration will be reconsidered after its upstream contract stabilizes.
