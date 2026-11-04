/* Directorist MCP: utilities/snippets.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.encodeCredentials = function (username, password) {
    if (!username.trim() || !password.trim()) return "";
    const bytes = new TextEncoder().encode(username.trim() + ":" + password.trim());
    return btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(""));
  };
  mcp.buildSnippet = function ({
    opened,
    ready,
    enabled,
    selectedClient,
    username: inputUsername,
    password: inputPassword,
  }) {
    if (!opened || !ready || !enabled || !selectedClient) return "";
    const template = selectedClient.snippet;
    if (!inputUsername.trim() || !inputPassword.trim()) return template;
    const username = inputUsername.trim();
    const password = inputPassword.trim();
    const basic = mcp.encodeCredentials(username, password);
    const replace = (text) =>
      text
        .split("<base64(username:application-password)>")
        .join(basic)
        .split("<username>")
        .join(username)
        .split("<application-password>")
        .join(password);
    try {
      const walk = (value) => {
        if (typeof value === "string") return replace(value);
        if (Array.isArray(value)) return value.map(walk);
        if (value && typeof value === "object")
          return Object.fromEntries(
            Object.entries(value).map(([key, item]) => [key, walk(item)]),
          );
        return value;
      };
      return JSON.stringify(walk(JSON.parse(template)), null, 2);
    } catch (_) {
      return replace(template);
    }
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
