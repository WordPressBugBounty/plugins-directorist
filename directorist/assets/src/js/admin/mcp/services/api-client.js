/* Directorist MCP: services/api-client.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.createApiClient = function (config, onUnauthorized) {
    const requests = new Set();
    return {
      async request(url, options = {}) {
        const controller = new AbortController();
        requests.add(controller);
        try {
          const response = await fetch(url, {
            credentials: "same-origin",
            ...options,
            headers: { "X-WP-Nonce": config.nonce, ...(options.headers || {}) },
            signal: controller.signal,
          });
          const unauthorized =
            response.status === 401 || response.status === 403;
          // Close the drawer even when a proxy returns an HTML authentication error.
          if (unauthorized) onUnauthorized();
          let data;
          try {
            data = await response.json();
          } catch (error) {
            if (error.name === "AbortError") throw error;
            throw new Error(
              unauthorized
                ? "Your session is unavailable. Sign in again and retry."
                : "The server returned an invalid response. Please retry.",
            );
          }
          if (!response.ok) {
            throw new Error(
              typeof data?.message === "string"
                ? data.message
                : unauthorized
                  ? "Your session is unavailable. Refresh this page or sign in again and retry."
                  : "The request could not be completed.",
            );
          }
          return data;
        } finally {
          requests.delete(controller);
        }
      },
      abort() {
        requests.forEach((controller) => controller.abort());
        requests.clear();
      },
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
