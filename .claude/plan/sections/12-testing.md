<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 12. Testing plan

Framework: PHPUnit + the WordPress test suite + WooCommerce test helpers, orchestrated with `wp-env` (`.wp-env.json` pins WP and WooCommerce). JS unit tests via `wp-scripts test-unit-jest` (Jest 30, `jest.config.js`) — `@wordpress/scripts` 36 moved `test-unit-js` to Vitest, and the suite stays on Jest as planned.

### 12.1 Unit tests (no WordPress bootstrap required)

Because rules are pure functions of `Product_Context::from_array()` + `Settings`, `tests/Unit/` runs on plain PHPUnit with a minimal `Settings` stub.

One test class per rule, covering **every branch in §5.6**:

| Test class | Required cases |
|---|---|
| `Title_Rule_Test` | empty → fail; whitespace-only → fail; auto-draft placeholder → fail; normal → pass |
| `Description_Rule_Test` | empty → fail; below threshold → warn (message contains both integers); exactly at threshold → pass; above → pass; threshold 0 → never warns; HTML and shortcodes stripped before counting; multibyte counted correctly |
| `Short_Description_Rule_Test` | the same matrix with its own threshold |
| `Featured_Image_Rule_Test` | id 0 → fail; id set but not an image → fail; valid → pass |
| `Image_Count_Rule_Test` | below min → fail; exactly min → pass; featured counted once when also in the gallery; min ≤ 1 → skip |
| `Price_Rule_Test` | empty → fail; non-numeric → fail; negative → fail; `0` → **pass**; `19.99` → pass; variable → skip; grouped → skip |
| `Sale_Price_Rule_Test` | no sale price → skip; sale ≥ regular → warn; non-numeric → warn; end before start → warn; valid → pass |
| `Category_Rule_Test` | none → fail; only the default term → warn; a real term → pass; default + real → pass |
| `Tags_Rule_Test` | none → fail; one → pass |
| `Sku_Rule_Test` | empty → fail; whitespace → fail; set → pass |
| `Stock_Status_Rule_Test` | empty → fail; invalid value → fail; `outofstock` → warn; managed stock, qty 0, status `instock` → warn; `instock` → pass |

Engine and settings unit tests:

| Test class | Cases |
|---|---|
| `Rule_Result_Test` | factories set status/severity/rule_id; `to_array()` shape matches §5.4 exactly |
| `Validation_Result_Test` | counts exclude skipped from `evaluated`; `is_ready` false iff a `fail` exists; `required_failure_ids` contains only `fail` results whose severity is `required` |
| `Validator_Test` | **the full §5.5 matrix**: `fail` + `warning` severity → `warning`; `warn` + `required` severity → stays `warning`; `skip` never escalates; disabled rules are not run at all; unsupported rules are not run; a rule that throws does not break the run |
| `Rule_Registry_Test` | duplicate id rejected; ordering by priority then id; `get_active()` filtering |
| `Settings_Sanitizer_Test` | unknown rule ids dropped; unknown top-level keys dropped; invalid severity falls back to the rule default; thresholds clamped at both ends; a non-numeric threshold falls back to the default; `version` cannot be injected |
| `Product_Context_Test` | override-present vs override-absent merge semantics; length normalization; gallery/featured de-duplication |
| `Save_Request_Reader_Test` | classic-editor payload; quick-edit payload (an absent content field must **not** become empty); bulk-edit payload (`-1` sentinels ignored); slashed input is unslashed |

### 12.2 Integration tests (WordPress + WooCommerce bootstrapped)

| Test class | Scenario |
|---|---|
| `Checklist_Service_Test` | a real `WC_Product_Simple` with known data produces the expected result; a second call in the same request does not re-run the engine (assert with a counting rule double) |
| `Editor_Meta_Box_Test` | the meta box is registered on the product screen; rendered output contains the mount node and escaped fallback items; no PHP notices |
| `Product_List_Column_Test` | column registered; cell output for ready/warning/error products; primed caches keep query count bounded for a 20-product page (assert `$wpdb->num_queries` deltas) |
| `Settings_Test` | defaults applied for an unsaved option; a newly registered rule receives its defaults; the hash changes when any value changes |
| `Publish_Guard_Classic_Test` | `wp_update_post( status=publish )` with a failing required rule → status ends as `draft` and a notice transient exists; with all rules passing → status is `publish` |
| `Publish_Guard_Quick_Edit_Test` | a simulated quick-edit payload (no content field) does not produce a spurious description failure |
| `Publish_Guard_Bulk_Test` | a bulk status change is blocked per failing product while passing products in the same batch still publish |
| `Publish_Guard_Crud_Test` | `$product->set_status( 'publish' ); $product->save();` with failures ends as `draft` (Layer B) |
| `Publish_Guard_Scheduled_Test` | scheduling a failing product is blocked at `future`; the `future_to_publish` backstop reverts a product broken after scheduling |
| `Publish_Guard_Disabled_Test` | with `block_on_required_failure` off, publishing always succeeds and the checklist still reports failures |
| `Publish_Guard_Override_Test` | with override enabled a `manage_woocommerce` user publishes; a user without that capability still cannot |
| `Publish_Guard_Failopen_Test` | a rule that throws results in publishing being **allowed**, with a logged warning |
| `Rest_Validate_Test` | 200 for a valid draft payload; the draft overrides the stored product; absent keys fall back to stored values; 404 for a non-product id; the product is unchanged after the call |

### 12.3 Security tests

| Test | Expectation |
|---|---|
| REST validate as a logged-out user | 401/403, no result body |
| REST validate as a subscriber | 403 |
| REST validate as an author on someone else's product (no `edit_others_products`) | 403 |
| REST validate with a missing/invalid `X-WP-Nonce` under cookie auth | rejected by core before the handler |
| REST validate with 10,000 `category_ids` | capped, no timeout, 200 |
| REST validate with `product_type` = `'<script>'` | rejected by `validate_callback`, 400 |
| Settings save without a nonce | `options.php` dies |
| Settings save as a subscriber, and as an `edit_products`-only user | denied by `option_page_capability_sit_wcpg_settings` |
| Settings save with an injected unknown rule id and an injected `version` | both dropped; the stored option matches the whitelist exactly |
| Publish attempt as a shop manager with `allow_admin_override` **off** | blocked |
| Publish via `/wc/v3/products` as a shop manager with failing rules | blocked (Layer B) |
| Product titled `"><script>alert(1)</script>` | the rendered checklist, the list column, the admin notice and the REST response contain **no** occurrence of `<script>` (assert on the raw output string) |
| Product description containing an executable shortcode | never executed — `strip_shortcodes` only, `do_shortcode` is never called |

### 12.4 Manual test matrix

Run every row on a clean install at the minimum versions, then again on current WP/WC.

| # | Case | Expected |
|---|---|---|
| 1 | Simple product, all fields complete | All checks pass, "Ready", publishes normally |
| 2 | Simple product, incomplete (no image, price or category) | 3 required failures; publish blocked; stays a draft; notice lists all three |
| 3 | Variable product with priced variations | `price` shows "Not applicable"; other rules evaluate; excluded from counts |
| 4 | Variable product with no variations | Same as #3 (V1 adds no variation rule) |
| 5 | Draft product | Checklist works; list column shows the readiness state |
| 6 | Published product later broken (price removed) | Checklist shows the failure; the product **stays published**; list column shows errors |
| 7 | Missing price only | `price` fails; publish blocked; adding a price flips it to ✓ without a reload |
| 8 | Missing featured image | `featured_image` fails; the fix link focuses the media box |
| 9 | Missing category | `category` fails; assigning one updates the panel within ~1 s |
| 10 | Only the default category assigned | `category` warns, does not block |
| 11 | Missing SKU (default `warning`) | Warning, publish allowed; switching it to `required` then blocks publishing |
| 12 | Short description below threshold | Warning with the exact character counts; publish allowed |
| 13 | Description empty | Required failure; publish blocked |
| 14 | 1 image with `min_images = 2` | Warning; adding a gallery image clears it |
| 15 | Toggling required vs warning | The panel and enforcement both change immediately |
| 16 | All rules disabled | Panel shows a "no checks enabled" state; publishing is never blocked |
| 17 | Quick Edit → Published on a failing product | Stays a draft; the Readiness cell shows errors; a notice appears on the next page load |
| 18 | Bulk Edit → Published on 5 products, 2 failing | 3 publish, 2 remain drafts, the notice names the 2 |
| 19 | Schedule a failing product for tomorrow | Scheduling blocked with the same notice |
| 20 | `block_on_required_failure` off | Nothing is blocked; the checklist still reports |
| 21 | Override on, acting as a shop manager | Publish succeeds with an "override used" notice |
| 22 | Products list with 50 products | Column renders; load time and query count comparable to the plugin being deactivated (measure with Query Monitor) |
| 23 | Non-product admin screens (Posts, Orders, Dashboard) | No plugin JS/CSS enqueued (verify in the network panel) |
| 24 | WooCommerce deactivated | Admin notice shown, no fatal error, plugin inert |
| 25 | Product block editor active (**older WooCommerce only** — not reachable on WC 11, which force-disables it) | Notice explains the panel is unavailable; **publish enforcement still works** |
| 26 | Long idle, then edit (expired nonce) | The panel shows the session-expired message, not a silent stale result |
| 27 | Visual and Text editor modes | Description changes are detected in both |
