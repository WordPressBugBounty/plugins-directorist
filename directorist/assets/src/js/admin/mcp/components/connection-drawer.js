/* Directorist MCP: components/connection-drawer.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  const { __, sprintf, _n } = window.wp.i18n;
  mcp.createConnectionDrawer = function (config) {
    return {
      name: "McpConnectionDrawer",
      components: { ClientConfiguration: mcp.createClientConfiguration() },
      props: {
        status: Object,
        enabled: Boolean,
        refreshStatus: Function,
        error: String,
      },
      data() {
        return {
          opened: true,
          clients: [],
          client: "claude-code",
          templatesLoading: false,
          username: "",
          useDifferentAccount: false,
          password: "",
          showPassword: false,
          message: "",
          copiedTarget: "",
          copyState: "",
          text: {
            title: __("Set up a connection", "directorist"),
            intro: __("Choose your AI app, add your credentials, and copy the configuration.", "directorist"),
            checking: __("Checking connection setup…", "directorist"),
            retry: __("Retry", "directorist"),
            required: __("Setup required", "directorist"),
            setup: __("Install and activate a compatible WordPress MCP Adapter, then refresh. Configuration snippets are unavailable until setup is ready.", "directorist"),
            refresh: __("Refresh setup", "directorist"),
            benefit: __("Manage listings, orders, settings, media, and more with", "directorist"),
            permissions: __("Every action follows your WordPress permissions.", "directorist"),
            learn: __("Explore everything you can do with Directorist MCP", "directorist"),
            unavailable: __("Application passwords are unavailable for this user. Resolve the WordPress site policy before connecting.", "directorist"),
            credentials: __("Add your WordPress credentials", "directorist"),
            guidance: __("Enter a WordPress application password. It’s different from your login password.", "directorist"),
            create: __("Create one", "directorist"),
            connecting: __("Connecting as", "directorist"),
            different: __("a different account", "directorist"),
            currentAccount: __("Use current account", "directorist"),
            otherAccount: __("Use a different account", "directorist"),
            username: __("WordPress username", "directorist"),
            password: __("Application password", "directorist"),
            hide: __("Hide application password", "directorist"),
            show: __("Show application password", "directorist"),
            test: __("Test your connection", "directorist"),
            verify: __("Initialize your compatible HTTP client, discover tools, then call", "directorist"),
            identity: __("Check your identity and permissions before making a change. OAuth-only clients are not supported.", "directorist"),
            advanced: __("Advanced connection details", "directorist"),
            insecure: __("Not secure", "directorist"),
            endpoint: __("Endpoint URL", "directorist"),
            copyEndpoint: __("Copy MCP endpoint URL", "directorist"),
            copiedEndpoint: __("Endpoint URL copied", "directorist"),
            headerKey: __("Header Key", "directorist"),
            headerValue: __("Header Value", "directorist"),
            copyHeaderKey: __("Copy header key", "directorist"),
            copyHeaderValue: __("Copy header value", "directorist"),
            copiedHeaderKey: __("Header key copied", "directorist"),
            copiedHeaderValue: __("Header value copied", "directorist"),
            warning: __("HTTPS warning", "directorist"),
            https: __("This site isn't using HTTPS. Use a secure connection before entering credentials.", "directorist"),
          },
          loading: false,
          templatesError: "",
          headingId: "directorist-mcp-" + this._uid,
        };
      },
      computed: {
        authorizationHeader() {
          if (!this.opened || !this.ready || !this.enabled) return "";
          return "Basic " + (mcp.encodeCredentials(this.username, this.password) || "<base64(username:application-password)>");
        },
        headerRows() {
          return [
            { target: "header-key", label: this.text.headerKey, value: "Authorization", copyLabel: this.text.copyHeaderKey, copiedLabel: this.text.copiedHeaderKey },
            { target: "header-value", label: this.text.headerValue, value: this.authorizationHeader, copyLabel: this.text.copyHeaderValue, copiedLabel: this.text.copiedHeaderValue },
          ];
        },
        toolsLabel() {
          // translators: %s: number of available MCP tools.
          return sprintf(_n("%s built-in tool", "%s built-in tools", this.status?.tools_count || 0, "directorist"), this.status?.tools_count || 0);
        },
        ready() {
          return Boolean(this.status && this.status.ready);
        },
        selectedClient() {
          return this.clients.find((item) => item.id === this.client) || null;
        },
      },
      created() {
        this._alive = true;
        this._copySequence = 0;
        this._api = mcp.createApiClient(config, () => this.close(false));
        this.username = this.status ? this.status.current_user_login || "" : "";
      },
      async mounted() {
        await this.refresh();
        if (this._alive && this.opened && this.ready)
          await this.loadTemplates();
      },
      beforeDestroy() {
        this._alive = false;
        this.opened = false;
        this.password = "";
        this.username = "";
        this.clients = [];
        this.resetCopyFeedback();
        this._api.abort();
      },
      watch: {
        ready(value) {
          if (!value) {
            this.clients = [];
            this.password = "";
            this.showPassword = false;
            this.resetCopyFeedback();
          }
        },
        username() { this.resetCopyFeedback(); },
        password() { this.resetCopyFeedback(); },
        client() { this.resetCopyFeedback(); },
        selectedClient() { this.resetCopyFeedback(); },
        "status.endpoint_url"() { this.resetCopyFeedback(); },
        "status.current_user_login"(value) {
          if (!this.useDifferentAccount) this.username = value || "";
        },
      },
      methods: {
        request(url, options) {
          return this._api.request(url, options);
        },
        async refresh() {
          this.loading = true;
          try {
            await this.refreshStatus();
          } finally {
            if (this._alive) this.loading = false;
          }
        },
        close(restoreFocus = true) {
          this.resetCopyFeedback();
          this._api.abort();
          this.showPassword = false;
          this.password = "";
          this.username = "";
          this.clients = [];
          this.opened = false;
          this.$emit("close", restoreFocus);
        },
        async loadTemplates() {
          if (!this.opened || !this.ready || !this.enabled) return;
          this.templatesLoading = true;
          this.templatesError = "";
          try {
            const response = await this.request(config.templatesUrl);
            if (this._alive && this.opened && this.ready && this.enabled)
              this.clients = response.clients || [];
          } catch (error) {
            if (this._alive && error.name !== "AbortError")
              this.templatesError = error.message;
          } finally {
            if (this._alive) this.templatesLoading = false;
          }
        },
        snippet() {
          return mcp.buildSnippet({
            opened: this.opened,
            ready: this.ready,
            enabled: this.enabled,
            selectedClient: this.selectedClient,
            username: this.username,
            password: this.password,
          });
        },
        toggleAccount() {
          this.useDifferentAccount = !this.useDifferentAccount;
          this.username = this.useDifferentAccount
            ? ""
            : this.status.current_user_login || "";
          if (this.useDifferentAccount)
            this.$nextTick(() => {
              if (this.$refs.username) this.$refs.username.focus();
            });
        },
        resetCopyFeedback() {
          ++this._copySequence;
          window.clearTimeout(this._feedbackTimer);
          this.message = "";
          this.copiedTarget = "";
          this.copyState = "";
        },
        async copy(text, target) {
          if (!text || !this._alive || !this.opened) return;
          this.resetCopyFeedback();
          const sequence = this._copySequence;
          const current = () => this._alive && this.opened && sequence === this._copySequence;
          this.copiedTarget = target;
          this.copyState = "pending";
          try {
            await mcp.copyText(text, this.$refs.dialog);
            if (!current()) return;
            this.copyState = "success";
            this.message = {
              configuration: __("Configuration copied to clipboard.", "directorist"),
              endpoint: __("Endpoint URL copied to clipboard.", "directorist"),
              "header-key": __("Header key copied to clipboard.", "directorist"),
              "header-value": __("Header value copied to clipboard.", "directorist"),
            }[target];
          } catch (_) {
            if (!current()) return;
            this.copyState = "error";
            this.message = __("Could not copy. Select the text and copy it manually.", "directorist");
          }
          this._feedbackTimer = window.setTimeout(() => {
            if (current()) this.resetCopyFeedback();
          }, 3000);
        },
      },
      template: `
        <section ref="dialog" class="directorist-mcp-inline-panel" role="region" :aria-labelledby="headingId">
          <header><div><h2 :id="headingId">{{ text.title }}</h2><p>{{ text.intro }}</p></div></header>
          <div class="directorist-mcp-panel-body">
            <p v-if="loading" role="status">{{ text.checking }}</p>
            <p v-if="error" role="alert" class="directorist-mcp-error">{{ error }} <button type="button" @click="refresh">{{ text.retry }}</button></p>
            <div v-if="!ready" class="directorist-mcp-note"><h3>{{ text.required }}</h3><p>{{ text.setup }}</p><button type="button" class="directorist-mcp-button" @click="refresh">{{ text.refresh }}</button></div>
            <template v-else-if="status">
              <p>{{ text.benefit }} <strong>{{ toolsLabel }}</strong>. {{ text.permissions }} <a href="https://directorist.com/blog/" target="_blank" rel="noopener noreferrer">{{ text.learn }}</a>.</p>
              <div v-if="!status.application_passwords_available" class="directorist-mcp-note"><p>{{ text.unavailable }}</p></div>
              <client-configuration :clients="clients" :client="client" :heading-id="headingId" :templates-loading="templatesLoading" :templates-error="templatesError" :credentials-ready="!!username.trim() && !!password.trim()" :snippet="snippet()" :copy-message="copiedTarget === 'configuration' ? message : ''" :copy-state="copiedTarget === 'configuration' ? copyState : ''" @load-templates="loadTemplates" @select-client="client = $event" @copy-snippet="copy(snippet(), 'configuration')">
                <div class="directorist-mcp-credentials-section">
                  <h3 class="directorist-mcp-step-title"><span aria-hidden="true">2</span>{{ text.credentials }}</h3>
                  <p>{{ text.guidance }} <a :href="status.profile_url" target="_blank" rel="noopener noreferrer">{{ text.create }}</a>.</p>
                  <div class="directorist-mcp-account-row">
                    <p class="directorist-mcp-account-summary"><span>{{ text.connecting }}</span><strong>{{ useDifferentAccount ? (username || text.different) : username }}</strong></p>
                    <button type="button" class="directorist-mcp-link" :aria-expanded="useDifferentAccount ? 'true' : 'false'" :aria-controls="headingId + '-credentials'" @click="toggleAccount">{{ useDifferentAccount ? text.currentAccount : text.otherAccount }}</button>
                  </div>
                  <div class="directorist-mcp-credentials" :id="headingId + '-credentials'" :class="{ 'has-alternate-account': useDifferentAccount }">
                    <label v-if="useDifferentAccount">{{ text.username }}<input ref="username" v-model="username" @keydown.enter.prevent.stop autocomplete="off" maxlength="256"></label>
                    <div class="directorist-mcp-password-field">
                      <label :for="headingId + '-password'">{{ text.password }}</label>
                      <div class="directorist-mcp-password-control">
                        <input :id="headingId + '-password'" v-model="password" @keydown.enter.prevent.stop :type="showPassword ? 'text' : 'password'" autocomplete="new-password" maxlength="512">
                        <button type="button" class="directorist-mcp-password-visibility" :aria-label="showPassword ? text.hide : text.show" :aria-pressed="showPassword ? 'true' : 'false'" :title="showPassword ? text.hide : text.show" @click="showPassword = !showPassword"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle><path v-if="showPassword" d="m4 4 16 16"></path></svg></button>
                      </div>
                    </div>
                  </div>
                </div>
              </client-configuration>
              <details class="directorist-mcp-details"><summary><span>{{ text.test }}</span></summary><p>{{ text.verify }} <code>directorist-get-context</code>. {{ text.identity }}</p></details>
              <details class="directorist-mcp-details">
                <summary><span>{{ text.advanced }}</span><span v-if="!status.https" class="directorist-mcp-security-status">{{ text.insecure }}</span></summary>
                <div class="directorist-mcp-details-body"><div class="directorist-mcp-endpoint-row">
                  <span class="directorist-mcp-endpoint-label">{{ text.endpoint }}</span>
                  <div class="directorist-mcp-endpoint-value" :title="status.endpoint_url">
                    <code tabindex="0">{{ status.endpoint_url }}</code>
                    <button type="button" class="directorist-mcp-icon-button" :class="{ 'is-copied': copiedTarget === 'endpoint' && copyState === 'success' }" :disabled="copiedTarget === 'endpoint' && copyState === 'pending'" :aria-label="copiedTarget === 'endpoint' && copyState === 'success' ? text.copiedEndpoint : text.copyEndpoint" :title="text.copyEndpoint" @click="copy(status.endpoint_url, 'endpoint')"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path></svg></button>
                  </div>
                  <span v-if="!status.https" class="directorist-mcp-https-warning" role="img" tabindex="0" :aria-label="text.warning" :aria-describedby="headingId + '-https-warning'"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2.8 19a1.4 1.4 0 0 0 1.2 2h16a1.4 1.4 0 0 0 1.2-2L12 3Z"></path><path d="M12 9v5"></path><circle cx="12" cy="17.5" r=".8"></circle></svg><span>{{ text.insecure }}</span><span role="tooltip" class="directorist-mcp-warning-tooltip" :id="headingId + '-https-warning'">{{ text.https }}</span></span>
                  <span class="directorist-mcp-inline-feedback" :class="{ 'is-error': copyState === 'error' }" role="status" aria-live="polite">{{ copiedTarget === 'endpoint' && copyState !== 'pending' ? message : '' }}</span>
                </div>
                <div v-for="header in headerRows" :key="header.target" class="directorist-mcp-endpoint-row directorist-mcp-header-row">
                  <span class="directorist-mcp-endpoint-label" :id="headingId + '-' + header.target + '-label'">{{ header.label }}</span>
                  <div class="directorist-mcp-endpoint-value">
                    <code tabindex="0" :aria-labelledby="headingId + '-' + header.target + '-label'">{{ header.value }}</code>
                    <button type="button" class="directorist-mcp-icon-button" :class="{ 'is-copied': copiedTarget === header.target && copyState === 'success' }" :disabled="!header.value || (copiedTarget === header.target && copyState === 'pending')" :aria-label="copiedTarget === header.target && copyState === 'success' ? header.copiedLabel : header.copyLabel" :title="header.copyLabel" @click="copy(header.value, header.target)"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"></path></svg></button>
                  </div>
                  <span class="directorist-mcp-inline-feedback" :class="{ 'is-error': copyState === 'error' }" role="status" aria-live="polite">{{ copiedTarget === header.target && copyState !== 'pending' ? message : '' }}</span>
                </div></div>
              </details>
            </template>
          </div>
        </section>`,
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));
