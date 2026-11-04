/* Directorist MCP: init.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  const config = window.directoristMcpSettings;
  if (!config) return;
  const install = (registry) =>
    mcp.install(registry, mcp.createConfigurationField(config));
  if (window.DirectoristSettingsPanel) install(window.DirectoristSettingsPanel);
  else
    document.addEventListener(
      "directorist:settings-panel:registry-ready",
      (event) => install(event.detail.registry),
      { once: true },
    );
})((window.DirectoristMcp = window.DirectoristMcp || {}));
