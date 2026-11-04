/* Directorist MCP: components/client-configuration.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  const { __, sprintf } = window.wp.i18n;
  mcp.createClientConfiguration = function () {
    return {
      name: "McpClientConfiguration",
      props: {
        clients: Array,
        client: String,
        headingId: String,
        templatesLoading: Boolean,
        templatesError: String,
        credentialsReady: Boolean,
        snippet: String,
        copyMessage: String,
        copyState: String,
      },
      data() {
        return {
          text: {
            choose: __("Choose your AI app", "directorist"),
            loading: __("Loading client configuration…", "directorist"),
            retry: __("Retry", "directorist"),
            load: __("Load client configuration", "directorist"),
            client: __("MCP client", "directorist"),
            credentials: __("Enter an application password to create a ready-to-use configuration. You can still copy the template with placeholders.", "directorist"),
            copy: __("Copy configuration", "directorist"),
            copied: __("Configuration copied", "directorist"),
            copying: __("Copying configuration…", "directorist"),
          },
        };
      },
      computed: {
        selectedClient() {
          return this.clients.find((item) => item.id === this.client) || null;
        },
        connectionTitle() {
          // translators: %s: AI app name.
          return sprintf(__("Connect to %s", "directorist"), this.selectedClient?.label || "");
        },
        copyLabel() {
          return this.copyState === "success" ? this.text.copied : this.copyState === "pending" ? this.text.copying : this.text.copy;
        },
      },
      methods: {
        tabKey(event, index) {
          let next = index;
          if (event.key === "ArrowRight")
            next = (index + 1) % this.clients.length;
          else if (event.key === "ArrowLeft")
            next = (index - 1 + this.clients.length) % this.clients.length;
          else if (event.key === "Home") next = 0;
          else if (event.key === "End") next = this.clients.length - 1;
          else return;
          event.preventDefault();
          this.$emit("select-client", this.clients[next].id);
          this.$nextTick(() => this.$refs.tabs[next]?.focus());
        },
      },
      template: `
        <div class="directorist-mcp-client-configuration">
          <h3 class="directorist-mcp-step-title"><span aria-hidden="true">1</span>{{ text.choose }}</h3>
          <p v-if="templatesLoading" role="status">{{ text.loading }}</p>
          <p v-if="templatesError" role="alert" class="directorist-mcp-error">{{ templatesError }} <button type="button" @click="$emit('load-templates')">{{ text.retry }}</button></p>
          <button v-if="!clients.length && !templatesLoading" type="button" class="directorist-mcp-button" @click="$emit('load-templates')">{{ text.load }}</button>
          <div v-if="clients.length" class="directorist-mcp-tabs" role="tablist" :aria-label="text.client">
            <button v-for="(item, index) in clients" :key="item.id" ref="tabs" type="button" role="tab" :id="headingId + '-tab-' + item.id" :aria-controls="headingId + '-client'" :aria-selected="client === item.id ? 'true' : 'false'" :tabindex="client === item.id ? 0 : -1" @click="$emit('select-client', item.id)" @keydown="tabKey($event, index)">{{ item.label }}</button>
          </div>
          <slot></slot>
          <div v-if="selectedClient" class="directorist-mcp-client-output" role="tabpanel" :id="headingId + '-client'" :aria-labelledby="headingId + '-tab-' + client">
            <h3 class="directorist-mcp-step-title"><span aria-hidden="true">3</span>{{ connectionTitle }}</h3>
            <p>{{ selectedClient.instructions }}</p>
            <p v-if="!credentialsReady" class="directorist-mcp-note">{{ text.credentials }}</p>
            <div class="directorist-mcp-snippet-wrap">
              <pre class="directorist-mcp-snippet" :id="headingId + '-snippet'" tabindex="0">{{ snippet }}</pre>
              <button type="button" class="directorist-mcp-snippet-copy" :class="{ 'is-copied': copyState === 'success' }" :disabled="!snippet || copyState === 'pending'" :aria-label="copyLabel" :title="copyLabel" @click="$emit('copy-snippet')">
                <svg v-if="copyState !== 'success'" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.2 4L19 6.5"></path></svg>
              </button>
              <span class="screen-reader-text" role="status" aria-live="polite">{{ copyState === 'success' ? copyMessage : '' }}</span>
              <span v-if="copyState === 'error'" class="directorist-mcp-inline-feedback is-error" role="alert">{{ copyMessage }}</span>
            </div>
          </div>
        </div>`,
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
