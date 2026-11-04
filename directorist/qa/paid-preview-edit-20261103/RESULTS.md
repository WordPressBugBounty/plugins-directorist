# Paid Preview / Edit / Continue URL integrity

## Owner and scope

Directorist Core, based on authoritative upstream `trunk` commit
`51376a6598461f7b763eb7110a0b11b984212b65` (8.10.7). This branch is independent
of the consent/Popular fixes and does not contain those commits. No Gutenberg,
Elementor, Pricing Plans, server configuration or shared-site data was changed.

Three production files change: `includes/model/SingleListing.php`, the existing
Add Listing JS source and its targeted production bundle. No builder workaround,
checkout-type fallback, payment rule, entitlement or nonce contract is introduced.

## Root cause and fix

1. `SingleListing::edit_link()` added an unencoded nested checkout URL. WordPress
   rebuilt the outer query with the nested ampersands at the wrong level. It now
   encodes the already `wp_validate_redirect()`-validated URL as one value.
2. The Add Listing query parser already decodes that value. Its edited-preview
   callback then appended it raw, splitting it a second time. The callback now
   encodes that decoded value once at the next outer-query boundary.
3. The existing normalizer decoded an already absolute/relative response URL,
   altering encoded query values. It now preserves such raw URLs and only decodes
   the existing fully URI-encoded response form. No repeated decoding is added.

Edit remains unsigned; Continue still uses the unchanged native Core getter and
WordPress nonce generation/verification. Empty, malformed and disallowed external
Edit destinations fall back to the native Edit permalink. The existing WordPress
allowed-redirect-host policy is not relaxed.

## Reproduction and verification

The harness uses the actual WordPress URL, redirect, hook and nonce APIs; actual
Core getters/permalinks; the actual Add Listing query parser and full success
callback; and the actual Core checkout method, request adapter, validator and
checkout template. Only storage, current-user and plan configuration boundaries
are fixtures. WordPress is not bootstrapped, no database is connected, and no
request, order, payment or publication occurs.

- Baseline: **Invalid checkout type reproduced**. Outer Edit keys become
  `redirect, listing_id, is_featured, checkout_type`; checkout receives no type.
- Fixed PHP plus original JS: **still broken**, independently confirming that
  a PHP-only fix is insufficient. Edit has only `redirect`, but the next preview
  retains only the nested `plan_id` parameter.
- Both source fixes: **385 checks pass**. Eight destination/permalink combinations
  each complete three repeated Edit round trips and render the actual paid
  checkout form with plan9/listing42/type `plan` and Pay Now.
- Encoded ampersands, plus signs, quotes, Unicode, nested return URLs, signatures,
  literal percent escapes and fragments survive. Absolute/relative and
  pretty/plain URLs are covered. No double encoding accumulates.
- Fresh paid preview supports raw and fully URI-encoded response URLs. Ordinary
  unpaid Edit and free new/edited Continue remain unchanged.
- External/scheme-relative/credential-host/javascript destinations are rejected;
  malformed redirect/payment arrays fail safely. Native valid and tampered nonce
  verification is exercised.
- Targeted production Webpack build succeeds; only `assets/build/js/global/add-listing.js`
  is regenerated. Build scripts, dependencies, lockfiles and unrelated assets are unchanged.
- PHP lint, JS syntax and `git diff --check` pass. The repository CI-equivalent
  PHPCS command with `--warning-severity=0` passes both affected PHP files.

Run against the candidate:

```sh
GBI_WP_SOURCE=/path/to/read-only-wordpress node tests/paid-preview-edit-checkout.cjs
```

The same harness can verify an unchanged checkout with
`DIRECTORIST_TEST_SOURCE_ROOT=/path/to/baseline ... --expect-broken`.
`DIRECTORIST_TEST_JS_SOURCE_ROOT` independently tests the PHP-only combination.

## Artifact and native acceptance boundary

Production bundle SHA256:
`5ab3bde5f40ddffd348bbbb606794a771d01c79e7aa1657c99b2935f6952a122`.

Native shared-site paid Edit browser reacceptance is pending an exclusive QA
writer/deployment grant. This offline proof is not relabeled as a new HTTP or
full site E2E pass. No real payment should be submitted during acceptance.
