# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [0.3.0] - 2026-09-15

### Added

- `lsp_definition`, `lsp_references`, and `lsp_hover` as bounded adapters to Phpactor's
  standard position-based LSP methods.
- Saved-document validation, workspace-only Location filtering, traversal/symlink rejection,
  deterministic ordering, and bounded Hover output for standard LSP tools.

## [0.2.0] - 2026-09-15

### Added

- `bear_route_lookup` and `bear_sql_lookup` for explicit Route names and SQL query IDs.
- `bear_template_lookup` and `bear_template_for_resource` for Twig/Qiq template resolution.
- `bear_alps_descriptor_lookup` for ALPS descriptor facts and explicit relationships.
- `bear_resource_references` and `bear_resource_incoming_relations` for bounded static
  references and Link/Embed relationships.
- Real Phpactor MCP end-to-end coverage for all eleven tools.

## [0.1.1] - 2026-09-15

### Fixed

- Correct the executable path used by `create-project`, Codex, Claude Code, and generic MCP
  client examples.
- Report the release version consistently in MCP server and Phpactor LSP client metadata.

## [0.1.0] - 2026-09-15

### Added

- A read-only stdio MCP server backed by Phpactor's BEAR Semantic API version 1.
- `bear_project_info`, `bear_resource_list`, `bear_resource_describe`, and `bear_schema_lookup`.
- Bounded LSP framing, fixed workspace/process configuration, structured failure results, and
  fake and real stdio integration coverage.

[Unreleased]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/suzumaze/bear-sunday-mcp-server/releases/tag/v0.1.0
