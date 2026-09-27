# Product Publish Guard — V1 Test Report (Phase 11)

**Date:** 2026-09-25 · **Branch:** `chore/prefix-sit-wcpg` · **Plan refs:** §11, §12, §16

## 1. Verdict

| Gate item (Phase 11) | Status |
|---|---|
| Unit + integration suites green on the **floor** target | **Met** |
| Unit + integration suites green on the **current** target | **Met** |
| §12.3 security suite green (Phase 10 carry-over) | **Met** on both targets |
| 100 % of the §12.4 manual matrix passes | **Not met.** 19 rows pass, 7 pass their automated part and still need a browser check, 1 has an open defect (row 25). See §5. |

Phase 11 therefore stays **open** until the browser pass in §7 is done and SIT-WCPG-TEST-8 is decided.

## 2. Environments

| Target | PHP | WordPress | WooCommerce | Test library |
|---|---|---|---|---|
| Floor | 8.0.30 (system CLI, `-d extension=mysqli`) | 6.5 | 9.0.0 | `wp-phpunit/wp-phpunit` 6.5 |
| Current | 8.3.33 (Laragon) | 7.1.2 | 11.1.1 | `wp-phpunit/wp-phpunit` 7.1.1 |

PHPUnit 9.6.37 with Yoast polyfills 3; Jest 30 via `wp-scripts test-unit-jest`.

**Deviation from §12 (`wp-env`).** Docker is not installed on this machine, so `wp-env` could not run. Both targets ran
on a throwaway local harness: clean WP core downloads, the matching `wp-phpunit` library, a stock
WooCommerce, and dedicated MySQL databases (`sit_wcpg_tests`, `sit_wcpg_tests_floor`). The WP test installer
drops every table in its database, so the site database was never used. The harness lived in the session
scratchpad and is not committed. The project-side bootstrap change (§3, SIT-WCPG-TEST-1) is exactly what `wp-env`
needs as well, so the suites are expected to behave the same there. That is still unconfirmed.

## 3. Suite results (final run)

| Suite | Floor | Current |
|---|---|---|
| PHP unit (`--testsuite unit`) | OK — 185 tests, 2050 assertions | OK — 185 tests, 2050 assertions |
| PHP integration (`--testsuite integration`) | OK — 118 tests, 493 assertions | OK — 118 tests, 494 assertions |
| PHP security (`--testsuite security`) | OK — 25 tests, 96 assertions | OK — 25 tests, 96 assertions |
| JS unit (Jest) | OK — 42 tests, 3 suites (target-independent) | |
| `composer lint` (PHPCS + prefix guard) | 0 errors, 0 warnings | |
| `npm run lint:js` (ESLint + Prettier) | clean | |

`phpunit.xml.dist` converts notices, warnings and errors into exceptions, and `WP_DEBUG` is on, so a green run
also means the tested paths raised no PHP notices or warnings (§16.12, for the paths covered).

This was the **first time** the integration and security suites were executed at all (Phase 10 had written them
but noted "not yet run").

## 4. Gaps closed and defects found

### Test-harness and test defects (all fixed)

| ID | Finding | Fix |
|---|---|---|
| SIT-WCPG-TEST-1 | `tests/bootstrap.php` never loaded or installed WooCommerce. `Requirements::check()` would stop the plugin from booting, and every `WC_Product_*` reference would fatal. | Load `WP_PLUGIN_DIR/woocommerce/woocommerce.php` before the plugin at `muplugins_loaded`. Run `WC_Install::install()` at `setup_theme`, then rebuild the role list so `shop_manager` exists. |
| SIT-WCPG-TEST-2 | `Naming_Contract_Test` referenced `Product_List_Column` without importing it, so the data provider errored. | Added the `use`. |
| SIT-WCPG-TEST-3 | `Checklist_Service_Test` expected a `category` **fail** for a product saved with no category. WooCommerce's data store assigns the default category on save (`WC_Product_Data_Store_CPT::update_terms()`, verified in 9.0 and 11.1), so the correct outcome is the "only the default" **warning**. The plugin is right; the expectation was wrong. | The assertion now expects the warning and checks the stored term. |
| SIT-WCPG-TEST-4 | The WP test library's `tear_down_after_class()` deletes every term, including WooCommerce's default category, but `default_product_cat` still points at it. Every test class after the first runs with a dangling default category, which made the test above order-dependent. | The test creates its own default category. Any future test that relies on the default category must do the same. |
| SIT-WCPG-TEST-5 | `test_core_rejects_a_cookie_request_without_a_valid_rest_nonce` failed with "headers already sent". On success, core's `rest_cookie_check_errors()` calls `send_header()`, and the test runner has already printed output. This would fail under `wp-env` too. | Both REST test classes use core's `Spy_REST_Server`, which records headers. The test now also asserts the refreshed `X-WP-Nonce`. |
| SIT-WCPG-TEST-6 | Tests retyped global names that exist as constants (the REST path, the settings group), against the naming contract in `CLAUDE.md`. | Built from `Validate_Controller::REST_NAMESPACE` / `ROUTE`, `Settings_Page::OPTION_GROUP` and `Settings::OPTION_NAME`. |
| SIT-WCPG-TEST-7 | A floor run against a database last used by WC 11 produced `Multiple primary key defined` from WC 9.0's `dbDelta`. | Harness only: each target gets its own database. |

### Coverage added

| Test | Covers |
|---|---|
| `tests/Integration/Assets_Test.php` (new, 13 tests) | The full §11.1 loading matrix: the editor and new-product screens get the script, the stylesheet and the `sitWcpgEditorData` payload, whose `restPath` is derived from the constant. React comes from `wp-element`. A disabled checklist loads nothing. The list and settings pages get `admin.css` only. The dashboard, posts, pages, legacy and HPOS orders, WooCommerce settings and product categories get nothing. (Row 23, §16.18) |
| `Checklist_Service_Test::test_a_variable_product_skips_only_the_price_rule` | A real `WC_Product_Variable` with priced variations, and with none: `price` is skipped and left out of `evaluated`, and the other rules still run. (Rows 3, 4) |
| `Publish_Guard_Disabled_Test::test_all_rules_disabled_blocks_nothing` | Every rule disabled: an empty product publishes through `wp_update_post` and through CRUD, no notice is queued, and the result evaluates 0 and is ready. (Row 16, server side) |
| `Publish_Guard_Classic_Test::test_the_configured_severity_decides_enforcement` | A missing SKU at the shipped `warning` severity publishes. Switching SKU to `required` refuses the same product, with `sku` as the only named failure. (Rows 11, 15) |

Every §12.1 and §12.2 case listed in the plan was checked against the suite by name. All were already present
apart from the additions above.

### Product defect (open)

| ID | Finding | Status |
|---|---|---|
| **SIT-WCPG-TEST-8** | Row 25 expects a notice explaining that the panel is unavailable under the WooCommerce product **block** editor. `Requirements::is_product_block_editor_active()` exists but **nothing calls it**, so no such notice is ever shown. The block editor is reachable on the WC 9.0 floor (a WooCommerce feature toggle). WC 11 force-disables it. **Enforcement is not affected**: the block editor saves through `/wc/v3/products`, which Layer B blocks (`Publish_Guard_Crud_Test::test_the_woocommerce_rest_api_cannot_publish_a_failing_product`, `Publishing_Security_Test`). | **Fixed 2026-09-27:** notice implemented (`src/Admin/Block_Editor_Notice.php`, plan §7.2.1). Integration test `Block_Editor_Notice_Test` written, not yet run (no WP test harness). Checked against the live site (WP 7.1.2 / WC 11.1.2): not shown under the classic editor; with the block editor forced on through `use_block_editor_for_post_type`, shown on the products list and settings page only; zero PHP diagnostics. |

## 5. §12.4 manual matrix

Legend: **Pass** = verified by an automated test or smoke run on **both** targets. **Pass\*** = the server side and
the logic are verified on both targets and the JS logic by Jest, but the visual or interactive part still needs a
browser check (§7). **Open** = defect.

| # | Case | Result | Evidence |
|---|---|---|---|
| 1 | Simple, complete | Pass | `Publish_Guard_Classic_Test::test_a_passing_product_publishes`; `Editor_Meta_Box_Test::test_the_summary_reports_readiness` |
| 2 | Simple, incomplete (image, price, category) | Pass | `Publish_Guard_Classic_Test::test_an_incomplete_product_reports_all_three_failures`, `test_a_failing_product_is_kept_as_a_draft` |
| 3 | Variable, priced variations | Pass | `Checklist_Service_Test::test_a_variable_product_skips_only_the_price_rule` (priced variations); `Editor_Meta_Box_Test::test_skipped_rules_are_collected_under_their_own_heading` |
| 4 | Variable, no variations | Pass | Same test, "no variations" data set |
| 5 | Draft product | Pass | `Product_List_Column_Test` cell states and the mixed draft/publish page in the budget test |
| 6 | Published product later broken | Pass | `Publish_Guard_Classic_Test::test_a_published_product_is_never_demoted`, `Publish_Guard_Crud_Test::test_a_live_product_is_never_demoted`, `Product_List_Column_Test::test_a_published_product_with_errors_still_shows_them` |
| 7 | Missing price only; fix flips without reload | Pass\* | Blocked: `Publish_Guard_Classic_Test`. Draft overrides: `Rest_Validate_Test::test_the_draft_overrides_the_stored_product`. Refresh loop: Jest `useValidation`, `buildSnapshot`. Browser: the live flip. |
| 8 | Missing featured image; fix link focuses media box | Pass\* | Rule: `Featured_Image_Rule_Test`. Browser: the fix link's focus behaviour. |
| 9 | Missing category; assigning updates within ~1 s | Pass\* | Rule and REST override tests; the 1500 ms floor in Jest `useValidation`. Browser: the timing. |
| 10 | Only the default category | Pass | `Category_Rule_Test::test_only_the_default_category_warns`; `Checklist_Service_Test::test_a_real_product_produces_the_expected_result` (real WooCommerce default term, a warning, not a required failure) |
| 11 | Missing SKU: warns, then blocks when required | Pass | `Publish_Guard_Classic_Test::test_the_configured_severity_decides_enforcement` |
| 12 | Short description below threshold | Pass | `Short_Description_Rule_Test::test_a_short_one_warns_with_both_numbers` (a warning never blocks: `Validation_Result_Test`) |
| 13 | Description empty | Pass | `Description_Rule_Test::test_an_empty_description_fails`; required failures refuse publishing (`Publish_Guard_Classic_Test`) |
| 14 | 1 image with `min_images = 2`; adding one clears it | Pass\* | `Image_Count_Rule_Test`; `Product_Context_Test` gallery de-duplication. Browser: the live clear. |
| 15 | Toggle required vs warning | Pass | `Publish_Guard_Classic_Test::test_the_configured_severity_decides_enforcement`; `Settings_Test::test_the_hash_changes_when_any_value_changes`; `Validator_Test` severity matrix |
| 16 | All rules disabled | Pass\* | Server: `Publish_Guard_Disabled_Test::test_all_rules_disabled_blocks_nothing`. Browser: the panel's "No checks are enabled." state, which has no Jest test. |
| 17 | Quick Edit → Published on a failing product | Pass | `Publish_Guard_Quick_Edit_Test` (3 tests); list notice rendering in `Output_Security_Test` |
| 18 | Bulk Edit 5 products, 2 failing | Pass | `Publish_Guard_Bulk_Test::test_failing_products_are_refused_one_by_one` (exactly 3 publish, 2 stay drafts, the notice names the 2) |
| 19 | Schedule a failing product | Pass | `Publish_Guard_Scheduled_Test` (blocked at `future`; the backstop reverts) |
| 20 | `block_on_required_failure` off | Pass | `Publish_Guard_Disabled_Test::test_nothing_is_blocked_and_the_checklist_still_reports` |
| 21 | Override as shop manager | Pass | `Publish_Guard_Override_Test::test_a_shop_manager_can_override_when_allowed` (asserts the override notice) |
| 22 | Products list with 50 products | Pass | +2 queries against the baseline (budget ≤ 5) on both targets. See §6.1 and `Product_List_Column_Test::test_a_full_page_stays_within_the_query_budget`. Measured in-process rather than with Query Monitor. |
| 23 | No assets on non-product screens | Pass | `Assets_Test` (8 other screens, plus the editor, list and settings rows of §11.1) |
| 24 | WooCommerce deactivated | Pass | Smoke boot of the real plugin file with WooCommerce absent, on both targets: no PHP error raised by the plugin, `Requirements` failure `wc_missing`, notice "Product Publish Guard has been stopped. WooCommerce is not active. Activate WooCommerce to use the product checklist.", and the only hooks registered are the bootstrap, the compatibility declaration and the notice. The plugin is inert. |
| 25 | Product block editor active (older WC) | Pass\* | Enforcement: Pass (Layer B over `/wc/v3`). Notice: implemented 2026-09-27 (SIT-WCPG-TEST-8), verified with the editor simulated on WC 11. \*A real WC 9.x block-editor pass is still to do. |
| 26 | Expired nonce | Pass\* | Jest `useValidation`: "stops automatic checks after the session expired, but not Re-check". Browser: the message as rendered. |
| 27 | Visual and Text editor modes | Pass\* | Jest `buildSnapshot`: "reads the visual editor when it is showing", "falls back to the textarea in Text mode". Browser: with real TinyMCE. |

**Totals:** 19 Pass · 7 Pass\* · 1 Open.

## 6. Performance

All figures are in-process on the test install: no HTTP, no persistent object cache, and each run starts with cold
post, meta and term caches. They are medians of 7 runs. Absolute times are machine-dependent; query counts are not.

### 6.1 Products list, 50 products (§11.2, §16.19, row 22)

Each product has a featured image, a gallery image, a category and a tag. `image_count` is enabled so gallery
priming runs. Half the products are published and half are drafts.

| | Floor | Current |
|---|---|---|
| Main query without the column (queries) | 5 | 5 |
| Main query + priming + 50 cells (queries) | 7 | 7 |
| **Added queries** | **+2** (budget ≤ 5) | **+2** (budget ≤ 5) |
| Added time, whole page | ≈ 28 ms (0.56 ms/row) | ≈ 33 ms (0.67 ms/row) |
| Column HTML, 50 cells | 14.5 KB | 14.5 KB |

The +2 is the single attachment prime pair from §11.2 (featured and gallery ids merged). Rendering the cells adds
no queries.

### 6.2 Product editor screen

| | Floor | Current |
|---|---|---|
| Payload build at `admin_enqueue_scripts` (queries / time) | 5 / 4.4 ms | 2 / 4.6 ms |
| Meta box render after the payload (queries / time) | **0** / 0.17 ms | **0** / 0.15 ms |
| Inline payload `sitWcpgEditorData` | 3.0 KB | 3.0 KB |
| Meta box HTML (server-rendered fallback) | 3.9 KB | 3.9 KB |

The meta box reuses the per-request memo (§11.3 layer 1), so the editor validates each product once per page load.

| Asset | Raw | gzip |
|---|---|---|
| `build/editor.js` | 14.1 KB | 5.1 KB |
| `build/editor.css` | 1.9 KB | 0.6 KB |
| `build/admin.css` (list and settings only) | 0.5 KB | 0.2 KB |

React is not bundled. `editor.js` depends on core's `wp-element`, `wp-components`, `wp-api-fetch`, `wp-i18n`,
`wp-date`, `wp-dom-ready` and `jquery`.

### 6.3 Live validation request (§11.5)

This is the one request the panel repeats while the user types, at most every 1.5 s.

| | Floor | Current |
|---|---|---|
| `POST /sit-wcpg/v1/products/{id}/validate` with a draft (queries / time) | 6 / 5.0 ms | 3 / 3.6 ms |
| Response body | 2.7 KB | 2.7 KB |

It performs no writes (`Rest_Validate_Test::test_the_product_is_unchanged_after_a_call`).

### 6.4 Not measured here

Browser-side figures: first paint of the React panel, console and React warnings (§16.13), and wall-clock page loads
under Query Monitor. These belong to the browser pass in §7.

## 7. Remaining work to close Phase 11

1. Decide SIT-WCPG-TEST-8 (row 25): implement the block-editor notice, or amend §12.4 row 25 and §16.17.
2. Run a browser pass on a clean site at each target (both profiles in `.wp-env*.json`) for rows 7, 8, 9, 14, 16, 26
   and 27. While there, confirm there are no console errors or React warnings on the product editor (§16.13) and
   take a Query Monitor reading of the 50-product list (row 22).
3. Optionally, repeat §3 under `wp-env` once Docker is available, to confirm parity with the local harness.

## 8. Reproducing

```bash
composer test:unit                 # unit suite, no WordPress needed
composer lint
npm run test:unit:js && npm run lint:js
```

Integration and security suites, with `wp-env` (per §12; these commands were not run in this session because Docker is unavailable):

```bash
npm run env:current                # or: npm run env:floor
npx wp-env run tests-cli --env-cwd=wp-content/plugins/product-publish-guard vendor/bin/phpunit --testsuite integration
npx wp-env run tests-cli --env-cwd=wp-content/plugins/product-publish-guard vendor/bin/phpunit --testsuite security
```

Without Docker, as in this report: point `WP_PHPUNIT__DIR` at a `wp-phpunit/wp-phpunit` install matching the WP
version, and point `WP_PHPUNIT__TESTS_CONFIG` at a `wp-tests-config.php`. That config sets `ABSPATH` to a clean core
whose `wp-content/plugins/woocommerce` holds the WooCommerce version under test, and `DB_NAME` to a **dedicated,
disposable** database. Then run `vendor/bin/phpunit --testsuite integration` (and `security`).

## 9. Phase 12 — release zip smoke test (2026-09-25)

`npm run package` built `dist/product-publish-guard.zip`: 51 files, 108.6 KB. It contains the main file,
`uninstall.php`, both readmes, `LICENSE`, `src/`, `build/` and `languages/`. It has no `assets/`, `tests/`,
`vendor/`, `node_modules/`, `bin/`, `.claude/` or dotfiles. `build/editor.js` is 14.1 KB minified, and React is external.

The zip was installed with `wp plugin install <zip> --activate` on two clean sites, each with its own fresh
database: floor (PHP 8.0.30 / WP 6.5 / WC 9.0.0) and current (PHP 8.3.33 / WP 7.1.2 / WC 11.1.1). The rows ran as a
signed-in administrator from a PHP script that loads `wp-load.php` outside WP-CLI, since WP-CLI is excluded from
enforcement by design.

| Row | Floor | Current | Notes |
|---|---|---|---|
| 1 Simple, complete | Pass | Pass | 10 of 10 checks pass (1 skipped: no sale). `wp_update_post` publishes it. |
| 2 Simple, incomplete | Pass\*\* | Pass\*\* | `featured_image` + `price` are required failures. Publishing is refused through both `wp_update_post` and `$product->save()`, and the product stays a draft. The editor notice lists the failures. \*\*Category: see SIT-WCPG-PKG-1. |
| 22 50-product list | Pass | Pass | Column registered, 50 cells, 14.6 KB. +4 / +5 queries against the baseline (budget ≤ 5). This run flushed the whole object cache, `alloptions` included, which is why it reads higher than §6.1's +2. |
| 24 WooCommerce deactivated | Pass | Pass | No fatal error and no guard hooks. Notice: "Product Publish Guard has been stopped. WooCommerce is not active. …" |

HTTP pass on the current site (`php -S`, logged-in admin, `WP_DEBUG_LOG` on): the product editor, for ready and
not-ready products, the products list, the settings page and the dashboard all returned 200. The editor carries the
mount node, `sitWcpgEditorData` and `build/editor.js`. The list carries the readiness column and `admin.css`. The
dashboard loads no plugin assets. `debug.log` stayed empty.

**SIT-WCPG-PKG-1 (open):** row 2's "3 required failures" cannot occur on a real site. WooCommerce's
`force_default_term` puts `default_product_cat` back whenever the categories are cleared, so the category rule warns
"only the default category" (row 10) instead of failing. **Decided 2026-09-27:** row 2 amended to expect 2 required
failures plus the default-category warning (`sections/12-testing.md`); §5.6 unchanged. Closed.
