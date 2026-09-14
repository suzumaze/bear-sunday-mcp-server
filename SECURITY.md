# Security Policy

## Scope

This server is intentionally read-only. Tool calls must not execute arbitrary commands, run
the BEAR application, render templates, access the network, or read outside the configured
workspace.

The Phpactor executable and workspace root are startup configuration, not MCP tool inputs.
Report any way for a tool call to change either value, escape the workspace, expose exception
traces or child-process stderr, or produce unbounded output as a security issue.

## Reporting

Please report vulnerabilities privately through GitHub Security Advisories for this
repository. Do not include secrets or private project contents in a public issue.
