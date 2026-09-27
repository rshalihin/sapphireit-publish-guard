<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 6. WordPress / WooCommerce integration points

### 6.1 Bootstrap & compatibility

| Hook | Priority | Why / what we do | Side effects |
|---|---|---|---|
| `plugins_loaded` | 5 | `Requirements::check()` then `Plugin::boot()`. Late enough that WooCommerce has defined `WC_VERSION`; early enough to register everything else. | If requirements fail, only an `admin_notices` callback is registered. |
| `before_woocommerce_init` | 10 | `FeaturesUtil::declare_compatibility( 'custom_order_tables', … , true )` — HPOS compatible. Nothing else is declared. | Prevents WooCommerce's "incompatible plugin" warning on the HPOS screen. (The block-editor declaration was dropped: the feature id no longer exists.) |

No `load_plugin_textdomain()` call: WordPress.org-hosted plugins have their translations loaded just in
time (WP 4.6+), and Plugin Check warns on the call.

### 6.2 Admin surfaces

| Hook | Server/Client | Why | Data available | Action |
|---|---|---|---|---|
| `add_meta_boxes_product` | server | Product-specific variant of `add_meta_boxes`, so no per-request post-type branch. | `WP_Post` | Register the `sit_wcpg_product_checklist` meta box, context `side`, priority `high`. |
| `admin_enqueue_scripts` | server | Single conditional enqueue point; receives `$hook_suffix`. | screen | See §11.1. |
| `manage_edit-product_columns` | server | Adds the readiness column; product-specific hook. | columns array | Insert `sit_wcpg_readiness` before `date`. Skipped when the column setting is off or the user lacks `edit_products`. |
| `the_posts` | server | Runs once with **all** rows for the screen, before any column renders. The only place bulk cache priming is possible (R4). | `WP_Post[]` | Product list screen only: prime post, meta, term and attachment caches. |
| `manage_product_posts_custom_column` | server | Renders the cell. | `$column, $post_id` | `Checklist_Service::get_summary_for_post_id()` → escaped icon + label. |
| `admin_menu` | server | Settings submenu under WooCommerce. | — | `add_submenu_page( 'woocommerce', …, 'manage_woocommerce', 'sit-wcpg-settings', … )`. |
| `admin_init` | server | `register_setting( 'sit_wcpg_settings', 'sit_wcpg_settings', [ 'sanitize_callback' => … ] )` and `add_filter( 'option_page_capability_sit_wcpg_settings', … )`. | — | — |
| `admin_notices` | server | Publishing-blocked feedback + requirement failures + the block-editor notice (§7.2.1). | — | `Notices::render()`; `Block_Editor_Notice::render()`. |
| `post_updated_messages` | server | Replaces "Product published." after a blocked publish, which would otherwise be an outright lie. | messages array | Swap in "Product saved as a draft — publishing was blocked." |
| `redirect_post_location` | server | Adds `sit_wcpg_blocked=1` so the notice trigger survives the post-save redirect deterministically. | `$location, $post_id` | Append the arg when a block occurred in this request. |
| `rest_api_init` | server | Register `sit-wcpg/v1`. | — | `Validate_Controller::register_routes()`. |

### 6.3 Publishing enforcement (the critical section)

**Requirement (general-plan §15.11):** a disabled button is not enforcement. Every path that can set a product to `publish` must be covered.

#### 6.3.1 Layer A — `wp_insert_post_data` (primary gate)

* **Hook:** `add_filter( 'wp_insert_post_data', …, 10, 2 )` → `( array $data, array $postarr )`.
* **Why here:** it is the last filter before the post row is written, and it runs for `wp_insert_post()` **and** `wp_update_post()`. That single point covers the classic editor, Quick Edit (`inline-save`), Bulk Edit and most programmatic writes — **before** anything is persisted, so nothing has to be undone.
* **Skip matrix** (return `$data` untouched if any is true): `$data['post_type'] !== 'product'`; `$data['post_status']` not in `['publish','future']`; the stored status is already `publish`; `defined( 'DOING_AUTOSAVE' )`; `wp_is_post_revision()` / `wp_is_post_autosave()`; settings `enabled` off; `publishing.block_on_required_failure` off; the enforcement scope excludes this request (§6.3.3); `Publish_Guard::can_override()`.
* **Validation input:** `Save_Request_Reader::read( $_POST, $data )` → overrides; `wc_get_product( $postarr['ID'] )` → stored product (may be `null` for a brand-new product); `Product_Context::from_save_request()`.
  **Important:** `$data` and `$postarr` arrive **slashed** — `wp_unslash()` before measuring or comparing anything. *Verified: `wp-includes/post.php:4973` — "sanitized (and slashed) but otherwise unmodified post data".*
* **Verified ordering fact (`verification-notes.md` §9):** WooCommerce applies Quick Edit and Bulk Edit product values on **`save_post` priority 10**, i.e. *after* this filter. So (a) Layer A must take price/SKU/stock from `$_POST`, never from `wc_get_product()`, which is still stale here — which is exactly what `Save_Request_Reader` does; and (b) when Layer A downgrades the status, WooCommerce still applies the field edits afterwards, so the merchant keeps their data and only the publish is refused. This is the desired behaviour and needs an integration test that asserts it.
* **Absent status is not a publish attempt.** Bulk Edit's `_status = -1` makes core `unset( $post_data['post_status'] )` entirely, and `wp_insert_post()` then carries the existing status forward. The skip matrix must treat a missing/empty `post_status` as "no transition requested" — never as an implicit publish.
* **On required failures:** set `$data['post_status']` to the stored status when it was `draft`/`pending`, otherwise `'draft'`; record the `Validation_Result` on the guard instance; return `$data`.
* **Feedback (R5):** `Notices::queue()` writes a 60-second **per-user** transient `sit_wcpg_blocked_{user_id}` holding one entry per post id (kind `blocked` or `override`, plus only rule ids/labels/statuses); `redirect_post_location` adds `sit_wcpg_blocked=1`; `admin_notices` renders and deletes the entries it showed — on the product editor only that product's entry, on the products list every entry. *Amended in Phase 8:* the plan's `…_{user_id}_{post_id}` key cannot be found again after a Quick Edit or Bulk Edit (neither redirects with a post id), and manual row 18 needs **one** notice naming several products; a per-user key does both without enumerating transients. *Amended in Phase 10:* the products-list notice names each product by id (`Product #12`), never by title — §9.5 forbids product-supplied text in any notice.
* **The reader's input is Layer A's own `$postarr` (amended in Phase 8), not `$_POST`.** Verified: `edit_post()` (classic editor and Quick Edit) passes the whole form to `wp_update_post()` minus `meta_input` / `file` / `guid` (`_wp_get_allowed_postdata()`, `wp-admin/includes/post.php:240`), and `bulk_edit_posts( $_REQUEST )` passes the shared request per post with `tax_input` **already merged** with the post's current terms (`wp-admin/includes/post.php`, `bulk_edit_posts()`). So the plugin reads no superglobal at all, and a nested `wp_update_post()` for another product carries none of this form's fields.
* **Per-source field whitelists (amended in Phase 8).** `Save_Request_Reader` reads a form field only when (a) the payload targets the post being written (`post_ID`, or `post[]` for Bulk Edit — another plugin's `wp_update_post()` of a *different* product inside the same request must not borrow this form's fields) and (b) that save path actually persists the field. Core-applied fields — `_thumbnail_id` (`wp_insert_post()`, `post.php:5135`) and `tax_input` (only where the user can `assign_terms`, `post.php:5090`) — are read for every source. WooCommerce-applied fields are read only when the WooCommerce nonce WooCommerce itself checks verifies (`woocommerce_meta_nonce` / `woocommerce_save_data` for the classic editor, `woocommerce_quick_edit_nonce` for Quick and Bulk Edit); otherwise WooCommerce would ignore them, and believing them would be a bypass. Classic editor: every field. Quick Edit: `_sku`, `_regular_price`, `_sale_price`, the sale dates, `_stock_status`, `_manage_stock` (checkbox: absent = off), `_stock`, `tax_input`. Bulk Edit: `_stock_status` / `_manage_stock` when non-empty, prices only with modifier `1` ("set to") and a non-empty value, `_stock` only in set mode, and `tax_input` as core has already merged it. Anything else falls back to the stored product. Title, description and short description come from `$data` (unslashed), which `wp_update_post()` has already merged with the stored row — so a Quick Edit with no content field is read as the stored description, never as empty. Documented limitation: Bulk Edit's relative price modes (`2`/`3`) are not modelled, so a product with no price that Bulk Edit would *raise* from zero is judged on its stored (empty) price.
* **`detect_source( array $post_data, int $post_id = 0 )`** returns `classic` / `quick_edit` / `bulk_edit` for an admin payload that targets `$post_id`, `rest` inside a REST request, otherwise `programmatic`. *Amended:* the plan's zero-argument signature cannot tell whose payload it is looking at.

#### 6.3.2 Layer B — `woocommerce_before_product_object_save` (CRUD gate)

* **Hook:** `add_action( 'woocommerce_before_product_object_save', …, 10, 2 )` → `( WC_Product $product, $data_store )`.
* **Why also here:** WooCommerce CRUD writes (REST `/wc/v3/products`, WP-CLI, importers, `$product->save()` from other plugins) set props on the object and write the post row afterwards; at Layer A those props are invisible. At this hook the object holds the **intended final state**.
* **Action:** when `$product->get_status()` is `publish`/`future`, the stored status is not already `publish`, the skip matrix passes, and the object's context yields required failures → `$product->set_status( 'draft' )` and queue a notice if a user context exists.
* **Terms at this hook (amended in Phase 8, `verification-notes.md` *Extra findings*).** Terms are written by the data store *after* this hook, so `get_the_terms()` is stale here. The context is `from_product_with_overrides()`: `category_ids` / `tag_ids` come from the object when they are in `$product->get_changes()` or the product is new, and an empty category list on a create takes `default_product_cat`, because `WC_Product_Data_Store_CPT::update_terms()` assigns it (`class-wc-product-data-store-cpt.php:971`). Everything else is read from the object's getters.
* **Verified (`verification-notes.md` §1):** the action fires in `WC_Product::save()` (`abstract-wc-product.php:1559`) **after** `validate_props()` and **before** the data store write, and core's own docblock sanctions adjusting props there — so `set_status()` at this point is supported, not a hack. Because `validate_props()` has already run, stock status is post-normalization here; read stock from the object, never from POST, in this layer.
* **Re-entrancy:** a static in-progress flag keyed by product id (`0` for a product being created) is set for **every** product CRUD save and cleared on `woocommerce_after_product_object_save`. Layer A skips while it is set: the data store writes the post row *before* the meta, so Layer A would otherwise judge a CRUD write that Layer B just passed against stale meta. No "already decided" marker outlives the write — a later `$product->save()` in the same request is judged again.

#### 6.3.3 Enforcement scope (R6)

Setting `publishing.enforce_scope`:

* `editor` — only requests where `Save_Request_Reader::detect_source()` recognises an admin save payload (classic editor / quick edit / bulk edit).
* `authenticated` (**default**) — any request with `get_current_user_id() > 0`, which additionally covers REST and admin-triggered CRUD.
* **Always excluded:** `wp_doing_cron()` (Layer C handles that case), `defined( 'WP_CLI' )`, and unauthenticated contexts — so imports and system jobs are never silently demoted.
* Filter `sit_wcpg_should_enforce( bool $enforce, Product_Context $context, string $source )` for integrators, documented in `README.md`.

**Override:** `Publish_Guard::can_override( $user_id )` returns `true` only when `publishing.allow_admin_override` is on **and** `user_can( $user_id, 'manage_woocommerce' )`. Filterable via `sit_wcpg_can_override_publish_guard`. **No custom capability is registered and no role is modified** — nothing to clean up on uninstall.

#### 6.3.4 Layer C — scheduled publishes (backstop)

`wp_publish_post()` (used by the `publish_future_post` cron) writes the status with a direct `$wpdb->update()` and therefore **never** reaches `wp_insert_post_data`. *Verified: `wp-includes/post.php:5446` — raw `$wpdb->update( $wpdb->posts, … )`, followed by `wp_transition_post_status()`, which is what makes `future_to_publish` fire and the backstop possible.* Two measures:

1. Layer A treats `future` exactly like `publish`, so a product that fails required checks **cannot be scheduled** in the first place. This is the real fix and covers the normal case.
2. Backstop: `add_action( 'future_to_publish', …, 5 )` — if the product now fails required checks, revert it to `draft` with `wp_update_post()`, guarded by a static re-entrancy flag, and queue a notice for the post author. The cron and WP-CLI exclusions of §6.3.3 do **not** apply here (cron is this layer's whole case); the `sit_wcpg_should_enforce` filter does, with source `scheduled`, and so does `can_override()` for the **author**. The backstop stands down when Layer A already judged this same write in this request (a `future` → `publish` edit made through `wp_insert_post()`), so an allowed or overridden edit is never reverted behind the user's back.

**Documented limitation:** in case 2 the product is technically published for the duration of that request. Acceptable because measure 1 makes it rare — it requires the product to have been broken *after* it was scheduled.

#### 6.3.5 Client-side assistance (UX only, never enforcement)

When required failures exist, the React panel shows an inline warning and, on `submit` of `#post` via the Publish button, a confirmation prompt. (Implementation note, Phase 8: the prompt listens for `click` on `#publish` in the **capture** phase on `#post`, so a cancel stops the event before core's `post.js` click handler disables the submit buttons. Neither the warning nor the prompt appears for a product whose saved status is already `publish` — the payload carries `postStatus` for that — nor when `blocksPublishing` is off.) **It never disables the Publish button** — hiding the real server-side outcome is exactly the anti-pattern general-plan §15.11 warns about. The server decision is always authoritative and always produces a notice.

### 6.4 Products already published (decision A4)

Enforcement applies to transitions **into** `publish`/`future`. A live product that later fails a rule is surfaced in the checklist and the list column but is **never** auto-demoted. Auto-unpublishing live products is destructive, is not requested anywhere in general-plan, and would make a settings change capable of taking a whole catalogue offline.
