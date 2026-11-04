/* Directorist MCP: utilities/modal-accessibility.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.createModalAccessibility = function () {
    let background = null;
    let overflow = "";
    let overlay = null;
    return {
      open(element, closeButton) {
        overlay = element;
        document.body.appendChild(overlay);
        background = Array.from(document.body.children)
          .filter(
            (node) =>
              node !== overlay &&
              !["SCRIPT", "STYLE", "LINK"].includes(node.tagName),
          )
          .map((node) => ({ node, inert: node.hasAttribute("inert") }));
        background.forEach((item) => item.node.setAttribute("inert", ""));
        overflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
        closeButton.focus();
      },
      close() {
        if (background) {
          background.forEach((item) => {
            if (!item.inert) item.node.removeAttribute("inert");
          });
          document.body.style.overflow = overflow;
          background = null;
        }
        if (overlay && overlay.parentNode === document.body) overlay.remove();
        overlay = null;
      },
      keydown(event, dialog, close) {
        if (event.key === "Escape") {
          event.preventDefault();
          event.stopPropagation();
          close();
          return;
        }
        if (event.key !== "Tab") return;
        const nodes = Array.from(
          dialog.querySelectorAll(
            'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]',
          ),
        ).filter((node) => node.tabIndex >= 0 && node.getClientRects().length);
        if (!nodes.length) {
          event.preventDefault();
          return;
        }
        const first = nodes[0],
          last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      },
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
