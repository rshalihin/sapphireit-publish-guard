<!-- Phase 10 deliverable. Maps every §9 control (sections/09-security.md) to the file and line implementing it. -->

# Security audit — Product Publish Guard V1 (Phase 10)

**Date:** 2026-09-25 · **Branch:** `chore/prefix-sit-wcpg` · **Method:** verify, do not assume.
Every `echo`/`printf`, every superglobal read, every route/handler and every §9 control was
checked in the source. Line numbers are as of this audit.

## 1. Findings fixed in this phase

| ID | Finding | Control | Fix |
|---|---|---|---|
| SIT-WCPG-SEC-1 | `Product_List_Column::render_column()` pre-built HTML in `$content` and printed it under an inline `phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`. The output was in fact escaped, but §9.4 permits **no** inline EscapeOutput exclusion. | §9.4 | Each part is now escaped where it is printed (`src/Admin/Product_List_Column.php:284-297`); the exclusion is gone. |
| SIT-WCPG-SEC-2 | The products-list admin notice printed each blocked product's **stored title** (escaped). §9.5 forbids product-supplied text in any notice. | §9.5 | `Notices::product_name()` now returns `Product #<id>` only (`src/Admin/Notices.php:353-358`). §6.3 amended. |
| SIT-WCPG-SEC-3 | The list-column cell relied on the column having been registered; §9.1 asks for the `edit_products` check at registration **and** cell render. | §9.1 | Explicit guard in `render_column()` (`src/Admin/Product_List_Column.php:229`). |

## 2. §9.1 Capability checks

| Surface | Capability | Implemented at |
|---|---|---|
| Settings menu item | `manage_woocommerce` | `src/Settings/Settings_Page.php:117` (`add_submenu_page` `$capability`, `Settings_Page::CAPABILITY`) |
| Settings page render | `manage_woocommerce` | `src/Settings/Settings_Page.php:172-174` (`current_user_can()` + `wp_die()`) |
| Settings save | `manage_woocommerce` | `src/Settings/Settings_Page.php:102` (filter registered), `:156` (returns `CAPABILITY`) |
| REST validate | `edit_post` on the product | `src/Rest/Validate_Controller.php:176` (`permission_callback`), `:263-279` (`permissions_check()`) |
| Meta box render | `edit_post` on the post | `src/Admin/Editor_Meta_Box.php:137` (registration), `:160` (render) |
| List column | `edit_products` | `src/Admin/Product_List_Column.php:308` (`is_visible()`, used by registration at `:134` and priming at `:182`), `:229` (cell); edit link only with `edit_post` at `:279` |
| Publish override | `manage_woocommerce` **and** `allow_admin_override` | `src/Publishing/Publish_Guard.php:317-330` (`can_override()`) |
| Client `canOverride` flag | advisory only | `src/Admin/Assets.php:205`; the server-side authority is `can_override()` |
| Requirements notice | `activate_plugins` | `src/Compat/Requirements.php:153` |

`manage_options` is not used anywhere. The only other `current_user_can()` call,
`src/Engine/Save_Request_Reader.php:455`, checks the taxonomy's `assign_terms` before
believing submitted term ids.

## 3. §9.2 Nonces / CSRF

| Action | Mechanism | Implemented at |
|---|---|---|
| Settings form | `settings_fields( 'sit_wcpg_settings' )` emits `_wpnonce` for `sit_wcpg_settings-options`; core `options.php` calls `check_admin_referer()` | `src/Settings/Settings_Page.php:183-185` (form posts to `options.php`) |
| REST validate | `X-WP-Nonce` via `@wordpress/api-fetch` middleware; core `rest_cookie_check_errors()` | `assets/js/editor/api.js:8,79` (`apiFetch`, no raw `fetch`); route registered with core's standard auth at `src/Rest/Validate_Controller.php:169-180` |
| Publish blocking | core `post.php` nonce | no new endpoint; WooCommerce fields are believed only when WooCommerce's own nonce verifies: `src/Engine/Save_Request_Reader.php:186` (`woocommerce_meta_nonce`), `:193` (`woocommerce_quick_edit_nonce`), `:251-256` (`nonce_verifies()`) |

No `admin-ajax.php`, `wp_ajax_*` or `admin_post_*` handler exists (enforced by
`tests/Unit/Security_Invariants_Test.php`).

## 4. §9.3 Input sanitization

| Input | Handling | Implemented at |
|---|---|---|
| Settings POST | `Settings_Sanitizer::sanitize()` as `register_setting` `sanitize_callback` (also covers direct `update_option`); output built from defaults; `version` forced | `src/Settings/Settings_Page.php:131-139`; `src/Settings/Settings_Sanitizer.php:60-69` (built from `get_defaults()`, `version` = `Settings::VERSION`), `:93` rules whitelist, `:124-136` thresholds `absint` + clamp, `:158-166` scope enum |
| REST `id` | `absint` + positive-integer validate | `src/Rest/Validate_Controller.php:196-204`, `:264`, `:290` |
| REST draft | whitelist `DRAFT_FIELDS`, `additionalProperties: false`, own `validate_callback`/`sanitize_callback` | `src/Rest/Validate_Controller.php:69-95` (fields + caps 100/100/200), `:205-214`, `:331-394` (`validate_draft()`), `:408` (`sanitize_draft()`) |
| REST ID arrays | capped with `array_slice` **before** per-item work | `src/Rest/Validate_Controller.php:462`, `:491` |
| REST `product_type` / `stock_status` | whitelist against `wc_get_product_types()` / `wc_get_product_stock_status_options()` → 400 | `src/Rest/Validate_Controller.php:378-384`, `:572-586` |
| REST prices | `wc_format_decimal()` + numeric validate | `src/Rest/Validate_Controller.php:388-391`, `:525` |
| REST `title`/`content`/`excerpt` | kept raw, used only for a character count; never stored, echoed or logged | `src/Rest/Validate_Controller.php:437-444` |
| Form data at save | read only from core's `$postarr`, `wp_unslash()` first, then `sanitize_key` / `sanitize_text_field` / `absint` / id lists | `src/Engine/Save_Request_Reader.php:168-169` (unslash), `:347`, `:375`, `:388`, `:395`, `:434-437`, `:479` |
| `$_GET['sit_wcpg_blocked']` | existence check only, never read or echoed | `src/Admin/Notices.php:184` — **the only superglobal read in the plugin** (its `NonceVerification.Recommended` exclusion at `:183` is justified: nothing is read or changed) |

## 5. §9.4 Output escaping

Every `echo`/`printf` in `src/` was audited; each dynamic value is escaped for its context.

| File | Lines | Escaping |
|---|---|---|
| `src/Admin/Editor_Meta_Box.php` | 166-176, 208-349 | `esc_attr` on ids/classes, `esc_html` / `esc_html__` on all text |
| `src/Admin/Product_List_Column.php` | 284-297, 352-353 | `esc_attr` (status class), `esc_url` (edit link), `esc_html` (glyph, label) |
| `src/Admin/Notices.php` | 189, 296-319 | `esc_attr` (classes), `esc_html` (heading, labels, product reference) |
| `src/Settings/Settings_Page.php` | 173-462 | `esc_html__` for literals, `esc_attr` for names/ids/values, `%d` + `(int)` for numbers, `checked()` |
| `src/Compat/Requirements.php` | 157-165 | `esc_html__` / `esc_html` |
| Bootstrap payload | `src/Admin/Assets.php:170-172` | `wp_json_encode()` inside `wp_add_inline_script()` |
| React | `assets/js/editor/**` | children only; `react/no-danger: error` at `eslint.config.cjs:25` |

PHPCS: `WordPress.Security` at error severity (`phpcs.xml.dist:41-43`). **No inline
EscapeOutput exclusion remains** (SIT-WCPG-SEC-1), locked by
`Security_Invariants_Test::test_output_escaping_is_never_excluded`.

## 6. §9.5 Stored XSS — structural mitigation

| Control | Implemented at |
|---|---|
| Rule messages are translated literals with integer-only placeholders | `src/Rules/*.php`; asserted by `tests/Unit/Rules/Rule_Message_Safety_Test.php` |
| Rule `data` carries numbers/booleans only | `src/Engine/Rule_Result.php:392` (`to_array()`); asserted in `Rest_Validate_Security_Test::test_hostile_product_text_never_reaches_the_response` |
| Notice entries store rule ids/labels/statuses only | `src/Admin/Notices.php:117-121` |
| Notices name products by id, never title | `src/Admin/Notices.php:353-358` (SIT-WCPG-SEC-2) |
| List column prints plugin strings and integers only | `src/Admin/Product_List_Column.php:246-297` |

## 7. §9.6 SQL injection

No `$wpdb` anywhere (`uninstall.php:13` mentions it only in a comment; it enumerates users
via `get_users()` at `:28-38`). `WordPress.DB` at error severity (`phpcs.xml.dist:44-46`).
Locked by `Security_Invariants_Test::test_there_is_no_direct_database_access`.

## 8. §9.7 Privilege escalation & settings changes

* Settings: `manage_woocommerce` + nonce (sections 2–3 above).
* Override off by default and gated: `src/Publishing/Publish_Guard.php:317-330`.
* No `add_role` / `remove_role` / `add_cap` / `remove_cap` in shipped code; uninstall
  removes only `sit_wcpg_settings` and `sit_wcpg_blocked_*` (`uninstall.php:21`, `:37`).
  Locked by `Security_Invariants_Test::test_no_role_or_capability_is_changed` and
  `Publish_Guard_Override_Test::test_no_role_is_modified`.

## 9. §9.8 Unauthorized publishing

| Layer | Hook | Implemented at |
|---|---|---|
| A | `wp_insert_post_data` (classic, Quick/Bulk Edit, `wp_update_post`, `future` as publish intent) | `src/Publishing/Publish_Guard.php:65`, `:174`, `:205` |
| B | `woocommerce_before_product_object_save` (CRUD, `/wc/v3`) | `src/Publishing/Publish_Guard.php:176`, `:239` |
| C | `future_to_publish` backstop | `src/Publishing/Publish_Guard.php:178`, `:294` |
| — | Plugin REST route computes and returns only, writes nothing | `src/Rest/Validate_Controller.php:289-310` |

Fail-open on internal exceptions with `WP_DEBUG`-only logging:
`src/Publishing/Publish_Guard.php:212`, `:253`, `:301`, `:663-669`.

## 10. §9.9 Hostile product data

| Control | Implemented at |
|---|---|
| Length via `strip_shortcodes` → `wp_strip_all_tags` → single `\s+` collapse → `mb_strlen` | `src/Engine/Product_Context.php:318-327` |
| Array caps before per-item work | `src/Rest/Validate_Controller.php:491` |
| Attachment checks answer yes/no only | `Product_Context` via `wp_attachment_is_image()`; no attachment data in any response |
| Shortcodes never executed | no `do_shortcode` / `the_content` anywhere; `Security_Invariants_Test::test_no_shortcode_is_executed`, `Output_Security_Test::test_a_shortcode_in_the_description_is_never_executed` |

## 11. §9.10 REST hardening

`permissions_check()` at `src/Rest/Validate_Controller.php:263-279`: `absint` id (`:264`) →
`sit_wcpg_not_found` 404 (`:267`, `:599-601`) → `edit_post` or `sit_wcpg_forbidden` with
`rest_authorization_required_code()` (`:270-275`). Never `__return_true`.

## 12. Confirmations

| Check | Result | Evidence |
|---|---|---|
| No `$wpdb` | ✔ | grep + `Security_Invariants_Test` |
| No `admin-ajax` / `admin_post_` | ✔ | grep + `Security_Invariants_Test` |
| No `dangerouslySetInnerHTML` or raw-HTML sink in JS | ✔ | grep, ESLint clean, `Security_Invariants_Test` |
| No outbound HTTP (PHP or JS) | ✔ | grep + `Security_Invariants_Test` |
| No role/capability changes | ✔ | grep + `Security_Invariants_Test` |
| Every file starts with `defined( 'ABSPATH' ) \|\| exit;` | ✔ | all `src/*.php`, main file; `uninstall.php` uses `WP_UNINSTALL_PLUGIN` |
| Remaining inline PHPCS exclusions | 5, none security-relevant except the justified `NonceVerification.Recommended` at `src/Admin/Notices.php:183` | `error_log` behind `WP_DEBUG` ×3, core-domain `__( 'Auto Draft' )` ×1 |

## 13. §12.3 security tests — coverage map

| §12.3 row | Test |
|---|---|
| REST logged-out → 401/403, no body | `tests/Security/Rest_Validate_Security_Test.php::test_a_logged_out_request_is_refused` |
| REST subscriber → 403 | `…::test_a_subscriber_is_refused` |
| REST author on another's product → 403 | `…::test_an_editor_without_edit_others_products_is_refused` |
| REST missing/invalid `X-WP-Nonce` | `…::test_core_rejects_a_cookie_request_without_a_valid_rest_nonce` |
| REST 10,000 `category_ids` → capped, 200 | `…::test_an_oversized_id_list_is_capped`, `…::test_the_sanitizer_applies_the_documented_caps` |
| REST `product_type = '<script>'` → 400 | `…::test_an_unknown_product_type_is_rejected` |
| Settings save without a nonce dies | `tests/Security/Settings_Security_Test.php::test_a_save_without_a_nonce_dies`, `…::test_a_save_with_a_forged_nonce_dies` (+ positive control) |
| Settings save as subscriber / `edit_products`-only | `…::test_only_users_who_manage_woocommerce_may_save`, `…::test_the_save_capability_is_manage_woocommerce` |
| Injected rule id + `version` dropped | `…::test_injected_keys_never_reach_the_stored_option` |
| Shop manager publish, override off → blocked | `tests/Security/Publishing_Security_Test.php::test_a_shop_manager_is_blocked_while_the_override_is_off` |
| `/wc/v3/products` as shop manager → blocked | `…::test_a_shop_manager_cannot_publish_a_failing_product_over_the_woocommerce_rest_api` |
| Hostile title: checklist, column, notice, REST | `tests/Security/Output_Security_Test.php` (checklist, list column, list notice, editor notice); REST in `Rest_Validate_Security_Test::test_hostile_product_text_never_reaches_the_response` |
| Shortcode never executed | `Output_Security_Test::test_a_shortcode_in_the_description_is_never_executed`; `tests/Unit/Security_Invariants_Test.php::test_no_shortcode_is_executed` |

## 14. Gate status

* **PHPCS** (security sniffs at error severity) + prefix guard: `composer lint` — **0 errors, 0 warnings**.
* **ESLint** over `assets/js`: clean.
* **§12.3 suite:** written and mapped above; **not run in this phase** (by instruction). Run
  `vendor/bin/phpunit --testsuite security` (and `--testsuite unit` for
  `Security_Invariants_Test`) under wp-env to close the gate.
