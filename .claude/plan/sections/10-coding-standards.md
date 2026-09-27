<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 10. Coding standards

### 10.1 PHP

* **WordPress Coding Standards** (`WordPress` + `WordPress-Docs` + `WordPress-Extra`) via `phpcs.xml.dist`; CI-blocking. Excluded sniffs, each justified in the ruleset: `WordPress.Files.FileName` (PSR-4 layout), `Universal.Files.SeparateFunctionsFromOO` (single-class files).
* `declare( strict_types=1 )` is **not** used — it interacts badly with WordPress core passing loose types into filter callbacks. Use parameter and return type declarations instead (PHP 8.0-compatible only).
* Every file starts with `defined( 'ABSPATH' ) || exit;`.
* Naming: classes `Snake_Case` with an initial capital per word (`Rule_Registry`); methods/functions/variables `snake_case`; constants `UPPER_SNAKE`; hooks `sit_wcpg_snake_case`; option/transient keys `sit_wcpg_*`.
* Namespaces: `ProductPublishGuard\<Concern>`. Exactly one class per file.
* **Zero global variables and zero global functions**, with one exception: the procedural `sit_wcpg_bootstrap()` in the main file.
* DocBlocks on every class and public method: summary, `@param`, `@return`, `@since` (`1.0.0` for everything in V1).
* Comments explain **why**, not what. No commented-out code and no TODOs in a release build.
* Class-responsibility rule: if a class needs a new collaborator, pass it in the constructor. If a class exceeds ~200 lines or has two reasons to change, split it — **except** that splitting must not produce a file containing a single one-line method (constraint 10).
* Error handling: return `WP_Error` from REST paths; return safe defaults (empty context values, `skipped` results) from engine paths. **The checklist must never fatal an admin screen.** `Editor_Meta_Box::render()`, the list column cell, `Validator` (per rule) and `Publish_Guard` each wrap their engine call in `try/catch ( \Throwable $e )`, log via `error_log()` when `WP_DEBUG` is on, and degrade gracefully. **The guard blocks only on genuine required failures and fails open on an internal exception** — an internal bug must never stop a merchant from publishing.

### 10.2 i18n

* Text domain `product-publish-guard` on every user-facing string, always as a **literal** (never a variable or constant) so scanners can find it.
* No `load_plugin_textdomain()` call — just-in-time loading covers WordPress.org-hosted plugins, and Plugin Check warns on it.
* `_n()` for anything countable ("2 warnings"); `_x()` where a string is ambiguous; a translator comment (`/* translators: %d is the character count. */`) immediately above every placeholder string.
* `wp_set_script_translations( 'sit-wcpg-editor', 'product-publish-guard', SIT_WCPG_PATH . 'languages' )`.
* Strings that reach the browser are translated **server-side** where they are data (rule labels, messages, summary label) and **client-side** with `@wordpress/i18n` where they are UI chrome (buttons, loading/error text). No string is translated in both places.
* `languages/product-publish-guard.pot` generated with `wp i18n make-pot . languages/product-publish-guard.pot`.

### 10.3 JavaScript / React

* `@wordpress/eslint-plugin` recommended config; Prettier via `wp-scripts format`. Both CI-blocking.
* Function components and hooks only; no class components. Named exports for components (greppable refactors); the entry file is the only default-export file.
* One component per file, named after the file. Props destructured in the signature. No `PropTypes` (the payload is typed at the PHP boundary); JSDoc `@param` blocks on non-obvious components instead.
* No direct DOM writes from components — DOM interaction lives in `field-watcher.js`, `snapshot.js` and `utils/focusField.js`.
* No `dangerouslySetInnerHTML` (ESLint `react/no-danger: error`).
* Use `@wordpress/components` (`Card`, `CardBody`, `Spinner`, `Notice`, `Button`, `VisuallyHidden`) rather than hand-rolled equivalents; `@wordpress/api-fetch` rather than `fetch`/`axios`; `@wordpress/i18n` rather than a custom helper.
* jQuery is allowed **only** in `field-watcher.js`, because WooCommerce's product data panels emit jQuery-only custom events that cannot be observed otherwise. It is declared as a script dependency there and used nowhere else.

### 10.4 CSS

* BEM with the `sit-wcpg-` prefix: `.sit-wcpg-checklist`, `.sit-wcpg-checklist__item`, `.sit-wcpg-checklist__item--fail`, `.sit-wcpg-readiness`, `.sit-wcpg-readiness--warning`. Generic class names (`.container`, `.title`, `.button`, `.wrapper`) are forbidden.
* Use WordPress admin colour variables where available so the panel matches both admin colour schemes; do not hard-code a palette beyond the three status colours, which must also be distinguishable without colour (icon + text).
* SCSS compiled by `wp-scripts`; exactly two output files (`editor.css`, `admin.css`).

### 10.5 WooCommerce / WordPress API usage

| Need | Use | Never |
|---|---|---|
| Load a product | `wc_get_product()` | `get_post()` + manual meta reads |
| Product fields | `WC_Product` getters | `get_post_meta( '_regular_price' )` |
| Terms | `get_the_terms()` / `wp_get_object_terms()` | `$wpdb` term joins |
| Product types | `wc_get_product_types()` | hard-coded arrays |
| Stock statuses | `wc_get_product_stock_status_options()` | hard-coded arrays |
| Prices/decimals | `wc_format_decimal()` | `floatval()` on raw input |
| Attachment checks | `wp_attachment_is_image()` | file-path inspection |
| HTTP responses | `rest_ensure_response()`, `WP_Error` | `wp_send_json` + `die` |
| Options | `get_option` / `update_option` | direct SQL |

`get_post_meta()` is acceptable **only** inside `Product_Context::from_save_request()` fallbacks where no `WC_Product` exists yet, and must carry a comment saying so.

### 10.6 Naming contract

One prefix, two spellings; which one depends on where the name lives. Every string that WordPress, WooCommerce or the browser stores **globally** (outside our PHP namespace) carries the prefix, and each such string is defined **once**, as a class constant, and referenced from there — derived names (e.g. `Admin\Screen::SETTINGS_HOOK`, the payload's `restPath`) are built from those constants, never retyped.

| Context | Form | Example |
|---|---|---|
| PHP constants | `SIT_WCPG_` | `SIT_WCPG_VERSION` |
| Global functions, global variables | `sit_wcpg_` | `sit_wcpg_bootstrap()`, `$sit_wcpg_user_ids` |
| Hooks (actions / filters) | `sit_wcpg_` | `sit_wcpg_register_rules` |
| Options, transients, post meta | `sit_wcpg_` (hidden meta: `_sit_wcpg_`) | `sit_wcpg_settings` |
| Object-cache group | `sit_wcpg` | `'sit_wcpg'` |
| REST namespace | `sit-wcpg/v1` | `/wp-json/sit-wcpg/v1/products/12/validate` |
| REST / `WP_Error` / settings-error codes | `sit_wcpg_` | `sit_wcpg_forbidden` |
| Meta box id, list-column key | `sit_wcpg_` | `sit_wcpg_product_checklist` |
| Script & style handles, admin page slugs, HTML ids | `sit-wcpg-` | `sit-wcpg-editor`, `sit-wcpg-settings` |
| CSS classes (BEM) | `sit-wcpg-block__element--modifier` | `.sit-wcpg-checklist__item--fail` |
| JS globals | `sitWcpg` + PascalCase | `window.sitWcpgEditorData` |
| Test / audit IDs in docs | `SIT-WCPG-` | `SIT-WCPG-REST-1` |
| PHP namespace | **unchanged** `ProductPublishGuard\<Concern>` | `ProductPublishGuard\Engine\Validator` |
| Text domain, slug, main file | **unchanged** `product-publish-guard` | `__( '…', 'product-publish-guard' )` |

Enforcement: PHPCS `PrefixAllGlobals` (prefixes `sit_wcpg`, `SIT_WCPG`, `ProductPublishGuard`); `composer lint:prefix` (`bin/check-prefix.php`) fails on any leftover of the retired prefix; `.stylelintrc.json` requires every class to be `sit-wcpg-` BEM; `tests/Unit/Naming_Contract_Test.php` asserts the constants above and their links.
