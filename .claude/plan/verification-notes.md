# Verification Notes — Phase 0

Answers to `coding-plan.md` §17, verified against the code actually installed in this environment on 2026-09-22.
Every line reference below points at a real file in this WordPress install and was read, not assumed.

## Environment as measured

| Thing | Value | Where checked |
|---|---|---|
| WordPress | **7.1.1** | `wp-includes/version.php` |
| WooCommerce | **11.1.1** | `wp-content/plugins/woocommerce/woocommerce.php` |
| PHP (CLI on PATH) | 8.0.30 NTS x64 | `php -v` |
| PHP available in Laragon | 8.1.10, 8.3.33 | `G:/laragon/bin/php` |
| Node / npm | **v24.16.0** / **10.5.2** | `node -v`, `npm -v` |
| Composer | 2.6.5 | `composer -V` |
| Persistent object cache | **none** (`wp-content/object-cache.php` absent) | filesystem |
| Git repository | **no** (no `.git` in the site or the plugin) | filesystem |
| Other plugins present | `akismet`, `content-workflow-manager`, `woocommerce`, `hello.php` | `wp-content/plugins/` |

The installed stack is far newer than the plan's declared floors (PHP 7.4 / WP 6.5 / WC 8.2). The floors stay as
*declared* minimums, but note that **the minimum target cannot be exercised locally** — PHP 7.4 is not installed,
and neither is a WC 8.x. DoD item 10 ("suites pass at the minimum target") therefore needs `wp-env` with pinned
`core` and `plugins` versions, or it needs to be honestly restated. Recorded as an open item below.

---

## WooCommerce APIs

**1. `woocommerce_before_product_object_save` — CONFIRMED, Layer B is sound.**
`includes/abstracts/abstract-wc-product.php:1559`:
`do_action( 'woocommerce_before_' . $this->object_type . '_object_save', $this, $this->data_store );`
Signature `( WC_Data $product, WC_Data_Store_WP $data_store )`. It fires inside `WC_Product::save()`,
**after** `validate_props()` (line 1547) and **before** `$this->data_store->update()/create()`. So at hook time the
object's props — including `status` — are final and stock status has already been normalised by `validate_props()`.
Core's own docblock says *"Allows you to adjust object props before save"*, which is exactly Layer B's mechanism:
`$product->set_status( 'draft' )` inside the hook is a supported use, not a hack.
Note `validate_props()` may itself flip stock status before we see it — read stock from the object, never from POST, in Layer B.

**2. `FeaturesUtil::declare_compatibility()` — CONFIRMED for HPOS, NOT APPLICABLE for the block editor.**
- `src/Utilities/FeaturesUtil.php:87` — `declare_compatibility( string $feature_id, string $plugin_file, bool $positive_compatibility = true ): bool`.
  Must run inside `before_woocommerce_init`. Returns **false** if the feature id does not exist (documented on the method).
- `custom_order_tables` — **exists**, registered by `CustomOrdersTableController::add_feature_definition()`
  (`src/Internal/Features/FeaturesController.php:278`; slug used at `src/Internal/DataStores/Orders/CustomOrdersTableController.php:624`). Declare compatible.
- `product_block_editor` — **does not exist in WC 11.1.1.** No feature definition anywhere; the only surviving
  trace is the string `product-block-editor` as a *telemetry source* in
  `src/Admin/Features/ProductBlockEditor/BlockRegistry.php:147` and `ProductTemplate.php:191`, and neither class is
  instantiated anywhere outside its own directory. **→ Plan amended:** drop the incompatibility declaration.

**3. Detecting an active product block editor — MOOT HERE; the API named in the plan is deprecated.**
- `includes/class-wc-post-types.php:45-46` hooks both `gutenberg_can_edit_post_type` and `use_block_editor_for_post_type`
  to `gutenberg_can_edit_post_type()` (line 777), which returns **`false` for `product` unconditionally**.
  WooCommerce 11.1.1 guarantees the classic product editor. A7's "detect and warn" path cannot trigger here.
- `Automattic\WooCommerce\Admin\Features\Features::is_enabled()` (`src/Admin/Features/Features.php:356`) is
  **deprecated since WC 11.1.0** in favour of `FeaturesUtil::feature_is_enabled()` (`src/Utilities/FeaturesUtil.php:41`),
  which itself emits a deprecation notice for deprecated feature ids.
  **→ Plan amended:** detection uses `FeaturesUtil::feature_is_enabled()` behind `method_exists()`, and the whole
  check is a defensive branch for older WooCommerce, not a V1 UI requirement.

**4. Gallery meta key and POST field — CONFIRMED, both current.**
- Meta key `_product_image_gallery`: `includes/data-stores/class-wc-product-data-store-cpt.php:483` maps it to the
  `gallery_image_ids` prop; also listed in the internal meta keys at line 74.
- Classic-editor POST field `product_image_gallery` (comma-separated ids), hidden input rendered at
  `includes/admin/meta-boxes/class-wc-meta-box-product-images.php:116` and read back at line 166.

**5. Type and stock-status key sets — CONFIRMED, values unchanged.**
- `wc_get_product_types()` (`includes/wc-product-functions.php:1025`, filter `product_type_selector`):
  `simple`, `grouped`, `external`, `variable`. `variation` is **not** in the list.
- `wc_get_product_stock_status_options()` (line 1401, filter `woocommerce_product_stock_status_options`):
  `instock`, `outofstock`, `onbackorder`.
- New in modern WC: `Automattic\WooCommerce\Enums\ProductType`, `ProductStockStatus` (which also defines
  `LOW_STOCK = 'lowstock'`, *not* offered in the options list) and `ProductStatus`
  (`auto-draft/draft/pending/private/publish/trash/future`).
  **The string values are identical to the historical literals**, so the plan keeps using literals for WC 8.2
  compatibility. Do not reference the enum classes — they do not exist on the declared floor.

**6. `default_product_cat` — CONFIRMED as an option holding an integer term id.**
`includes/admin/class-wc-admin-taxonomies.php:398`/`438` read it via `absint( get_option( 'default_product_cat', 0 ) )`;
line 421 writes it. **Important for the category rule:** the "default category" in WooCommerce is a *deletion
fallback* ("products that were only assigned to the deleted category are set to…", line 349), **not** an
auto-assignment on save. Nothing in WooCommerce assigns Uncategorized to a product on save.
See also item 8 — WordPress core's `wp_publish_post()` *does* apply a taxonomy default term, but `product_cat` is
registered without `default_term`, so it is skipped there too. **The category rule is therefore meaningful: a
product really can reach publish with zero categories.**

---

## WordPress core behaviour

**7. `wp_insert_post_data` receives slashed data — CONFIRMED.**
`wp-includes/post.php:4978`, docblock at 4973: *"An array of sanitized (and slashed) but otherwise unmodified post data."*
The plan's `wp_unslash()` on `$data`/`$postarr` is correct and required.
It fires for Quick Edit and Bulk Edit because both funnel into `edit_post()` → `wp_update_post()` → `wp_insert_post()`
(`wp_ajax_inline_save` calls `edit_post()` in `wp-admin/includes/ajax-actions.php`; `bulk_edit_posts()` in
`wp-admin/includes/post.php` does the same per post).

**8. `wp_publish_post()` still bypasses `wp_insert_post_data` — CONFIRMED. Layer C is required.**
`wp-includes/post.php:5446` writes the status with a raw `$wpdb->update( $wpdb->posts, ... )`. No `wp_insert_post()`,
no filter. It then calls `clean_post_cache()`, `wp_transition_post_status( 'publish', $old_status, $post )` and the
`edit_post`/`save_post` actions — so **`future_to_publish` does fire**, which is what Layer C hooks. Confirmed.
Also worth knowing: this function force-applies a taxonomy's `default_term` when the post has none.
`product_cat` has no `default_term`, so products are unaffected.

**9. Quick Edit / Bulk Edit field names — CONFIRMED, with one design-relevant surprise.**

*Status (core, both paths):* `_status`. In Bulk Edit `-1` is the "— No Change —" sentinel and
`bulk_edit_posts()` (`wp-admin/includes/post.php`) **unsets `post_status` entirely** before saving when it sees it;
it applies the same `''`/`-1` → unset treatment to the other bulk fields. Quick Edit maps `_status` →
`post_status` in `wp_ajax_inline_save` (with `private` taking precedence). Nonce: `_inline_edit` / action `inlineeditnonce`.

*WooCommerce product fields* (`includes/admin/class-wc-admin-post-types.php`):
`_sku`, `_regular_price`, `_sale_price`, `_sale_price_dates_from`/`_to`, `_stock_status`, `_stock`, `_manage_stock`,
`_backorders`, `_weight`, `_length`, `_width`, `_height`, `_visibility`, `_tax_class`, `_tax_status`,
`_shipping_class`, `_featured`, `_sold_individually`.
Bulk Edit wraps price and stock in *modifier* selects — `change_regular_price`, `change_sale_price`, `change_stock`
— where an empty/absent modifier means "no change" (lines ~622-690); `change_stock` is a numeric mode
(1 = increase, 2 = decrease, otherwise set).
Gating fields: `woocommerce_quick_edit` (presence = quick edit) and nonce `woocommerce_quick_edit_nonce`
(verified at line 374).

> **Surprise that matters for Layer A.** WooCommerce applies quick/bulk edit product values on **`save_post`
> (priority 10)** via `bulk_and_quick_edit_hook()` (lines 79 and 346) — i.e. **after** `wp_insert_post_data` has run.
> Two consequences, both good for us and both already satisfied by `Product_Context::from_save_request()`:
> 1. Layer A must read price/SKU/stock from `$_POST` itself (it cannot ask the product object, which is still stale).
>    The POST names above are the ones to read.
> 2. When Layer A downgrades the status, WooCommerce still applies the field edits afterwards. That is the desired
>    behaviour: the merchant's data edits are kept, only the publish is refused.
>
> Bulk Edit's `-1` handling means `post_status` may be **absent** from `$data`. `wp_insert_post()` then carries the
> existing status forward, so Layer A must treat "absent status" as "no transition requested" and skip — *not* as a
> publish attempt. This matches the plan's skip matrix; it deserves an explicit integration test.

**10. `option_page_capability_{$option_group}` — CONFIRMED.**
`wp-admin/options.php:47`: `$capability = apply_filters( "option_page_capability_{$option_page}", $capability );`
The `manage_woocommerce` plan holds.

**11. REST nonce middleware is attached automatically — CONFIRMED, no extra work needed.**
`wp-includes/script-loader.php:351-371` adds `wp-hooks` as a dep of `wp-api-fetch` and inlines
`wp.apiFetch.nonceMiddleware = wp.apiFetch.createNonceMiddleware( "<nonce>" ); wp.apiFetch.use( wp.apiFetch.nonceMiddleware );`
whenever `wp-api-fetch` is registered. Declaring `wp-api-fetch` as a dependency is sufficient for `X-WP-Nonce`.

**12. Text-domain timing — CONFIRMED, and stricter than the plan assumed.**
`wp-includes/l10n.php:1444` fires `_doing_it_wrong()` (since 6.7.0) when a domain is loaded before
`after_setup_theme`, telling authors to translate at `init` or later. The plan's "load on `init`" rule is correct.
Additionally: with modern WP, `load_plugin_textdomain()` is optional for plugins hosted on w.org — just-in-time
loading covers it — but calling it on `init` is harmless and keeps the plugin self-contained. **No change.**

**13. Cache-priming signatures — CONFIRMED.**
- `update_post_caches( &$posts, $post_type = 'post', $update_term_cache = true, $update_meta_cache = true )` — `wp-includes/post.php:7998`.
  Takes `$posts` **by reference**, so pass the real variable.
- `update_object_term_cache( $object_ids, $object_type )` — exists as used.
- Attachment priming: use **`_prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true )`**
  (`wp-includes/post.php:8494`), called as `_prime_post_caches( $attachment_ids, false, true )` — no term cache for
  attachments, meta cache yes (we need `_wp_attached_file` / `_wp_attachment_metadata`). It is an underscore-prefixed
  core function but stable and widely used; the alternative is a bare `get_posts( [ 'post__in' => $ids ] )`, which
  costs an extra query. **→ Plan amended** to name the function explicitly.

---

## Editor integration

**14. Product data panel events — PARTIALLY VERIFIED; use delegation, as the plan already requires.**
Field ids confirmed: `#_regular_price` (`includes/admin/meta-boxes/views/html-product-data-general.php:62`),
`#_sku` (`html-product-data-inventory.php:20`), `#_stock_status` (`html-product-data-inventory.php:158`).
`assets/js/admin/meta-boxes-product.js` drives the panel with jQuery `.trigger('change')` in ~19 places, and the
panels are switched by `show_if_*` / `hide_if_*` class toggling on product-type change
(`class-wc-meta-box-product-data.php:83-113`) — the simple/external/grouped panels are **shown and hidden, not
re-rendered**, so those ids are stable. The variations panel *is* AJAX-rendered, but V1 reads no variation fields.
**Conclusion:** bind with jQuery delegation on `#woocommerce-product-data` for `change`/`input`, exactly as
§10 already mandates, and additionally listen for `woocommerce-product-type-change` to re-snapshot when the type
switches (a type change alters which rules apply). Confirm interactively in Phase 7.

**15. TinyMCE — NOT FULLY VERIFIABLE STATICALLY; treat defensively.**
The product post type supports `editor` and `excerpt` (`includes/class-wc-post-types.php:341`:
`array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'publicize', 'wpcom-markdown' )`), and
WooCommerce forces the classic editor for products (item 3), so TinyMCE is the editor in play.
The instance is created asynchronously and **`tinymce.get('content')` returns `null` in Text mode**.
Required behaviour, unchanged from the plan but now explicit: read the visual editor via
`window.tinymce && tinymce.get('content') && ! tinymce.get('content').isHidden()`, otherwise fall back to the
`#content` textarea; subscribe to both the TinyMCE `change`/`keyup`/`SetContent` events and the textarea's `input`;
and never assume the instance exists at mount — resolve it lazily on each snapshot. Same for `#excerpt`
— **corrected 2026-09-27:** it is *not* a plain textarea; WooCommerce renders it with `wp_editor( …, 'excerpt' )`,
so it is a second TinyMCE instance and must be read through `tinymce.get('excerpt')` exactly like `content`.
Verify interactively during Phase 7.

**16. Featured image / gallery DOM — CONFIRMED, with a correction to the plan.**
`wp-admin/includes/post.php:1704` renders
`<input type="hidden" id="_thumbnail_id" name="_thumbnail_id" value="<id or -1>" />`.
Two things the plan did not say:
- **The empty value is `-1`, not `''` or `0`.** Line 2134 confirms core normalises anything `<= 0` to `-1`.
  The snapshot builder and `Save_Request_Reader` must map `<= 0` → `0` (no featured image).
- The whole `#postimagediv .inside` block is **replaced wholesale** by the AJAX response when the user sets or
  removes the image, so the `#_thumbnail_id` node itself is destroyed and recreated. A MutationObserver bound to
  the input will silently stop firing. **→ Plan amended:** observe `#postimagediv` (`childList: true, subtree: true`)
  and re-read `#_thumbnail_id` on each mutation.
- Gallery: hidden `#product_image_gallery` input (`class-wc-meta-box-product-images.php:116`), value is a
  comma-separated id list, updated in place by WooCommerce's own JS — observe the container
  `#woocommerce-product-images` and parse the input's value.

---

## Environment questions

**17. PHP / Node — PASS, with a caveat.**
Node 24.16.0 and npm 10.5.2 are present; `@wordpress/scripts` works. PHP on the CLI path is 8.0.30 and Laragon
ships 8.1.10 and 8.3.33. All are ≥ 7.4, so the plugin runs. **But PHP 7.4 is not installed**, so the
"minimum target" half of DoD item 10 cannot be run here without `wp-env`/Docker. Decide in Phase 1:
either (a) add a `.wp-env.json` matrix with a PHP 7.4 + WC 8.2 profile, or (b) raise the declared floor to
PHP 8.0 / WC 9.x and simplify. **Recommendation: (b)** — the real-world install base for WC 8.2 is small, and
raising the floor lets the code use typed properties freely while removing an untestable claim.
This is a user decision; flagged below.

**18. Persistent object cache — ABSENT.**
No `wp-content/object-cache.php`. `wp_cache_*` is per-request only here. §11.3 layer 2 therefore degrades to layer 1
in this environment — correct either way, but *the persistent-cache path will not be exercised locally*.
Test it explicitly with a mocked persistent cache, or the memoisation-invalidation logic ships unverified.

**19. Git — NOT A REPOSITORY.**
Neither the site root nor the plugin directory is under version control. **Decision needed before Phase 1:**
whether to `git init` the plugin directory, and if so, whether `build/` is committed or CI-produced.
**Recommendation:** `git init` the plugin only, commit `build/` (no CI exists here, and the zip must be installable
from a clean checkout), and `.gitignore` `node_modules/` and `vendor/`.

**20. Other plugins that could interact with Layer A — ONE NEIGHBOUR, LOW RISK.**
`content-workflow-manager` is installed alongside. Checked its whole `includes/` and `src/`:
- **No `wp_insert_post_data` filter**, no `transition_post_status` handler, no `wp_publish_post()` call.
- Its only capability filter is `PostMeta::lock_status_meta()` on `map_meta_cap`
  (`includes/Content/PostMeta.php:105`), which appends `do_not_allow` **only** for meta caps targeting its own
  `PostRepository::META_STATUS` meta key. It cannot affect `publish_product` or `edit_post`.
- It operates on an opt-in post-type list (`Settings::enabled_post_types()`), so `product` may or may not be in scope.

**Conclusion:** no conflict with Layer A/B/C. One thing to keep in mind: if `product` *is* workflow-enabled, two
plugins will be gating the same publish action and their messages should not contradict. Worth one manual test row.
`akismet` and `hello.php` are irrelevant.

---

## Extra findings (not in §17, but they change code)

- **`is_purchasable()` settles decision 23.** `includes/abstracts/abstract-wc-product.php`:
  `$this->exists() && $this->is_viewable() && '' !== $this->get_price()`. WooCommerce's own definition of "has a
  usable price" is *not empty string* — a price of `0` is purchasable. The plan's "price `0` passes, empty fails"
  matches core exactly. **Recommend confirming decision 23 as-is.**
- **`ProductStatus::FUTURE` and the whole status set** are exactly the literals the plan uses. No change.
- **`validate_props()` runs before Layer B's hook**, so a product whose stock quantity forces `outofstock` will
  already read as `outofstock` at Layer B. The stock rule sees the post-normalisation value — which is the value the
  customer will see, so that is the correct input.
- **Item 6 corrected, measured 2026-09-24 during the Phase 5 gate.** `get_taxonomy( 'product_cat' )->default_term`
  really is `null`, exactly as item 6 says — but a product saved with no categories nevertheless comes back holding
  the store's default term. Measured on WP 7.1.2 / WC 11.1.1: after `( new WC_Product_Simple() )->save()` with no
  categories set, `get_the_terms( $id, 'product_cat' )` returns the `default_product_cat` term (id 16 here) and so
  does a freshly fetched `wc_get_product( $id )->get_category_ids()`. **The in-memory object that performed the save
  still reports `[]`** — it is stale, which is what made the first run of the Phase 5 gate expect the wrong branch.
  Two consequences:
  1. The category rule's `count === 0` → *fail* branch is reachable only when a merchant explicitly removes the
    category, not on a fresh product. The ordinary "I forgot the category" case lands on the
    `is_only_default_category()` → *warn* branch. Both branches exist and both are correct; **no code change**.
  2. Anything that reads categories must read them through `Product_Context` (which uses `get_the_terms()`), never
    from a `WC_Product` object it has just saved. This matters for Layer B in Phase 8.

---

## User decisions — ALL SETTLED 2026-09-24

The five open items were put to the user on 2026-09-24. **Every recommendation was accepted as written.**
These are now binding; do not re-litigate them during implementation.

| # | Question | Decision |
|---|---|---|
| A | §17.21 — default severities (SKU + tags `warning`, description `required`) | **Accepted as planned.** §5.6 stands unchanged: `required` = title, description, featured_image, price, category; `warning` = short_description, image_count, sale_price, tags, sku, stock_status. |
| B | §17.22 — `enforce_scope` default: `authenticated` vs `editor` | **`authenticated`.** The only value that closes the REST path. `wp_doing_cron()` and WP-CLI remain excluded regardless. |
| C | §17.23 — price `0` passes | **Accepted.** `0` passes, empty fails — matches `is_purchasable()`'s `'' !== get_price()` (evidence above). |
| D | Declared floors | **Raised to PHP 8.0 / WP 6.5 / WC 9.0.** Rationale: PHP 7.4 and WC 8.x are not installed here, so the old floor could not be substantiated. WP stays at 6.5 for the `Requires Plugins:` header. |
| E | `git init` the plugin, and commit `build/`? | **Yes — `git init` the plugin directory only, and commit `build/`.** No CI exists here and the zip must install from a clean checkout. `.gitignore` covers `node_modules/`, `vendor/` and `.phpcs.cache`. |

Consequences of D, applied to `coding-plan.md` in amendment 7 below:
constructor promotion, union types, `match`, named arguments and `?->` are now permitted;
enums, `readonly` properties, `never` return types and first-class callable syntax remain forbidden (8.1+).

## Amendments applied to `coding-plan.md`

1. §1.3 A7 and §4 (`Woo_Compat`, `Requirements`) — the `product_block_editor` incompatibility declaration is dropped
   (the feature does not exist); block-editor detection is a defensive branch using `FeaturesUtil::feature_is_enabled()`.
2. §6.1 `before_woocommerce_init` row — HPOS declaration only.
3. §5.6 / §8 — `#_thumbnail_id` may be `-1`; observe `#postimagediv`, not the input node.
4. §11.2 — attachment priming names `_prime_post_caches( $ids, false, true )`.
5. §12.4 row 25 — restated as "older WooCommerce with the block editor feature"; not reachable on WC 11.
6. §17 — now points at this file, and carries the two new open decisions (D and E).
7. **(2026-09-24, from decision D)** §2 `Min PHP` → **8.0** and `Min WooCommerce` → **9.0**, with the permitted/forbidden
   language-feature list restated for an 8.0 baseline; §4.1 (`Status.php`) and §10.1 (`strict_types` note) reworded to
   cite 8.0/8.1 rather than 7.4; §16 DoD item 10 restated as substantiable; §17 items 17 and 24 updated to record the
   settled floor.
