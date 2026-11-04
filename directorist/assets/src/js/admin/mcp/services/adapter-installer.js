/* Directorist MCP: native WordPress plugin setup. Directly shipped source. */
(function (mcp) {
  "use strict";
  mcp.createAdapterInstaller = function (config, request) {
    const adapter = config.adapterSetup;

    async function run(action) {
      const body = new FormData();
      body.append("action", action);
      body.append("_ajax_nonce", adapter.nonce);
      body.append("slug", adapter.slug);
      body.append("plugin", adapter.plugin);
      body.append("name", adapter.name);
      const response = await request(config.ajaxUrl, { method: "POST", body });
      // Native upgrader errors use a 200 JSON error envelope as well as HTTP errors.
      if (response?.success !== true) {
        throw new Error(
          typeof response?.data?.errorMessage === "string"
            ? response.data.errorMessage
            : adapter.labels.failed,
        );
      }
    }

    return {
      async setup(state, progress) {
        if (!state?.can_setup) {
          throw new Error(state?.message || adapter.labels.failed);
        }
        if (!state.installed) {
          progress("installing");
          await run("install-plugin");
        }
        progress("activating");
        await run("activate-plugin");
      },
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
