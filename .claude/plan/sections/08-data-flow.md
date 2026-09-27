<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 8. Client ⇄ server data flow

### 8.1 First paint (zero requests)

```text
post.php
  └─ Editor_Meta_Box::render()
       ├─ Checklist_Service::validate_post( $id )        → Validation_Result
       ├─ echo '<div id="sit-wcpg-checklist-root">' + escaped no-JS fallback + '</div>'
       └─ Assets: wp_add_inline_script( 'sit-wcpg-editor',
              'window.sitWcpgEditorData = ' . wp_json_encode( [
                  'productId' => int,
                  'result'    => $result->to_array(),
                  'settings'  => [ 'blocksPublishing' => bool, 'canOverride' => bool ],
                  'restPath'  => '/sit-wcpg/v1/products/<id>/validate',
                  'groups'    => [ 'content' => 'Content', … ],   // translated labels
              ] ) . ';', 'before' )
```

The panel is fully rendered before any network activity. This is the single biggest perceived-performance decision.

### 8.2 Live revalidation

```text
DOM change (title / editor / price / sku / stock / terms / images)
   └─ field-watcher → snapshot.js builds a draft object
        └─ signature = stable string of the draft
             ├─ signature === lastSentSignature? → drop (no request)
             └─ else → debounce 800 ms → api.js
                  ├─ abort any in-flight request (AbortController)
                  └─ apiFetch({ path, method:'POST', data:{ draft } })   [X-WP-Nonce added by core middleware]
                       └─ Validate_Controller::validate_item()
                            ├─ permission_callback already passed (edit_post)
                            ├─ args schema sanitized/validated per field
                            ├─ wc_get_product($id)  (404 if not a product)
                            ├─ Product_Context::from_product_with_overrides()
                            ├─ Validator::validate()
                            └─ rest_ensure_response( $result->to_array() )
                       └─ useValidation dispatch( RESOLVED ) → re-render
```

**Draft payload (the only fields sent):** `title, content, excerpt, product_type, regular_price, sale_price, sale_from, sale_to, sku, stock_status, manage_stock, stock_quantity, featured_image_id, gallery_image_ids[], category_ids[], tag_ids[]`. Nothing else is read from the DOM, and nothing is returned to the browser except the validation result.

**Verified DOM contract for `snapshot.js` / `field-watcher.js`** (`verification-notes.md` §14–16; anything marked *confirm in Phase 7* still needs an interactive check):

| Draft field | Source | Notes |
|---|---|---|
| `title` | `#title` | plain input |
| `content` | `tinymce.get('content')` when present and not hidden, else `#content` | the instance is created asynchronously and is `null` in Text mode — resolve it lazily on **every** snapshot, never cache it at mount |
| `excerpt` | `tinymce.get('excerpt')` when present and not hidden, else `#excerpt` | **not** a plain textarea: WooCommerce renders the short description with `wp_editor( …, 'excerpt' )` (`class-wc-meta-box-product-short-description.php`), so in Visual mode the textarea is only synced on save. Same lazy resolution as `content`; the watcher binds both instances. *Amended 2026-09-27 after a live report that short-description edits never reached the checklist.* |
| `product_type` | `#product-type` | also re-snapshot on the `woocommerce-product-type-change` jQuery event — a type change alters which rules apply |
| `regular_price` / `sale_price` | `#_regular_price` / `#_sale_price` | ids confirmed in `html-product-data-general.php` |
| `sku` | `#_sku` | confirmed in `html-product-data-inventory.php` |
| `stock_status` / `manage_stock` / `stock_quantity` | `#_stock_status` / `#_manage_stock` / `#_stock` | confirmed in `html-product-data-inventory.php` |
| `featured_image_id` | `#_thumbnail_id` | **core writes `-1`, not `''`, when there is no image — map any value `<= 0` to `0`** |
| `gallery_image_ids` | `#product_image_gallery` | comma-separated id list |
| `category_ids` / `tag_ids` | `#product_catdiv` checked boxes (both tabs, de-duplicated) / `#tax-input-product_tag` | categories are real term ids. The tag box holds **names** (a new tag has no id yet), so tags are sent by presence: positional placeholders `1..n`, one per distinct name, split on `,` and core's localized `tagsBoxL10n.tagDelimiter`. The server never resolves them — `Tags_Rule` only counts. |
| `sale_from` / `sale_to` | `#_sale_price_dates_from` / `#_sale_price_dates_to` | WooCommerce's "Cancel" link clears both **without an event**; the watcher also listens for clicks on `.cancel_sale_schedule` |

Binding rules that follow from the above:

* Product-data fields are bound by **delegation** on `#woocommerce-product-data` (`change`, `input`). WooCommerce shows and hides panels by class on type change rather than re-rendering them, but delegation is immune either way.
* The featured-image and gallery blocks **are** replaced wholesale by their AJAX responses, destroying `#_thumbnail_id`. Observe the containers `#postimagediv` and `#woocommerce-product-images` with `MutationObserver({ childList: true, subtree: true })` and re-read the hidden input on each mutation. Never observe the input node itself. The same observer covers `#tagsdiv-product_tag .tagchecklist` (core's tag box changes its textarea programmatically) and `#product_catchecklist` (a category added inline arrives already checked, with no event).
* The signature compares text fields the way the server measures them — markup removed, whitespace collapsed — because the server reduces text to a length. A markup-only change (TinyMCE re-wrapping a paragraph on init or focus) therefore costs no request. The baseline signature is taken at mount, so opening a product sends nothing.

**Request-avoidance rules (R3):**

1. 800 ms trailing debounce.
2. Signature dedupe — identical snapshots never produce a request (covers focus/blur churn and TinyMCE's chatty events).
3. A single in-flight request; a newer snapshot aborts the older request.
4. A hard floor of 1500 ms between two completed requests (leading-edge throttle on top of the debounce).
5. Watching pauses on `visibilitychange` → hidden, and stops on form submit.
6. No polling, no interval timers.

**Error handling:** aborts are silent. Real failures set `status: 'error'` while keeping the last good result visible. `401`/`403` (expired nonce or login after a long idle session) → "Your session expired — reload the page to continue checking."; automatic checks then stop, since every further request would fail the same way, while the manual Re-check still works. `5xx` and anything else → the generic retry notice. The client never invents a pass/fail. Every settle action carries its request id and the reducer ignores any but the newest, so an aborted request rejecting late can never undo its successor's `loading` state.

**Manual Re-check** bypasses both the signature dedupe and the 1500 ms floor, and records its snapshot's signature so the debounced path does not send the same draft again.

**No result at first paint** (the meta box shows "will appear once saved"): the app does not mount and the server markup stays; the route would answer `404` for the same product.

**Caching:** none in the browser (the draft changes constantly). Server-side, `Checklist_Service` caches only override-free validations (§11.3), so live editor calls are never cached — they must not be.

### 8.3 Recalculation triggers (complete list)

| Trigger | Mechanism |
|---|---|
| Editor field edited | field-watcher → debounced REST call |
| Manual `Re-check` | immediate REST call, bypasses the dedupe |
| Product saved / page reloaded | fresh server-side render (§8.1) |
| Products list rendered | per-row server-side computation with primed caches |
| Publish attempted | `Publish_Guard` validates from the save request |
| Settings saved | the settings hash changes, invalidating every cached summary |
