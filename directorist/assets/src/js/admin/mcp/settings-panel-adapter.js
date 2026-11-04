/* Directorist MCP: settings-panel-adapter.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.install = function (registry, component) {
    if (mcp.install.done) return;
    mcp.install.done = true;
    registry.registerComponent("McpConfigurationField", component);
    registry.registerAfterMount((app, builderData, context) => {
      const store = app.$store;
      const menu = builderData.layouts.other_features;
      if (!menu || !store.state.fields.mcp_enabled) return;
      const prune = (node) => {
        let removed = false;
        if (Array.isArray(node.fields) && node.fields.includes("mcp_enabled")) {
          node.fields = node.fields.filter((key) => key !== "mcp_enabled");
          removed = true;
        }
        ["sections", "submenu"].forEach((kind) => {
          Object.keys(node[kind] || {}).forEach((key) => {
            const child = node[kind][key];
            if (prune(child)) {
              removed = true;
              if (
                (Array.isArray(child.fields) && !child.fields.length) ||
                (child.sections && !Object.keys(child.sections).length) ||
                (child.submenu && !Object.keys(child.submenu).length)
              )
                context.Vue.delete(node[kind], key);
            }
          });
        });
        return removed;
      };
      Object.keys(store.state.layouts).forEach((key) => {
        const node = store.state.layouts[key];
        if (prune(node) && node.submenu && !Object.keys(node.submenu).length)
          context.Vue.delete(store.state.layouts, key);
      });
      const displayMenu = JSON.parse(JSON.stringify(menu));
      displayMenu.icon =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7m-3.5-3.5v7"/></svg>';
      context.Vue.set(store.state.layouts, "other_features", displayMenu);
      store.commit("prepareNav");
      const manager = app.$children[0];
      if (manager && manager.getSettingsSavePayload) {
        const original = manager.getSettingsSavePayload;
        manager.getSettingsSavePayload = function (onlyKeys) {
          const keys = (onlyKeys || Object.keys(this.fields)).filter(
            (key) => key !== "mcp_enabled",
          );
          return original.call(this, keys);
        };
        manager.updateCurrentPage();
      }
    });
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
