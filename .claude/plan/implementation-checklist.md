# Implementation Checklist — Product Publish Guard V1

A flat, ordered task list derived from `coding-plan.md` §15. Tick items in order. Section references (§) point at `coding-plan.md`, which remains the authority for detail — this file is only a progress tracker.

**Rule:** do not start a phase until the previous phase's *Gate* line passes.

---

## Phase 0 — Verification (before any code)

- [x] Work through every item in §17 and write the answers to `.claude/plan/verification-notes.md`
- [x] Amend `coding-plan.md` for anything §17 proved wrong (6 amendments applied)
- [ ] Re-check §17 items 14–15 interactively during Phase 7 (product-panel events, TinyMCE) — not answerable from source. *Phase 7 covered the DOM contract with a jsdom harness over core's real scripts and a TinyMCE stub; a real-browser check is still outstanding (no browser available in that session).*
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
- [x] **Gate — all §12.1 engine tests pass:** not run in the Phase 2 session (the user asked for no test execution; `php -l` clean). *Closed retroactively on 2026-09-25:* the full unit suite, engine tests included, ran green on both version targets in Phase 11 (185 cases, `test-report.md` §3).

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

- [x] `assets/js/editor/index.js`, `api.js`, `snapshot.js`, `field-watcher.js` — the entry does not mount when the payload has no result (the meta box's "not saved yet" state), so the server markup stays
- [x] `hooks/useValidation.js` — exported pure `reducer` + `initState`; settle actions carry a request id and stale ones are ignored
- [x] `components/`: `ChecklistApp`, `ChecklistSummary`, `ChecklistGroup`, `ChecklistItem`, `StatusIcon`, `ChecklistFooter` — same BEM classes and glyphs as the no-JS fallback, so the box does not change shape on mount
- [x] `utils/focusField.js` — opens the product-data tab (`li.{panel}_options > a`), opens a collapsed postbox, focuses TinyMCE when it hides the textarea, tolerates a malformed third-party selector
- [x] `assets/scss/editor.scss` — styles both renderings; stylelint BEM/prefix clean
- [x] Request-avoidance: debounce 800 ms, signature dedupe, AbortController, 1500 ms floor, pause on hidden, stop on submit
- [x] Error states: abort (silent), 401/403 session expired (automatic checks then stop; Re-check still works), 5xx retry; last good result always retained
- [x] Jest tests for `snapshot.js` (16 cases) and `useValidation` (15 cases: 7 reducer, 8 hook) in `tests/js/`. **Not run** — the user asked for no test execution this session. ESLint-clean.
- [x] `Naming_Contract_Test::test_editor_entry_matches_the_php_constants` — the JS entry's mount id and payload global must equal `Editor_Meta_Box::MOUNT_ID` / `Assets::PAYLOAD_GLOBAL`. Not run.
- [x] **Gate — manual rows 7–9, 12, 14, 26, 27; no request for a no-op keystroke; never >1 in-flight request:** no browser was available, so run as a harness at `scratchpad/gate7.js` + `gate7-server.php`: WordPress 7.1.2's **real** core scripts (React 18.3.1, `wp-element`, `wp-components`, `api-fetch` with its nonce/root middlewares, jQuery) and the built `build/editor.js`, loaded into jsdom in the exact order and with the inline scripts WordPress printed for `sit-wcpg-editor`, over editor markup that follows the §8.2 DOM contract. Every request was answered by the **live** validate route (WC 11.1.1, PHP 8.3.33) as a temp administrator, except where a 403 or 500 was injected. **78 checks, 0 failures; zero console errors or React warnings; zero plugin PHP diagnostics.** Covers: zero requests on load; no request for a same-value event, type-and-delete inside the debounce, a markup-only description edit, whitespace in the SKU, blur, a type-change event or TinyMCE `SetContent` chatter; nothing sent inside the debounce; the 1500 ms floor; the `X-WP-Nonce` header and a body of only the 16 draft fields; rows 7, 8 (fix link focuses `#set-post-thumbnail`; the SKU fix opens the Inventory tab and focuses `#_sku`), 9 (real term ids), 12 (message carries 16 and 50), 14 (featured box replaced wholesale, gallery item added), 26 (session message, no retry offered, automatic checks stop), 27 (Text-mode textarea and a TinyMCE instance arriving via `tinymce-editor-init`); a second snapshot aborting the first with **max concurrency 1**, the last result kept visible and dimmed meanwhile; 5xx notice → Try again → recovered; nothing sent after form submit. All fixtures hard-deleted; verified none remain.
- [x] **Gate — lint:** `npm run lint:js`, `npm run lint:css`, `wp-scripts format --check` and `composer lint` all clean (zero errors, zero warnings). `build/editor.js` is 12.7 KB minified (§15 Phase 12 cap: 40 KB).
- [ ] **Still outstanding (by nature interactive):** §17 items 14–15 against a real browser — the harness drives WooCommerce's DOM contract and a TinyMCE *stub*, not WooCommerce's own `meta-boxes-product.js` or real TinyMCE. Also the "~1 s" deliverable in a real browser: harness latency was ~1.4 s per call, dominated by spawning PHP CLI + `wp-load.php` per request.

### Deviations from the plan, and findings recorded

- **JSX runtime (plan §2 amended).** The default automatic runtime made `build/editor.asset.php` depend on `react-jsx-runtime`, which WordPress registers only from 6.6 (floor: 6.5). Bundling the runtime instead was tried and rejected: `node_modules` carries React 19, whose elements (`react.transitional.element`) React 18 cannot render — and WP 7.1.2 itself still ships **React 18.3.1**. `babel.config.js` now compiles JSX to `createElement` from `wp-element`; every JSX file imports `createElement` (and `Fragment` where used).
- **Jest runner (plan §12 amended).** `@wordpress/scripts` 36 moved `test-unit-js` to Vitest. `test:unit:js` now runs `wp-scripts test-unit-jest` with `jest@30`, `jest-environment-jsdom@30` and a project `jest.config.js`; `@testing-library/react` is used for the hook tests.
- **ESLint 9 flat config.** wp-scripts ignores `.eslintrc*`; `eslint.config.cjs` extends its default, adds `react/no-danger: error` (§9.4), the `createElement` pragma, `jquery` as a core module (runtime handle, never installed), and Jest globals for `tests/js/`.
- **`@wordpress/*` packages as devDependencies** (`api-fetch`, `components`, `date`, `dom-ready`, `element`, `i18n`) so lint and Jest can resolve them; the build still externalizes all of them to core handles.
- **`webpack.config.js`** skips an entry whose source does not exist yet, because `admin.scss` is Phase 9 work and the build would otherwise fail.
- **`Assets` enqueues `editor.css` with a `wp-components` dependency**; the classic product editor does not load that stylesheet on its own, and the panel renders `Button`, `Notice` and `Spinner`.
- **Tags are sent by presence** (plan §8.2 amended): the classic tag box holds names, not ids, so each distinct name becomes a positional placeholder id `1..n`. `Tags_Rule` only counts; the server never resolves them.
- **Signature normalizes text the way the server measures it** (plan §8.2 amended): markup-only edits cost no request. The baseline is taken at mount, so opening a product sends nothing.
- **Extra watched surfaces** (plan §8.2 amended): `.cancel_sale_schedule` clicks (WooCommerce clears the dates without an event), the tag checklist and the category checklist (inline-added category) via `MutationObserver`.
- **Re-check bypasses the 1500 ms floor** as well as the dedupe, and records its signature so the debounced path does not resend it.
- **Skipped rows are collapsed** in a native `<details>` in the React panel, per §7.1; the no-JS fallback keeps them expanded under the same heading.
- The issue count (`%d issue` / `%d issues`) is translated in both PHP (fallback) and JS (panel), because both renderings show it. Same msgid, same text domain.
- The two neutral greys in `editor.scss` (`#646970`, `#dcdcde`) are core admin palette values; WordPress exposes no CSS variable for them.

## Phase 8 — Publishing enforcement

*Ticked retroactively on 2026-09-25: the work shipped with the rest of the branch, but the boxes were never updated. The evidence below comes from the files in the tree and `test-report.md`.*

- [x] `Engine/Save_Request_Reader.php` (classic / quick edit / bulk edit, unslashing, `-1` sentinels, `detect_source()`) — unit test `Save_Request_Reader_Test`
- [x] `Publishing/Publish_Guard.php` Layer A (`wp_insert_post_data`) + skip matrix
- [x] Layer B (`woocommerce_before_product_object_save`) + re-entrancy guard
- [x] Layer C (`future_to_publish` backstop) + `future` treated as publish intent in Layer A
- [x] Enforcement scope + `sit_wcpg_should_enforce` filter + `can_override()` + `sit_wcpg_can_override_publish_guard`
- [x] Fail-open on internal exceptions (with logging)
- [x] `Admin/Notices.php` (transient queue, `admin_notices`, `post_updated_messages`, `redirect_post_location`)
- [x] Client-side advisory notice (never disables the Publish button) — `assets/js/editor/publish-guard.js`
- [x] Integration tests: classic, quick edit, bulk, CRUD, scheduled, disabled, override, fail-open — `tests/Integration/Publish_Guard_*_Test.php` (9 classes incl. `Scope`, 44 cases) + `tests/Security/Publishing_Security_Test.php`; green on both version targets (Phase 11, `test-report.md` §3)
- [x] **Gate:** every §9.8 bypass path closed by a test (`test-report.md` §9); manual rows 2, 17–21 Pass (`test-report.md` §5). Row 2 passes with 2 required failures, not the 3 the plan expects — see SIT-WCPG-PKG-1 under Phase 12 (still open).

## Phase 9 — Products list integration

- [x] `Admin/Product_List_Column.php` — column registration, `the_posts` priming, cell rendering
- [x] `assets/scss/admin.scss`
- [x] Integration test `Product_List_Column_Test` with a query-count assertion
- [x] **Gate:** manual row 22 — query count within ~5 of the deactivated baseline. *Closed retroactively on 2026-09-25:* +2 queries on both targets in Phase 11 (`test-report.md` §6.1, `Product_List_Column_Test::test_a_full_page_stays_within_the_query_budget`); +4 floor / +5 current on the Phase 12 zip install with a fully flushed object cache (SIT-WCPG-PKG-2) — within budget, but at the limit on current.

### Deviations from the plan, recorded

- §11.2 steps 3 and 4 share one `_prime_post_caches()` call over the merged featured + gallery id list (one query pair, not two). §11.2 amended.
- §7.2 amended: the column is also hidden when the checklist is disabled (`enabled` off), matching the meta box, and a product with no evaluated checks renders `—`.
- Priming acts only on the **main** query of the products list screen (`WP_Query::is_main_query()`), so a secondary product query run on that screen by another plugin is never primed.
- The column key `sit_wcpg_readiness` is `Product_List_Column::COLUMN` and is listed in `Naming_Contract_Test`.
- The query-count test measures the same cold 50-product main query with and without the column after a warm-up pass (registry, options and locale reads are one-off), and asserts the difference is ≤ 5.

## Phase 10 — Security hardening pass

- [x] Audit every `echo`/`printf` for context-correct escaping
- [x] Audit every superglobal read for `wp_unslash` + sanitization
- [x] Audit every route/handler for `permission_callback` and capability
- [x] Confirm: no `$wpdb`, no `admin-ajax`, no `dangerouslySetInnerHTML`, no outbound HTTP, no role changes
- [x] Run the full §12.3 suite — every row has a test (`tests/Security/`, mapped in `security-audit.md` §13); green on both version targets in Phase 11 (`test-report.md` §3)
- [x] PHPCS with security sniffs at error severity
- [x] Write `.claude/plan/security-audit.md` mapping each §9 control to file:line
- [x] **Gate:** §12.3 green; PHPCS zero errors and zero warnings

### Findings fixed, recorded

- **SIT-WCPG-SEC-1** (§9.4): the list-column cell printed pre-built HTML under an inline `EscapeOutput` exclusion. Now escaped at the point of output; no EscapeOutput exclusion remains anywhere.
- **SIT-WCPG-SEC-2** (§9.5): the products-list notice printed each product's stored title. It now names products by id (`Product #12`); §6.3 amended.
- **SIT-WCPG-SEC-3** (§9.1): the list-column cell now checks `edit_products` itself, not only at column registration.
- Added `tests/Unit/Security_Invariants_Test.php`, which scans the source and fails on `$wpdb`, admin-ajax/admin-post handlers, outbound HTTP, role/capability edits, shortcode execution, EscapeOutput exclusions and raw-HTML sinks in the editor JS.
- The only superglobal read is the existence check on `$_GET['sit_wcpg_blocked']` (`Notices::filter_messages()`), and its `NonceVerification.Recommended` exclusion is justified. Everything else comes from core's `$postarr` and is unslashed in `Save_Request_Reader`.

## Phase 11 — Test completion & performance verification

- [x] Close any gaps in §12.1 and §12.2 — every listed case present; added `Assets_Test` (§11.1 matrix) and tests for matrix rows 3/4, 11/15 and 16
- [ ] Run the full §12.4 manual matrix at minimum versions — 19 rows pass by automation or smoke run; rows 7, 8, 9, 14, 16, 26, 27 still need their browser part; row 25 open
- [ ] Run the full §12.4 manual matrix on current WP/WC — same state as the floor
- [x] Profile the editor screen and a 50-product list — +2 queries on the list (budget ≤ 5); meta box adds 0 queries after the payload (`test-report.md` §6)
- [x] Write `.claude/plan/test-report.md`
- [ ] **Gate:** 100 % of the manual matrix passes; both suites green on both version targets — suites green on both targets (unit 185, integration 118, security 25); matrix not yet 100 %

### Deviations

- **No `wp-env`.** Docker is not installed, so both targets ran on a local harness: clean WP core, the matching `wp-phpunit`, stock WooCommerce and dedicated disposable MySQL databases, with floor on PHP 8.0 / WP 6.5 / WC 9.0 and current on PHP 8.3 / WP 7.1.2 / WC 11.1.1. Parity with `wp-env` is expected but unconfirmed (`test-report.md` §2).
- **Matrix rows verified by automation** where a test asserts the row's expectation. Rows whose expectation is visual or interactive stay open until a browser pass.

### Findings, recorded

- **SIT-WCPG-TEST-1:** `tests/bootstrap.php` never loaded WooCommerce, so the integration suite could not have booted the plugin. It now loads WooCommerce and installs it (tables and roles).
- **SIT-WCPG-TEST-3/4:** WooCommerce assigns the default category on save, so "no category" really means the default-only warning. The WP test library deletes that default term after each class, and tests that depend on it must create their own.
- **SIT-WCPG-TEST-5:** REST tests use `Spy_REST_Server`; core sends a header on a valid cookie nonce.
- **SIT-WCPG-TEST-8 (open, product):** the block-editor "panel unavailable" notice of row 25 / §16.17 does not exist, because `Requirements::is_product_block_editor_active()` is never called. Enforcement is unaffected (Layer B). Decide: implement it, or amend §12.4 row 25 and §16.17.

## Phase 12 — Documentation, i18n and packaging

- [x] Finalize `readme.txt` (incl. all version headers and changelog) — Tested up to 7.1, WC tested up to 11.1 (the versions the suites ran on)
- [x] Finalize `README.md` (hook reference §14, enforcement model §6.3, documented limitations)
- [x] Generate `languages/product-publish-guard.pot`; verify every string is translatable with translator comments — 155 strings, 0 placeholder strings without a `translators:` comment, no make-pot warnings
- [x] `npm run build` — `build/editor.js` 14.1 KB minified (budget 40 KB), React external. Built in the working tree, not a fresh clone
- [x] Verify `.distignore` excludes `assets/`, `tests/`, `node_modules/`, `vendor/`, dotfiles, `.claude/` — plus `bin/`, `dist/` and all tooling config; `bin/build-zip.php` also drops every dotfile at any depth
- [x] Build the zip (`npm run package` → `dist/product-publish-guard.zip`, 51 files); install on a clean site; run manual rows 1, 2, 22, 24 — installed with `wp plugin install <zip>` on clean floor and current sites; all four rows pass (row 2 with the amended expectation below)
- [ ] **Gate:** every item in §16 (Definition of Done) is checked off — **not met**: §16.11 (Phase 11 matrix still has 7 browser-only rows and row 25 open), §16.13 (browser console check), §16.17 (SIT-WCPG-TEST-8)

### Deviations

- **Zip tool.** No `wp dist-archive` package and no `zip` binary here, so the zip is built by `bin/build-zip.php` (PHP `ZipArchive`, reads `.distignore`), run by `npm run package`. Recorded in §4.
- **Clean site = local harness**, not `wp-env` (no Docker): WP 6.5 / WC 9.0.0 / PHP 8.0 and WP 7.1.2 / WC 11.1.1 / PHP 8.3, each with a fresh database (`sit_wcpg_zip_floor`, `sit_wcpg_zip_current`). Rows ran as a signed-in admin from a PHP script outside WP-CLI (WP-CLI is excluded from enforcement by design), plus an HTTP pass over `php -S` loading the editor, list, settings and dashboard screens with `WP_DEBUG_LOG` on: no log entries.

### Findings, recorded

- **SIT-WCPG-PKG-1 (open, plan):** §12.4 row 2 expects **3** required failures (image, price, category). On a real site that cannot happen: WooCommerce puts `default_product_cat` back whenever a product's categories are cleared, so the category rule reports the "only the default category" **warning** (row 10), and the product has 2 required failures. Enforcement and the notice are correct. Same root cause as SIT-WCPG-TEST-3. Decide: amend row 2's expectation, or make "only the default category" a required failure (a §5.6 change).
- **SIT-WCPG-PKG-2 (note):** row 22 measured +4 (floor) / +5 (current) queries against Phase 11's +2, because this run flushed the whole object cache, `alloptions` included, between the baseline and the column run. Still within the ≤ 5 budget, but at the limit on current.
