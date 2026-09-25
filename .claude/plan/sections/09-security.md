<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 9. Security plan

Each item names the exact location and the exact mechanism.

### 9.1 Capability checks

| Surface | Capability | Where |
|---|---|---|
| Settings menu item | `manage_woocommerce` | `add_submenu_page()` `$capability` argument |
| Settings page render | `manage_woocommerce` | an explicit `current_user_can()` guard at the top of `render_page()` — the menu capability alone does not protect direct `admin.php?page=` access in every scenario |
| Settings save | `manage_woocommerce` | the `option_page_capability_sit_wcpg_settings` filter, enforced by core `options.php` **before** the sanitize callback runs |
| REST validate | `current_user_can( 'edit_post', $product_id )` | `permission_callback` |
| Meta box render | `current_user_can( 'edit_post', $post->ID )` | guard in `render()` — cheap and explicit |
| List column | `current_user_can( 'edit_products' )` | column registration and cell render |
| Publish override | `manage_woocommerce` **and** the `allow_admin_override` setting | `Publish_Guard::can_override()` |

`manage_options` is deliberately **not** used: shop managers must be able to configure a WooCommerce plugin. `edit_post` (a meta capability) is used rather than `edit_products` for per-product actions so per-post ownership and `edit_others_products` are respected — a contributor-like role must not validate products it cannot edit.

### 9.2 Nonces / CSRF

| Action | Nonce | Why it is required |
|---|---|---|
| Settings form | `settings_fields( 'sit_wcpg_settings' )` emits `_wpnonce` for `sit_wcpg_settings-options`; core `options.php` calls `check_admin_referer()` | Without it, an attacker page could make a logged-in shop manager disable publish blocking through a forged POST — a genuinely privilege-relevant state change (general-plan §15.10). |
| REST validate | `X-WP-Nonce` (`wp_rest`), added automatically by `@wordpress/api-fetch`'s nonce middleware and verified by core cookie authentication | Cookie-authenticated REST requests are CSRF-able without it. Core rejects the request before our `permission_callback` runs, but the capability check remains the authorization decision — the nonce authenticates the *request origin*, the capability authorizes the *action*. Both are required; neither substitutes for the other. |
| Publish blocking | inherits WordPress's own `post.php` nonce | We add no new state-changing endpoint here. |

The plugin registers **no `admin-ajax.php` handlers** and **no `admin_post_` handlers** — one less attack surface. If a future version adds AJAX, it must use `check_ajax_referer( $action, false, true )` **plus** a capability check and must never rely on `is_admin()`.

### 9.3 Input sanitization

| Input | Handling |
|---|---|
| Settings POST | `Settings_Sanitizer::sanitize()`, registered as `register_setting`'s `sanitize_callback` — which also hooks `sanitize_option_sit_wcpg_settings`, so it runs for **every** `update_option` on that key, not only form posts. Whitelist-based: the output array is **constructed from defaults**, never a filtered copy of the input, so unknown keys cannot survive. |
| REST draft payload | A per-field `args` schema: `type`, `sanitize_callback`, `validate_callback`. IDs → `absint`; ID arrays → `wp_parse_id_list` + `array_slice` caps (categories 100, tags 200, gallery 100) to bound work; `product_type` → whitelist against `wc_get_product_types()` keys; `stock_status` → whitelist; prices → string, normalized then numerically validated; `title`/`content`/`excerpt` → accepted as raw strings and **immediately reduced to a length integer, never stored, echoed or logged**. |
| Form data at save time | Read only inside `Save_Request_Reader`, and only from the `$postarr` core hands `wp_insert_post_data` (amended in Phase 8, §6.3.1) — no superglobal is read. Always `wp_unslash()` then field-appropriate sanitizers (`sanitize_text_field`, `absint`, `wp_parse_id_list`). WooCommerce fields are believed only when WooCommerce's own nonce verifies. The reader never writes anything. |
| `$_GET['sit_wcpg_blocked']` | Existence check only; never echoed. |
| Product data from the database | Treated as untrusted — it may contain merchant- or import-authored HTML. See 9.4 / 9.5. |

### 9.4 Output escaping

* PHP: `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for links, `esc_html__()` / `esc_attr__()` for translated literals. No `echo` of an unescaped variable anywhere — enforced by `WordPress.Security.EscapeOutput` in PHPCS with **no** inline exclusions permitted.
* The bootstrap payload is emitted with `wp_json_encode()` inside `wp_add_inline_script()`, never concatenated into a `<script>` block.
* React: text is rendered as children and auto-escaped. **`dangerouslySetInnerHTML` is banned**; ESLint `react/no-danger: error` enforces it mechanically.
* The list column renders only plugin-authored translated strings and integers.

### 9.5 Stored XSS — structural mitigation

**Hard rule: no rule message, `data` payload, notice, or column value may contain product-supplied text.** Messages are translated literals with integer-only `sprintf` placeholders (§5.6).

Consequence: a product title containing `<script>` can never reach the checklist UI, the admin notice, or the JSON payload. This removes the entire stored-XSS class (general-plan §15.7) **by design** rather than by escaping discipline. Reviewers must reject any rule that interpolates `get_title()`, `get_sku()`, category names, etc. into a message.

### 9.6 SQL injection

No `$wpdb` usage anywhere in V1. All data access goes through `wc_get_product()`, `WC_Product` getters, `get_the_terms()`, `wp_attachment_is_image()`, `get_post_meta()` and the options API. A future query must use `WP_Query` / `wc_get_products()`, or `$wpdb->prepare()` with no exceptions. PHPCS `WordPress.DB` sniffs are enabled and must be clean.

### 9.7 Privilege escalation & unauthorized settings changes

* Settings are global and gated on `manage_woocommerce` + nonce. A staff member with only `edit_products` cannot change rules, thresholds or enforcement.
* There is no per-product settings override in V1, so there is nothing a product editor can flip to weaken enforcement.
* The override path requires `manage_woocommerce` **and** an explicit global opt-in; it is off by default, so out of the box **no one** can publish past a required failure through the UI.
* No roles or capabilities are added or modified; nothing is left behind or abusable after deactivation.

### 9.8 Unauthorized publishing (bypass resistance)

| Bypass attempt | Covered by |
|---|---|
| Re-enable the Publish button in devtools | Layer A — button state is irrelevant, the server decides |
| Quick Edit → status Published | Layer A (`inline-save` goes through `wp_update_post`) |
| Bulk Edit → status Published | Layer A |
| `POST /wp-json/wc/v3/products` with `status=publish` | Layer B (and Layer A for the post row) |
| `wp_update_post()` from another plugin | Layer A |
| `$product->set_status( 'publish' ); $product->save();` | Layer B |
| Schedule the product for a future date | Layer A (treats `future` as publish intent) |
| Product broken after scheduling, cron publishes it | Layer C backstop |
| Crafted REST body to the plugin's own endpoint | The endpoint is read-only — it computes and returns a result and **writes nothing**, so it cannot be used to change state at all |

### 9.9 Malicious / hostile product data

* Enormous descriptions: length is computed with `mb_strlen` after `wp_strip_all_tags`; the only regex applied to untrusted content is a single `\s+` collapse, with no backtracking risk.
* Deeply nested or huge arrays in the REST payload: array length caps (§9.3) are applied **before** any per-item work.
* Attachment IDs pointing at other users' attachments: rules only ask "does this id resolve to an image attachment?" — no attachment metadata, filename or URL is returned, so the endpoint cannot be used as an information oracle beyond what the user already sees in the media modal of a product they can edit.
* Shortcodes in descriptions are **stripped, never executed** (`strip_shortcodes`, never `do_shortcode`).

### 9.10 REST endpoint hardening summary

`permission_callback` is `Validate_Controller::permissions_check()` — never `__return_true`. It (1) resolves `$request['id']` with `absint()`, (2) `wc_get_product()` → `WP_Error( 'sit_wcpg_not_found', 404 )` when false, (3) `current_user_can( 'edit_post', $id )` → `WP_Error( 'sit_wcpg_forbidden', rest_authorization_required_code() )`. The route performs no writes, fires no state-changing hooks, and returns no product content.
