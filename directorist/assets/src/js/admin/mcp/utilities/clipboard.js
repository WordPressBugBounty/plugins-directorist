/* Directorist MCP: utilities/clipboard.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.copyText = async function (text, dialog) {
    if (navigator.clipboard && window.isSecureContext)
      await navigator.clipboard.writeText(text);
    else {
      const input = document.createElement("textarea");
      input.value = text;
      input.setAttribute("readonly", "");
      dialog.appendChild(input);
      try {
        input.select();
        if (!document.execCommand("copy")) throw new Error();
      } finally {
        input.value = "";
        input.remove();
      }
    }
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
