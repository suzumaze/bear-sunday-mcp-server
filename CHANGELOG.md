# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [0.9.0] - 2026-09-22

### Added

- `bear_contract_coverage` for bounded project-wide JSON Schema and ALPS adoption facts,
  preserving absent, dynamic, unresolved, available, and non-applicable states without
  treating optional contract gaps as errors or a quality score.
- An optional, read-only MCP Apps view for `bear_contract_coverage`, with surface summaries,
  local filtering, explicit scan-truncation warnings, and a structured/text fallback for hosts
  that do not render MCP Apps.

### Changed

- Project diagnostics and contract coverage now use stable offset pagination and an approximate
  serialized-byte page budget; diagnostics preserves its published 1–200 `limit` range,
  and contract coverage can select only adoption gaps with `gapsOnly`.
- Project diagnostics exposes skipped Schema and ALPS reference checks when their roots
  are absent, and limits name-difference samples to five names per surface with full totals.
- Project diagnostics and contract coverage now analyze the complete Resource inventory;
  `bear_resource_list` and `bear_resource_attribute_index` expose stable `offset` pagination.
- The contract coverage view labels request-Schema `not_applicable` as “No request fields” and
  displays the current page range.

## [0.8.0] - 2026-09-20

### Added

- `bear_project_diagnostics` for bounded, project-wide static inconsistency checks through
  `bear/project/diagnostics`, including scan coverage, truncation, and skipped-check metadata.

### Changed

- Require `suzumaze/bear-phpactor-extension` 0.1.7 or newer for the documented installation.
- `lsp_workspace_symbols` now reports its Phpactor index coverage, excludes methods
  explicitly, marks index freshness as unknown, and states that an empty result is not
  definitive. Coverage is retained on empty and engine-failure responses.
- Resource attribute tool descriptions and Semantic API data identify arguments as
  explicit-only; omitted constructor defaults are not expanded or guessed.
- `bear_template_for_resource` documents the optional `partial` member that preserves a
  resolved Resource and searched convention paths while `not_found` has no successful
  `data`; null members remain omitted by Phpactor's stdio serializer.

## [0.7.0] - 2026-09-18

### Added

- Add a distributable `bear-semantic` agent skill that applies the semantic-first workflow and
  falls back safely when the MCP tools are unavailable.

### Documentation

- Add a complete Japanese reference for all 22 MCP tools and connect it to the README,
  Japanese use cases, and project-status documents.
- Record the published v0.1.6 core / v0.6.0 server boundary and the post-release read-only
  real-workspace verification.

## [0.6.0] - 2026-09-16

### Added

- `bear_resource_attributes` and `bear_resource_attribute_index` for allowlisted,
  bounded Resource metadata with explicit dynamic values and per-Resource status.
- `bear_contract_compare` for presence-only Resource, JSON Schema, and ALPS name
  comparison without claiming type, meaning, or behavioral compatibility.
- English and Japanese audit/contract workflows and a BEAR.Skills responsibility map.

### Changed

- Disable Phpactor language-server auto-configuration for MCP-managed processes so
  inspecting a workspace does not create or rewrite its `.phpactor.json` file.

### Documentation

- Record end-to-end verification against a temporary, read-only copy of a production-scale
  276-Resource BEAR.Sunday workspace.
- Mark QueryRepository semantic-log MCP integration as deferred until its upstream reader
  and format contracts stabilize; no runtime-log tool or dependency is shipped.

## [0.5.0] - 2026-09-15

### Added

- `lsp_type_definition` for standard Type Definition queries, including BEAR Resource
  convention JSON Schema targets.
- `lsp_document_links` for resolved Resource URI and Twig/Qiq template links in a saved
  document, with workspace-only target filtering and deterministic bounds.

## [0.4.0] - 2026-09-15

### Added

- `lsp_completion`, `lsp_document_symbols`, and `lsp_workspace_symbols` as bounded adapters
  to Phpactor's standard LSP discovery methods.
- Deterministic Completion and Symbol normalization, workspace-only Symbol filtering,
  bounded text fields, and real Phpactor end-to-end coverage.

## [0.3.1] - 2026-09-15

### Fixed

- Retry one transient Phpactor `InternalError` for read-only standard LSP tools, which can
  occur while the References index warms up on the first request.
- Classify non-method-not-found LSP RPC failures as request failures instead of process failures.

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

[Unreleased]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/suzumaze/bear-sunday-mcp-server/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/suzumaze/bear-sunday-mcp-server/releases/tag/v0.1.0
