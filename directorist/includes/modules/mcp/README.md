# Directorist MCP module

Start at [class-init.php](class-init.php). It registers the module once and wires the settings integration, tool registry, transport and native write hooks.

Read the [architecture guide](../../../docs/mcp/architecture.md) for the file tree, request flow, extension contracts and verification commands.

- Feature schemas and callbacks live together under `tool-providers/`.
- `registration/Tool_Registry` is the common inventory for discovery and execution.
- `runtime/` applies shared validation and authorization; `safety/` controls reads, previews, commits and retries.
- `admin/` owns the existing settings integration; the connection UI ships directly from `assets/src/js/admin/mcp/` and `assets/src/css/admin/mcp/`.
- `compatibility/` forwards existing public PHP APIs. New implementation code uses the feature and infrastructure classes directly.

Do not place feature callbacks in Init, transport classes, or the common registry. Keep executable bindings separate from public descriptors: descriptor hashes participate in persisted preview and retry contracts.
