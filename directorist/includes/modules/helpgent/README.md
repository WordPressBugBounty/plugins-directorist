# HelpGent integration

Directorist loads the enquiry integration after `plugins_loaded` only when
HelpGent is active. Directory Builder always offers the HelpGent Enquiry field
and shows the install or activate prompt when needed. Listing forms, owner
dashboard tabs, and their assets and routes require active HelpGent. An active standalone
`directorist-helpgent-integration` takes precedence until it is deactivated,
preventing duplicate hooks and replies during migration.

`Init` composes the module. `Integration` connects listing forms, attribution,
notifications, and the dashboard. `Conversations` owns owner authorization and
chat/read policies. `Database` owns persistence and schema upgrades. `Answers`
projects safe display data. `Rest_Controller` handles owner/visitor endpoints,
and `Assets` integrates with Directorist's existing asset loader.

## Compatibility

The existing `directorist_helpgent_conversations` table, `dhgi_read_*` user metadata,
response metadata, `helpgent-form` builder key, `dashboard_helpgent` tab,
`directorist-helpgent/v1` routes, and `directorist_helpgent_*` filters are retained.
The new `directorist_helpgent_db_version` tracks schema changes independently of
Directorist and asset versions. No enquiry records are copied or removed.

The minimum supported HelpGent version is 3.1.5. HelpGent 3.1.4 emits a PHP
warning when loading follow-up history. Update HelpGent before relying on
the integration. Administrators with plugin update permissions see an upgrade
notice on Directorist and Plugins screens when an older version is active.
When Pro is active it must include the per-response AI-policy hook.
Listing enquiry AI remains disabled; ordinary
forms retain their normal settings. HelpGent verification, cleanup, quotas, and
follow-up eligibility remain authoritative.

Directorist's JavaScript consumes `window.helpgent.responseAnswers` at runtime.
It does not import HelpGent source files or require a sibling checkout to build.
Presentation overrides remain scoped to `#dhgi-dashboard`. FormGent files and
behavior are independent of this module.

The Directory Builder currently hides the lead protection setting, and new
enquiries are saved without a lock. Existing enquiries keep their saved lock
and still honor the `directorist_helpgent_is_conversation_unlocked` filter.

## Build and validation

`pnpm run build:helpgent` builds only these integration assets. The normal
Directorist production build includes the same entries. Commit the generated
assets under `assets/build/` with the source changes.

Local WordPress CLI fixtures live in `dev-tools/tests/helpgent/`. Their bootstrap
excludes plugins per process without changing the site's activation options.
The lifecycle matrix covers inactive, Free, Pro-enabled, and legacy-extension
states. Synthetic submission/reply fixtures block external HTTP and mail and
remove their data in `finally`.

Run PHP fixtures inside the local WordPress container, and run dashboard DOM
tests with Node. After installing dependencies in Directorist, HelpGent, and
HelpGent Pro, run `pnpm exec jest --runInBand --config jest.helpgent.config.cjs`
from Directorist. The suite exercises the public HelpGent renderer
against the sibling HelpGent/Pro development sources; this is a cross-plugin
test dependency, not a Directorist build dependency. Browser validation is not
part of these tests.
