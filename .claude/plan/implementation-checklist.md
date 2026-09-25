# Implementation Checklist — Product Publish Guard V1

A flat, ordered task list derived from `coding-plan.md` §15. Tick items in order. Section references (§) point at `coding-plan.md`, which remains the authority for detail — this file is only a progress tracker.

**Rule:** do not start a phase until the previous phase's *Gate* line passes.

---

## Phase 0 — Verification (before any code)

- [x] Work through every item in §17 and write the answers to `.claude/plan/verification-notes.md`
- [x] Amend `coding-plan.md` for anything §17 proved wrong (6 amendments applied)
- [ ] Re-check §17 items 14–15 interactively during Phase 7 (product-panel events, TinyMCE) — not answerable from source
- [x] Confirm the five decisions with the user: §17 items 21–23 (severities, enforce scope, price `0`) and the two new ones, 24 (declared PHP/WC floor) and 25 (`git init` + whether `build/` is committed) — *all five settled 2026-09-24, every recommendation accepted; recorded in `verification-notes.md`*
- [x] **Gate:** `verification-notes.md` exists and has no unanswered items *(items 1–20 verified; decisions A–E settled; only 14–15 deferred to Phase 7 by design)*

## Phase 1 — Project foundation

- [x] `product-publish-guard.php` — header (incl. `Requires Plugins: woocommerce`), constants, `sit_wcpg_bootstrap()`
- [x] `src/Autoloader.php`
- [x] `src/Plugin.php` (skeleton: `instance()`, `boot()`, lazy getters)
- [x] `src/Compat/Requirements.php` (PHP/WP/WC checks, admin notices, block-editor detection)
- [x] `src/Compat/Woo_Compat.php` (HPOS compatible; the block-editor declaration was dropped per amendment 1 — that feature id no longer exists)
- [x] `init` text-domain loading
- [x] `uninstall.php`
- [x] `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`
- [x] `package.json`, `webpack.config.js`, `.wp-env.json` (+ `.wp-env.floor.json` for the declared floor)
- [x] `.distignore`, `LICENSE`, `README.md`, `readme.txt` stub
- [x] `git init` + `.gitignore` (decision E; `build/` deliberately tracked). No commit made yet.
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 6 PHP files, WordPress ruleset with 301 active sniffs, security + DB sniffs at error severity.
- [x] **Gate — WooCommerce off → notice, no fatal:** verified for all three failure codes (`wc_missing`, `wc_version`, `wp`) on PHP 8.0.30 / 8.1.10 / 8.3.33. Plugin does not boot, escaped notice renders, no diagnostics raised.
- [ ] **Gate — activates with `WP_DEBUG=true` and zero notices:** bootstrap verified against stubbed WordPress on all three PHP versions with zero diagnostics; the live `activate_plugin()` run on the Laragon site is **still outstanding — MySQL is not running**. Harness ready at `scratchpad/gate-a.php`.

## Phase 2 — Engine primitives

- [x] `Engine/Status.php`, `Engine/Severity.php`
- [x] `Engine/Rule_Interface.php`, `Engine/Abstract_Rule.php`
- [x] `Engine/Rule_Result.php`, `Engine/Validation_Result.php` — the severity matrix lives in `Rule_Result::with_severity()`, so the outcome a rule reported (`get_outcome()`) stays readable after demotion
- [x] `Engine/Rule_Registry.php`
- [x] `Engine/Product_Context.php` (`from_product`, `from_product_with_overrides`, `from_array`) — accepts the editor's `content`/`excerpt` field names as aliases for `description`/`short_description`
- [x] `Engine/Validator.php` (§5.5 severity matrix + per-rule try/catch + `sit_wcpg_validation_result` filter)
- [x] Test harness: `tests/bootstrap.php` (WP test suite when `WP_TESTS_DIR`/`WP_PHPUNIT__DIR` is set, doubles otherwise) + `tests/stubs/` (`wordpress-functions.php`, `class-wc-product.php`, `class-fake-rule.php`, `class-settings.php`)
- [x] Unit tests: `Rule_Result_Test` (10), `Validation_Result_Test` (8), `Validator_Test` (8), `Rule_Registry_Test` (8), `Product_Context_Test` (21) — 55 cases
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 25 PHP files (`src/` + `tests/`).
- [x] **Gate — `to_array()` shapes match §5.4 exactly:** both shapes asserted key-for-key and in order by `Rule_Result_Test::test_to_array_matches_the_documented_shape` and `Validation_Result_Test::test_to_array_matches_the_documented_shape`.
- [ ] **Gate — all §12.1 engine tests pass:** **not run.** `php -l` is clean on all nine engine files and all test files, but the user asked for the suite not to be executed in this session. Run `composer test:unit` to close this.

## Phase 3 — Settings

- [x] `Settings/Settings.php` (facade, defaults from registry, `get_hash()`) — public surface unchanged from the Phase 2 placeholder plus `get_defaults()`, `refresh()` and the static `scopes()` / `threshold_limits()` / `threshold_label()` helpers; class is non-final; `tests/stubs/class-settings.php` deleted and its conditional require removed from `tests/bootstrap.php`. The registry is resolved lazily (constructor-injectable) so reading a setting never forces the rule list to be built.
- [x] `Settings/Settings_Sanitizer.php` (whitelist-built output, clamping, enum validation) — output is constructed from `get_defaults()`, never filtered from input. `sanitize()` takes an untyped parameter by design: core passes whatever was posted under the option name, and a typed parameter would turn a crafted non-array POST into a fatal on the settings screen.
- [x] `Settings/Settings_Page.php` (submenu, `register_setting`, `option_page_capability_sit_wcpg_settings`, form) — rule rows built from the registry; `render_page()` repeats the `manage_woocommerce` check; wired from `Plugin::boot()` under `is_admin()`.
- [x] Unit test `Settings_Sanitizer_Test` (14 cases incl. a 6-case data provider); integration test `Settings_Test` (9 cases). **Not run** — the user asked for no test execution this session. `php -l` clean on both.
- [x] **Gate — settings save/reload correctly; unknown keys and injected `version` dropped; thresholds clamp:** run as a direct harness at `scratchpad/gate-phase3.php` on PHP 8.3.33 with `error_reporting=E_ALL`. **77 checks, 0 failures, zero diagnostics.** Covers defaults, the hostile-payload whitelist, the version lock, clamping at both bounds + the clamp notices, non-numeric fallback, save/reload, a rule registered after the option was saved, the settings hash, menu/capability/`register_setting` wiring, form rendering, escaping of a hostile rule label, and an end-to-end round trip of the rendered form's own field names back through the sanitizer.
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 29 PHP files.
- [x] **Known gap, closed by Phase 4:** `Plugin::registry()` calls `Rules_Provider::populate()`, which now exists, so the live settings screen renders the eleven rule rows from the registry.

## Phase 4 — The 11 rules

- [x] `Rules/Rules_Provider.php` (+ `sit_wcpg_register_rules` action) — built-ins registered in priority order, then the action fires with the registry
- [x] `Title_Rule`, `Description_Rule`, `Short_Description_Rule`
- [x] `Featured_Image_Rule`, `Image_Count_Rule`
- [x] `Price_Rule`, `Sale_Price_Rule`
- [x] `Category_Rule`, `Tags_Rule`
- [x] `Sku_Rule`, `Stock_Status_Rule`
- [x] One unit test class per rule, covering every branch in §5.6 — 11 classes in `tests/Unit/Rules/`, plus `Rules_Provider_Test` for the catalogue contract (ids, display order, priorities, groups, shipped severities, the extension point, a third-party rule slotting in by priority). **Not run** — the user asked for no test execution this session. `php -l` clean on all 13 new test files.
- [x] Test asserting no rule message interpolates non-integer values (§9.5) — `tests/Unit/Rules/Rule_Message_Safety_Test.php`: four attack payloads planted in every text field across two threshold configurations, asserting no payload reaches a message, no message contains markup, every `data` value is int/float/bool, and that no file in `src/Rules/` contains a `%s` placeholder at all
- [x] **Gate — every §12.1 rule branch has a passing assertion:** run as a direct harness at `scratchpad/gate-phase4.php` (no PHPUnit, per the session's instruction) with `error_reporting=E_ALL` and a failing error handler. **669 checks, 0 failures, zero diagnostics on PHP 8.0.30, 8.1.10 and 8.3.33.** Covers every branch in the §12.1 rule table, the catalogue contract, `Settings::get_defaults()` deriving from the eleven rules, and 176 rule messages evaluated for §9.5 safety.
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 54 PHP files.

### Deviations from the plan, recorded

- §5.6 writes the two countable messages as `"… is %1$d characters; at least %2$d are recommended."` and `"This product has %1$d image(s); …"`. §10.2 requires `_n()` for anything countable, so both ship as `_n()` pairs with proper singular/plural forms. The placeholders and their integer-only rule are unchanged.
- §5.6 lists `grouped` as a skip for the price rule without giving a reason string; it has one of its own ("A grouped product takes its price from the products it contains.") so the checklist row explains itself.
- `Stock_Status_Rule` requires a **non-null** managed quantity before warning about a contradiction, so the `%d` placeholder is never fed a null.
- `Sale_Price_Rule` compares against the regular price only when that price is itself numeric; an unusable regular price is the price rule's finding to report, not this one's.
- `tests/stubs/wordpress-functions.php` gained a `do_action()` stub recording into `$GLOBALS['sit_wcpg_test_actions']`, which is how the provider test asserts the extension point is opened.

## Phase 5 — Checklist service + server-rendered panel

- [x] `Support/Checklist_Service.php` (static memo + object cache, override-free only) — memo keyed `{id}:{settings_hash}`; object-cache key `v1:{id}:{post_modified_gmt}:{settings_hash}:{locale}`. Also exposes `validate_product()`, `get_summary_for_post_id()` (the §14.4 seam), `flush_post()` and a static `flush_memo()` for the suites.
- [x] `Admin/Screen.php` — `is_product_edit_screen()`, `is_product_list_screen()`, `is_settings_screen()`, `current_product_id()`. Each takes the `admin_enqueue_scripts` hook suffix so the cheap check runs first; the product id is read from the global post, never from a superglobal.
- [x] `Admin/Editor_Meta_Box.php` (mount node + escaped no-JS fallback) — `side`/`high`, registered on `add_meta_boxes_product`, `edit_post` guard on both registration and render; grouped rows in the §7.1 order, skipped rows last under "Not applicable (n)", a visually hidden status label on every row and `aria-live="polite"` on the summary. Owns the shared `group_labels()` map.
- [x] `Admin/Assets.php` (§11.1 matrix, `build/editor.asset.php`, `wp_json_encode` payload, script translations) — screen decided before any setting is read, and every build artefact guarded by `file_exists()` so an unbuilt checkout still renders the static panel.
- [x] `Plugin::boot()` wires `Assets` and `Editor_Meta_Box` under `is_admin()`; both resolve `Checklist_Service` lazily, so an ordinary admin request still never builds the rule registry.
- [x] Integration tests `Checklist_Service_Test` (12 cases), `Editor_Meta_Box_Test` (10 cases). **Not run** — the user asked for no test execution this session. `php -l` clean on both.
- [x] **Gate — correct static checklist with JavaScript disabled; zero assets on unrelated screens:** run as a direct harness at `scratchpad/gate-phase5.php` against the **live** WP 7.1.2 / WC 11.1.1 install on PHP 8.3.33 with `error_reporting=E_ALL` and a failing error handler. **148 checks, 0 failures, zero diagnostics.** Covers the meta box's context/priority, an incomplete product's eleven rows against hand-computed statuses and counts, the ready branch, group order, one message per non-passing row, closed markup, the accessibility pairing, the `edit_post` guard, a hostile title/description/SKU reaching nothing, the ten-screen asset matrix, the payload's §8.1 shape and §5.4 result shape, the version and dependencies read from `build/editor.asset.php`, the unbuilt-checkout degradation, and all three memoization behaviours.
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 60 PHP files. Required one ruleset addition: `WordPress.WP.Capabilities` now declares WooCommerce's `manage_woocommerce` / `edit_products` / `edit_others_products` / `publish_products` as known custom capabilities.

### Deviations from the plan, recorded

- §7.1 says messages appear only on non-pass rows. Skipped rows carry their skip *reason*, which is a message on a non-pass row and is exactly the text that explains why the rule does not apply — so they are rendered. Passing rows show nothing, as specified.
- The object-cache key gains a **locale** segment beyond the four parts in §11.3. Rule labels and messages are translated before the result is stored, so an entry written for one administrator's language must not be served to another's.
- `Checklist_Service::validate_post()` returns `null` rather than an empty result when the id does not resolve to a product, so the meta box can render a distinct "not saved yet" state instead of an empty checklist.
- §5.6's category rule warns rather than fails on an ordinary category-less product, because WooCommerce assigns the store default term on save. Measured and recorded under *Extra findings* in `verification-notes.md`; no code change.

## Phase 6 — REST endpoint

- [x] `Rest/Validate_Controller.php` — `POST /sit-wcpg/v1/products/(?P<id>[\d]+)/validate`; a single `draft` object argument carrying its own per-field whitelist (`DRAFT_FIELDS`, 16 fields), `validate_draft()` and `sanitize_draft()`; `permissions_check()` in the §9.10 order (`absint` → 404 → `edit_post`); caps of 100 / 200 / 100 applied to the **raw** list before any per-item work; `try/catch ( \Throwable )` → 500 with a `WP_DEBUG`-only log, so a rule bug never fatals a REST request.
- [x] `Plugin::boot()` registers the controller **outside** `is_admin()` — a REST request is not an admin request — and the checklist service is still resolved lazily, so merely adding the `rest_api_init` callback builds nothing.
- [x] Integration test `Rest_Validate_Test` (13 cases): route registration, the §5.4 response shape, draft-wins / absent-keys-fall-back, no-draft, price normalization, bare sale dates, the `-1` featured-image sentinel, 404 for a non-product and for an unknown id, product unchanged, unknown keys dropped, drafted results never cached.
- [x] Security tests `tests/Security/Rest_Validate_Security_Test.php` (11 cases): logged out, subscriber, a product editor without `edit_others_products` (refused another's product, allowed its own), the REST nonce under cookie auth, oversized arrays through both the route and the sanitizer, `product_type` = `'<script>'`, an unregistered stock status, a scalar draft, hostile product text, and a refused call writing nothing. **Not run** — the user asked for no test execution this session. `php -l` clean on both files.
- [x] **Gate — all REST security tests pass; the product is provably unchanged after a call:** run as a direct harness at `scratchpad/gate-phase6.php` (no PHPUnit, per the session's instruction) against the **live** WP 7.1.2 / WC 11.1.1 install with `error_reporting=E_ALL` and a recording error handler. **119 checks, 0 failures, zero plugin diagnostics on PHP 8.3.33 and 8.1.10.** Covers every §12.2 `Rest_Validate_Test` row and every §12.3 row naming the REST route. The unchanged-product proof compares the post row, every meta row and both term sets before and after an everything-at-once draft, with the caches flushed in between, then re-reads the product through WooCommerce. The harness hard-deletes every product, user, term and the test role on shutdown; the install was verified empty afterwards.
- [x] **Gate — `phpcs` clean:** zero errors, zero warnings across all 63 PHP files. Required one ruleset addition: `PrefixAllGlobals.NonPrefixedVariableFound` and `.NonPrefixedHooknameFound` are excluded under `/tests/*`, because standing a REST server up means assigning `$wp_rest_server`, firing `rest_api_init` and setting `$wp_rest_auth_cookie` — all three are WordPress's own.

### Deviations from the plan, and findings recorded

- §9.3 asks for "a per-field `args` schema: `type`, `sanitize_callback`, `validate_callback`", but §8.2 fixes the body as `data: { draft }` and **core does not run per-property callbacks inside a nested object**. The per-field whitelist therefore lives in `DRAFT_FIELDS` and is enforced by the `draft` argument's own two callbacks; the per-field JSON types and `maxItems` are still published in `properties` for discoverability. Same guarantees, one level down.
- `sale_from` / `sale_to` are not in §9.3's list. They are parsed strictly against `Y-m-d H:i:s` and `Y-m-d` (never `strtotime()`, which reads almost anything as a date) and normalized to `Y-m-d H:i:s`, because `Sale_Price_Rule` compares them as strings. An unparseable value becomes `''` — what a cleared datepicker sends — rather than a 400, so a half-typed date never breaks the live panel. The same reasoning applies to prices: `wc_format_decimal()` normalizes, and the numeric `validate_callback` is a belt-and-braces check that in practice never fires.
- Unknown draft keys are **dropped, not rejected**, so a newer client sending a field this version does not know about still gets a correct answer for the fields it does. Verified that the internal context key `has_valid_featured_image` cannot be set from a request.
- **Finding (core ordering, no code change):** `WP_REST_Server::respond_to_request()` validates and sanitizes arguments **before** calling the `permission_callback`, so a malformed body returns 400 to an anonymous caller rather than 401. §12.3 requires the enum rejection to come from `validate_callback`, so this ordering is inherent. The only thing it discloses is which product types and stock statuses a store has registered, and no result body is ever returned. Asserted explicitly in the gate so it stays a recorded decision.
- Identifier lists use `absint()` per §9.3's `wp_parse_id_list` reference, so a negative id becomes positive rather than being dropped. Nested arrays, zeroes and duplicates are dropped outright.

## Cross-cutting — Prefix migration to `sit_wcpg` / `sit-wcpg` (2026-09-25)

Executed per `prefix-migration-plan.md`, on branch `chore/prefix-sit-wcpg`. No backward compatibility (the plugin was never released).

- [x] Plan docs first: `coding-plan.md` §2 row + new §10.6 "Naming contract"; every mention renamed in `coding-plan.md`, this file and `general-plan.md` §14
- [x] Guards: PHPCS `PrefixAllGlobals` → `sit_wcpg` / `SIT_WCPG`; `bin/check-prefix.php` as `composer lint:prefix`, chained into `composer lint`; `.stylelintrc.json` BEM + prefix pattern; `bin` and `.stylelintrc.json` in `.distignore`
- [x] Production code, `uninstall.php` and tests renamed; `Admin\Screen::SETTINGS_HOOK` now derived from `Settings_Page::MENU_SLUG`, and the payload's `restPath` from `Validate_Controller::REST_NAMESPACE`
- [x] New `tests/Unit/Naming_Contract_Test.php` (12 cases)
- [x] `CLAUDE.md` at the plugin root carries the contract for future sessions
- [x] **Gate — `composer lint` clean (PHPCS + prefix guard); unit suite green (149 → 161).** Integration/security suites and the manual `wp-env` checks (settings URL `page=sit-wcpg-settings`, `sit-wcpg/v1` route, old route 404, uninstall) still to run where the WP test suite is available.

## Phase 7 — React checklist UI

- [ ] `assets/js/editor/index.js`, `api.js`, `snapshot.js`, `field-watcher.js`
- [ ] `hooks/useValidation.js`
- [ ] `components/`: `ChecklistApp`, `ChecklistSummary`, `ChecklistGroup`, `ChecklistItem`, `StatusIcon`, `ChecklistFooter`
- [ ] `utils/focusField.js`
- [ ] `assets/scss/editor.scss`
- [ ] Request-avoidance: debounce 800 ms, signature dedupe, AbortController, 1500 ms floor, pause on hidden, stop on submit
- [ ] Error states: abort (silent), 403 session expired, 5xx retry; last good result always retained
- [ ] Jest tests for `snapshot.js` and `useValidation`
- [ ] **Gate:** manual rows 7–9, 12, 14, 26, 27; no request for a no-op keystroke; never >1 in-flight request

## Phase 8 — Publishing enforcement

- [ ] `Engine/Save_Request_Reader.php` (classic / quick edit / bulk edit, unslashing, `-1` sentinels, `detect_source()`)
- [ ] `Publishing/Publish_Guard.php` Layer A (`wp_insert_post_data`) + skip matrix
- [ ] Layer B (`woocommerce_before_product_object_save`) + re-entrancy guard
- [ ] Layer C (`future_to_publish` backstop) + `future` treated as publish intent in Layer A
- [ ] Enforcement scope + `sit_wcpg_should_enforce` filter + `can_override()` + `sit_wcpg_can_override_publish_guard`
- [ ] Fail-open on internal exceptions (with logging)
- [ ] `Admin/Notices.php` (transient queue, `admin_notices`, `post_updated_messages`, `redirect_post_location`)
- [ ] Client-side advisory notice (never disables the Publish button)
- [ ] Integration tests: classic, quick edit, bulk, CRUD, scheduled, disabled, override, fail-open
- [ ] **Gate:** every §9.8 bypass path closed by a test; manual rows 2, 17–21

## Phase 9 — Products list integration

- [ ] `Admin/Product_List_Column.php` — column registration, `the_posts` priming, cell rendering
- [ ] `assets/scss/admin.scss`
- [ ] Integration test `Product_List_Column_Test` with a query-count assertion
- [ ] **Gate:** manual row 22 — query count within ~5 of the deactivated baseline

## Phase 10 — Security hardening pass

- [ ] Audit every `echo`/`printf` for context-correct escaping
- [ ] Audit every superglobal read for `wp_unslash` + sanitization
- [ ] Audit every route/handler for `permission_callback` and capability
- [ ] Confirm: no `$wpdb`, no `admin-ajax`, no `dangerouslySetInnerHTML`, no outbound HTTP, no role changes
- [ ] Run the full §12.3 suite
- [ ] PHPCS with security sniffs at error severity
- [ ] Write `.claude/plan/security-audit.md` mapping each §9 control to file:line
- [ ] **Gate:** §12.3 green; PHPCS zero errors and zero warnings

## Phase 11 — Test completion & performance verification

- [ ] Close any gaps in §12.1 and §12.2
- [ ] Run the full §12.4 manual matrix at minimum versions
- [ ] Run the full §12.4 manual matrix on current WP/WC
- [ ] Profile the editor screen and a 50-product list
- [ ] Write `.claude/plan/test-report.md`
- [ ] **Gate:** 100 % of the manual matrix passes; both suites green on both version targets

## Phase 12 — Documentation, i18n and packaging

- [ ] Finalize `readme.txt` (incl. all version headers and changelog)
- [ ] Finalize `README.md` (hook reference §14, enforcement model §6.3, documented limitations)
- [ ] Generate `languages/product-publish-guard.pot`; verify every string is translatable with translator comments
- [ ] `npm run build` from a clean checkout
- [ ] Verify `.distignore` excludes `assets/`, `tests/`, `node_modules/`, `vendor/`, dotfiles, `.claude/`
- [ ] Build the zip; install on a clean site; run manual rows 1, 2, 22, 24
- [ ] **Gate:** every item in §16 (Definition of Done) is checked off
