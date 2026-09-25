# Product Publish Guard — V1 Coding Plan

**Source of truth:** `.claude/general idea/general-plan.md` (product-level spec).
**This document:** implementation-level plan. It is binding for the coding agent.
**Status:** planning only. **No production code is to be written from this document's authoring task.**

---

## 0. How to use this document

1. Read §1 (scope) and §2 (decisions) before anything else.
2. Implement strictly in the order defined in §15. Do not start a phase before its prerequisites are green.
3. Anything listed in §13 (`V1 — Do Not Implement`) is forbidden, even if it looks easy.
4. Before writing the first line of code, work through §17 (`Implementation Risks / Things to Verify Before Coding`) and record the answers in `.claude/plan/verification-notes.md`. If a verified fact contradicts this plan, update this plan first, then code.
5. Companion file: `.claude/plan/implementation-checklist.md` — a flat, ordered, tickable task list derived from §15.

---

## 1. Scope analysis of `general-plan.md`

### 1.1 Exact V1/MVP scope (derived from §3, §7, §19, §22)

The V1 deliverable is complete when a merchant can do the eight things listed in general-plan §22. Concretely:

| # | V1 capability | Source |
|---|---|---|
| 1 | Checklist panel inside the WooCommerce product editor | §3.1, §19 |
| 2 | Pass / Warning / Fail per rule, with a specific, actionable message | §3.2 |
| 3 | 11 built-in rules (title, description, short description, featured image, image count, price, sale price validity, category, tags, SKU, stock status) | §3.1, §7 |
| 4 | Per-rule enable/disable + per-rule severity (required vs warning) | §3.3, §19 |
| 5 | Content-length thresholds + minimum image count | §3.4 |
| 6 | Readiness summary (`n / m` + ready/not-ready state) | §2.B, §19 |
| 7 | Option: prevent publishing when required checks fail, enforced **server-side** | §3.5, §15.11 |
| 8 | Readiness indicator column in the products list | §3.6 |
| 9 | One settings screen under the WooCommerce menu | §3.7, §19 |
| 10 | Checklist refreshes automatically while editing (no mandatory "Run Check" button) | §4 |

### 1.2 Explicitly deferred (V2+)

Variation QA, attribute/brand/weight/dimension/shipping/tax rules, image dimension & alt-text rules, quality scoring, duplicate title/SKU detection, bulk scanning, scheduled audits, reports, CSV export, email notifications, approval workflow, roles, reviewer notes, activity history, custom rule builder UI, webhooks/API integrations, AI features, SEO features. See §13 for the enforced list.

### 1.3 Ambiguities found, and the resolution adopted

| # | Ambiguity in general-plan | Resolution for V1 |
|---|---|---|
| A1 | §3.3 shows "enable/disable" while §2.C and §19 show "Required" vs "Optional" groups — two different concepts. | Unified into **one model**: each rule has `enabled` (bool) + `severity` (`required` \| `warning`). "Optional" = enabled with severity `warning`. "Disabled" = not evaluated at all. This removes a whole settings dimension without losing expressiveness. |
| A2 | §2.A lists "Description required" and "Minimum description length" as separate rules; §19 shows only one description line. | **One rule per field** that reports two distinct outcomes: empty → fail; below threshold → warning. Avoids two checklist rows for one field and matches the §1 mock (`✓ Product description` + `! Product description too short` are the same field in different states). |
| A3 | §1 mock shows "Readiness: 6 / 8"; §2.B shows "Readiness: 80%"; §13.6 forbids scoring. | Use **`passed / evaluated` counts plus a ready/not-ready state**. No percentage, no score. Percentages read as a score and §13.6 forbids scoring. |
| A4 | §3.5 says "prevent publishing"; §1 says "before publishing **or updating**". | Enforcement applies to **transitions into `publish` or `future`** only. An already-published product that later breaks a rule is flagged (checklist + list column) but **not** auto-demoted — silent unpublishing of live products is destructive and is not requested. Documented in §6.4. |
| A5 | §3.6 product-list indicator vs §13.5 "no custom tables / avoid persistence". | Computed on demand, with request-level memoization and optional object-cache reuse. **No persisted readiness meta in V1.** §11 defines the priming strategy that makes this fast; §14 defines the seam where persistence can be added in V2 if profiling demands it. |
| A6 | §3.7 lists four settings tabs (Checklist / Publishing Rules / Appearance / Advanced) but then says "keep the actual settings minimal". | **One page, three sections** (Rules, Content thresholds, Publishing). No Appearance, no Advanced — there is nothing to put in them in V1. |
| A7 | §17 says "Block-based/current WooCommerce admin UI where relevant" — unclear whether the new WooCommerce product block editor is a target. | **V1 targets the classic product editor only.** *Verified (see `verification-notes.md` §3): WooCommerce 11.1.1 returns `false` from `use_block_editor_for_post_type` for `product` unconditionally (`includes/class-wc-post-types.php:777`) and the `product_block_editor` feature no longer exists, so the classic editor is guaranteed on the installed stack.* The plugin still detects an active block editor defensively (for older WooCommerce) and shows an admin notice explaining the checklist panel is unavailable there; server-side publish enforcement works regardless of editor. Supporting two editor UIs doubles the front-end scope and is not in §3. |
| A8 | §3.1 lists "Sale price validity" but §2.A does not. | Included as a rule, defaulting to severity `warning` and skipped when no sale price is set. It is cheap and is on the §3.1 free list. |
| A9 | §3.6 example implies possible filtering/sorting; §21 Phase 5 says "Filtering/sorting only if simple enough". | **Not implemented.** Sorting/filtering by readiness requires an indexed persisted value, which conflicts with A5/§13.5. Listed in §13. |

### 1.4 Technically risky items identified up front

| Risk | Why | Mitigation in this plan |
|---|---|---|
| R1 | At `wp_insert_post_data` time the product's WooCommerce meta (price, SKU, stock) is **not yet saved** — validating the stored product would use stale data and produce false blocks. | `Product_Context` can be built from the in-flight save request (§5.3, §6.3). |
| R2 | A "live" checklist tempts a duplicate rule implementation in JavaScript, which will drift from PHP. | Rules exist **only in PHP**. The browser sends a draft snapshot; PHP validates. §8. |
| R3 | A naive live checklist fires a request per keystroke. | Debounce + payload signature dedupe + `AbortController`. §8.2. |
| R4 | Products list with 100–200 rows × 11 rules = term/meta/attachment queries per row. | Bulk cache priming on `the_posts` for the list screen. §11.2. |
| R5 | Blocking publish server-side changes `post_status` behind the user's back; without feedback this looks like a bug. | Redirect flag + user transient + `post_updated_messages` override + admin notice. §6.3.4. |
| R6 | Enforcing on every programmatic write can break CSV imports and integrations. | Enforcement scope setting, `wp_doing_cron()`/WP-CLI exclusion, and a documented filter. §6.3.3. |

### 1.5 Assumptions (stated explicitly; verify in §17)

1. The site uses the **classic** WooCommerce product editor (`post.php` / `post-new.php`, `post_type=product`).
2. Only **simple** and **variable** product types are formally supported; other types (grouped, external, and third-party types) are handled gracefully — rules that cannot apply return `skipped` rather than failing.
3. The plugin is distributed as a self-contained zip. **No Composer runtime dependencies**; `vendor/` is dev-only.
4. The target audience is merchants, so the settings screen is plain PHP/HTML (no React) and only the editor panel is a React app.
5. Shop managers (`manage_woocommerce`) are trusted to configure the plugin; only users who can edit a given product may validate it.
6. No custom database tables, no custom post types, no custom taxonomies, one options row.

---

## 2. Binding technical decisions

These are settled. Do not re-litigate them during implementation.

| Key | Decision | Rationale |
|---|---|---|
| Plugin slug / dir | `product-publish-guard` | Matches the existing directory. |
| Main file | `product-publish-guard.php` | WP.org convention: main file matches slug. |
| Text domain | `product-publish-guard` | Must equal the slug for WP.org language packs. |
| Global prefix | `sit_wcpg_` (functions, hooks, options, transients, error codes, meta box id; cache group `sit_wcpg`), `SIT_WCPG_` (constants), `sit-wcpg-` (script/style handles, admin slugs, HTML ids, CSS classes; REST namespace `sit-wcpg/v1`), `sitWcpg…` (JS globals). Exact form per context: §10.6. | Vendor-prefixed, distinctive, collision-safe; each global string is defined once as a class constant. Enforced by PHPCS `PrefixAllGlobals` and `composer lint:prefix`. Changed from `wcpg` to `sit_wcpg` by user decision on 2026-09-25 to add a vendor prefix (`prefix-migration-plan.md`). <!-- prefix-guard: ignore --> |
| PHP namespace root | `ProductPublishGuard\` | One root namespace, sub-namespaces per concern. |
| Min PHP | **8.0** | Raised from 7.4 by user decision on 2026-09-24 (§17.24): PHP 7.4 is not installed in this environment, so a 7.4 claim could not be substantiated. Typed properties, arrow functions, constructor promotion, union types, `match` and named arguments are all available. **Do not use** enums, `readonly` properties, `never` return types or first-class callable syntax (all 8.1+). |
| Min WordPress | **6.5** | First version supporting the `Requires Plugins:` header, which gives us dependency handling for free. |
| Min WooCommerce | **9.0** | Raised from 8.2 by user decision on 2026-09-24 (§17.24). Still HPOS-default era; `wc_get_product` / CRUD APIs used here are long-stable, and 9.x is testable via `wp-env`. |
| Autoloading | Hand-written PSR-4 autoloader in `src/Autoloader.php` | Zero runtime dependencies, no `vendor/` in the zip, no autoloader conflicts with other plugins. Composer is dev-only (PHPCS/PHPUnit). |
| File naming | PSR-4 (`src/Engine/Rule_Registry.php` → `ProductPublishGuard\Engine\Rule_Registry`) with WPCS class names (`Snake_Case`) | Keeps WPCS naming while allowing a trivial autoloader. `WordPress.Files.FileName` is excluded in `phpcs.xml.dist`. |
| Settings storage | **One** autoloaded option, `sit_wcpg_settings` (nested array) | One row, atomic sanitization, trivial version/migration, and a cheap settings hash for cache keys. |
| Settings UI | WP Settings API + `options.php`, with `option_page_capability_sit_wcpg_settings` filtered to `manage_woocommerce` | Free nonce/CSRF handling and a core-verified save path, while still letting shop managers (not just admins) configure it. |
| Editor UI | React app mounted in a classic `add_meta_box` panel (`side`, `high`) | §13.1 forbids replacing the editor; a meta box is the supported, lightweight integration point. |
| Build | `@wordpress/scripts` (wp-scripts), output to `build/` | Standard WP tooling, generates `*.asset.php` dependency/version manifests. |
| React provenance | Core-provided script handles (`wp-element`, `wp-components`, `wp-api-fetch`, `wp-i18n`) | React is **not** bundled; keeps the editor bundle small (§16). |
| Transport | REST (`sit-wcpg/v1`), `POST .../validate` | Carries an unsaved draft snapshot in the body; authenticated admin-only; `@wordpress/api-fetch` handles the `X-WP-Nonce` automatically. |
| Enforcement | Server-side, three layers (`wp_insert_post_data`, `woocommerce_before_product_object_save`, `future_to_publish` backstop) | general-plan §15.11: UI-only blocking is not acceptable. |
| Persistence | None beyond `sit_wcpg_settings` + short-lived transients for admin notices | §13.5 of general-plan. |

---

## 3. Architecture

### 3.1 Component map

```text
                       product-publish-guard.php  (bootstrap)
                                    │  requirements gate
                                    ▼
                            ProductPublishGuard\Plugin        (lazy service locator)
                                    │
        ┌───────────────┬───────────┴───────────┬────────────────────┐
        ▼               ▼                       ▼                    ▼
    Admin\*        Rest\Validate_         Publishing\           Settings\
  (UI surfaces)      Controller           Publish_Guard          Settings
        │               │                       │                    │
        └───────────────┴───────────┬───────────┘                    │
                                    ▼                                │
                       Support\Checklist_Service  ◄──────────────────┘
                            (facade + memoization)
                                    │
                                    ▼
                            Engine\Validator
                                    │
                    ┌───────────────┴───────────────┐
                    ▼                               ▼
          Engine\Rule_Registry            Engine\Product_Context
                    │                     (normalized product data:
                    ▼                      saved  OR  saved+overrides
        Rules\Title_Rule, Price_Rule,       OR  save-request snapshot)
        Featured_Image_Rule, … (11)
                    │
                    ▼
            Engine\Rule_Result  ×N
                    │
                    ▼
          Engine\Validation_Result  (summary + results[])
```

### 3.2 Request-path view (the four entry points)

```text
(1) Product editor first paint
    post.php → Editor_Meta_Box::render()
        → Checklist_Service::validate_post($id)
        → Validation_Result → wp_add_inline_script( sitWcpgEditorData )
        → React hydrates with data already present → 0 HTTP requests

(2) Live re-check while typing
    field-watcher → snapshot → signature changed? → debounce 800ms
        → POST /sit-wcpg/v1/products/{id}/validate  { draft: {...} }
        → Validate_Controller (permission_callback: edit_post)
        → Product_Context::from_product_with_overrides()
        → Validator → Validation_Result (JSON) → React re-render

(3) Save / publish attempt
    wp_insert_post_data (status→publish|future)
        → Publish_Guard::filter_insert_post_data()
        → Product_Context::from_save_request($_POST, $data, $stored_product)
        → Validator → required failures?
             yes → force post_status back to draft/previous
                   + queue user notice + redirect flag
             no  → untouched

(4) Products list row
    the_posts (edit.php?post_type=product) → prime caches for all row IDs
    manage_product_posts_custom_column → Checklist_Service::get_summary_for_post_id()
        → memoized Validation_Result → icon + label (escaped)
```

### 3.3 Why this shape

* **Single source of truth for rules (PHP).** Every entry point funnels into `Validator`. The browser never decides pass/fail; it only renders what PHP returned. This is what makes the live UI and the server-side block impossible to disagree with, and it is what makes general-plan §15.11 (publishing bypass) tractable.
* **`Product_Context` as the only product accessor.** Rules never touch `$_POST`, `$wpdb`, `get_post_meta`, or `WC_Product` directly. Three factories produce a context from three very different data situations (saved product / saved + unsaved overrides / in-flight save request), and every rule works unchanged against all three. Without this, publish-time validation and editor-time validation would need separate rule implementations.
* **Rules are pure.** Because the context exposes only normalized scalars and arrays (with lazy, memoized WP lookups behind it), each rule is a pure function of `(context data, settings)`. That makes §12.1 unit tests runnable without a WordPress bootstrap.
* **Registry + action hook for discovery.** Adding a rule in V2 means: add one class, register it in `Rules_Provider` (or from a third-party plugin via `sit_wcpg_register_rules`). No existing file changes behaviour. This satisfies constraint 9 without building a rule-builder UI.
* **Lazy service locator, not a DI container.** ~25 classes do not justify a container (constraint 10). `Plugin` holds lazily-instantiated singletons; constructors receive their collaborators explicitly, so unit tests can inject doubles.
* **Facade (`Checklist_Service`) between callers and the engine.** It is the single place that owns memoization and cache keys. It is also the documented seam where a persisted readiness store could be introduced later (§14.4) without touching any caller.

---

## 4. Directory and file structure

```text
product-publish-guard/
├── product-publish-guard.php          # Plugin header, constants, requirements gate, boot
├── uninstall.php                      # Option + transient cleanup
├── readme.txt                         # WP.org readme
├── README.md                          # Developer readme
├── LICENSE                            # GPL-2.0-or-later
├── composer.json                      # DEV ONLY: phpcs, wpcs, phpunit, wp-phpunit
├── phpcs.xml.dist
├── phpunit.xml.dist
├── package.json                       # wp-scripts, @wordpress/* devDeps
├── webpack.config.js                  # extends @wordpress/scripts config (2 entries)
├── .wp-env.json                       # test environment (WP + WooCommerce)
├── .distignore                        # excluded from the release zip
├── build/                             # GENERATED — never edited by hand
│   ├── editor.js  editor.asset.php  editor.css
│   └── admin.css
├── languages/
│   └── product-publish-guard.pot
├── src/                               # PHP only
│   ├── Autoloader.php
│   ├── Plugin.php
│   ├── Compat/
│   │   ├── Requirements.php
│   │   └── Woo_Compat.php
│   ├── Settings/
│   │   ├── Settings.php
│   │   ├── Settings_Sanitizer.php
│   │   └── Settings_Page.php
│   ├── Engine/
│   │   ├── Status.php
│   │   ├── Severity.php
│   │   ├── Rule_Interface.php
│   │   ├── Abstract_Rule.php
│   │   ├── Rule_Result.php
│   │   ├── Validation_Result.php
│   │   ├── Rule_Registry.php
│   │   ├── Product_Context.php
│   │   ├── Save_Request_Reader.php
│   │   └── Validator.php
│   ├── Rules/
│   │   ├── Rules_Provider.php
│   │   ├── Title_Rule.php
│   │   ├── Description_Rule.php
│   │   ├── Short_Description_Rule.php
│   │   ├── Featured_Image_Rule.php
│   │   ├── Image_Count_Rule.php
│   │   ├── Price_Rule.php
│   │   ├── Sale_Price_Rule.php
│   │   ├── Category_Rule.php
│   │   ├── Tags_Rule.php
│   │   ├── Sku_Rule.php
│   │   └── Stock_Status_Rule.php
│   ├── Support/
│   │   └── Checklist_Service.php
│   ├── Rest/
│   │   └── Validate_Controller.php
│   ├── Publishing/
│   │   └── Publish_Guard.php
│   └── Admin/
│       ├── Screen.php
│       ├── Assets.php
│       ├── Editor_Meta_Box.php
│       ├── Product_List_Column.php
│       └── Notices.php
├── assets/                            # SOURCES (not shipped)
│   ├── js/editor/                     # React sources (see §4.3)
│   ├── scss/editor.scss
│   └── scss/admin.scss
└── tests/
    ├── bootstrap.php
    ├── Unit/Rules/*Test.php
    ├── Unit/Engine/*Test.php
    ├── Integration/*Test.php
    ├── Security/*Test.php
    └── js/*.test.js
```

> React/SCSS sources live in `assets/`, compiled output in `build/`. `src/` is PHP-only, which keeps the PHP autoloader root clean and unambiguous.

### 4.1 PHP files — responsibility, API, callers

| File | Responsibility | Key API | Called by |
|---|---|---|---|
| `product-publish-guard.php` | Plugin header (incl. `Requires Plugins: woocommerce`), define constants (`SIT_WCPG_VERSION`, `SIT_WCPG_FILE`, `SIT_WCPG_PATH`, `SIT_WCPG_URL`, `SIT_WCPG_MIN_PHP`, `SIT_WCPG_MIN_WP`, `SIT_WCPG_MIN_WC`), require `src/Autoloader.php`, register the autoloader, hook `plugins_loaded` → `Requirements::check()` → `Plugin::instance()->boot()`, hook `before_woocommerce_init` → `Woo_Compat::declare_compatibility()`. | `sit_wcpg_bootstrap()` — the only global function in the plugin | WordPress |
| `src/Autoloader.php` | PSR-4 map `ProductPublishGuard\` → `src/`. Only handles the plugin prefix; `str_replace( '\\', '/' )`; `file_exists` check before `require`. | `Autoloader::register()` | Bootstrap |
| `src/Plugin.php` | Lazy service locator + hook wiring. `boot()` decides what to instantiate for this request: `Publish_Guard` always; `Admin\*` only when `is_admin()`; `Validate_Controller` on `rest_api_init`. Exposes `settings()`, `registry()`, `validator()`, `checklist()` which construct on first call. | `Plugin::instance()`, `boot()`, typed getters | Bootstrap, internal |
| `src/Compat/Requirements.php` | PHP/WP/WC version + WooCommerce-active checks. On failure: register an `admin_notices` callback with an escaped, translated, actionable message and return `false` (plugin does not boot). Also exposes `is_product_block_editor_active()`, implemented as `! use_block_editor_for_post_type( 'product' ) ? false : true` with an optional `FeaturesUtil::feature_is_enabled( 'product_block_editor' )` check behind `method_exists()` — **never** `Features::is_enabled()`, deprecated since WC 11.1.0. On WC 11 this always returns `false`. | `check(): bool`, `get_failures(): array` | Bootstrap |
| `src/Compat/Woo_Compat.php` | `FeaturesUtil::declare_compatibility( 'custom_order_tables', SIT_WCPG_FILE, true )` only, wrapped in a `class_exists` guard. **Do not declare against `product_block_editor`** — that feature id does not exist in current WooCommerce and the call would simply return `false` (verified: `verification-notes.md` §2). | `declare_compatibility()` | `before_woocommerce_init` |
| `src/Settings/Settings.php` | Typed, cached facade over the `sit_wcpg_settings` option. Merges stored values over `get_defaults()`. Provides `is_enabled()`, `rule_is_enabled( $id )`, `rule_severity( $id )`, `threshold( $key )`, `blocks_publishing()`, `allows_admin_override()`, `enforcement_scope()`, `shows_list_column()`, `get_hash()` (md5 of the normalized array, used in cache keys), `get_all()`. Defaults derive from the registry's rule defaults, so a newly added rule works before it is ever saved. | as above | Everything |
| `src/Settings/Settings_Sanitizer.php` | Pure sanitizer: raw `$_POST` array → fully normalized settings array. Whitelists rule ids against the registry, coerces booleans, clamps thresholds, whitelists severity + enforcement-scope enums, drops unknown keys. Adds `settings_errors()` entries when a value is clamped. | `sanitize( array $raw ): array` | `register_setting` sanitize callback (and therefore every `update_option` on `sit_wcpg_settings`) |
| `src/Settings/Settings_Page.php` | Registers submenu `woocommerce` → `sit-wcpg-settings` with cap `manage_woocommerce`; `register_setting`; the `option_page_capability_sit_wcpg_settings` filter; renders the form (sections: Rules, Content, Publishing) with `settings_fields()`, escaped labels, and rule rows built from the registry. | `register()`, `render_page()` | `admin_menu`, `admin_init` |
| `src/Engine/Status.php` | `const PASS/WARNING/FAIL/SKIPPED` + `is_valid()`, `weight()` (sort order: fail < warning < pass < skipped). A class of constants, not an enum (enums are PHP 8.1+; the floor is 8.0). | constants | Engine, Admin |
| `src/Engine/Severity.php` | `const REQUIRED/WARNING` + `is_valid()`, `all()`, `label()`. | constants | Engine, Settings |
| `src/Engine/Rule_Interface.php` | The rule contract (§5.1). | — | Rules |
| `src/Engine/Abstract_Rule.php` | Default implementations for everything except `get_id()`, `get_label()`, `check()`. Provides `pass()/fail()/warn()/skip()` result factories that stamp the rule id, label and group. | — | All rules |
| `src/Engine/Rule_Result.php` | Immutable value object: `rule_id, status, severity, label, message, group, data[], fix[]`. `to_array()` returns a JSON-safe array. Constructed only via static factories. | `pass/warn/fail/skip/to_array/get_*` | Rules, Validator |
| `src/Engine/Validation_Result.php` | Aggregate: `product_id, product_type, results[], counts, is_ready, required_failure_ids[], summary_label, generated_at, settings_hash`. Counts computed once in the constructor. | `from_results()`, `is_ready()`, `get_required_failures()`, `to_array()` | Everything downstream |
| `src/Engine/Rule_Registry.php` | Holds rules keyed by id. `register()` (rejects duplicate ids; `_doing_it_wrong()` in debug), `get()`, `has()`, `all()` (sorted by priority then id), `get_active( Settings, Product_Context )`. Populated once per request, lazily. | as above | Validator, Settings_Page, Settings_Sanitizer |
| `src/Engine/Product_Context.php` | Normalized, memoized product data (§5.3). Four factories. **The only class in `Engine`/`Rules` allowed to call WordPress/WooCommerce data functions.** | `from_product()`, `from_product_with_overrides()`, `from_save_request()`, `from_array()`, ~20 getters | Validator, rules |
| `src/Engine/Save_Request_Reader.php` | Knows the shape of every admin save payload (classic editor, quick edit, bulk edit) and converts it to a normalized override array. Handles `wp_unslash`, the missing-vs-empty distinction, `-1` sentinels, per-field sanitization. **Verified field names** (`verification-notes.md` §9): status `_status` (`-1` = no change, and core may omit `post_status` entirely); WooCommerce `_sku`, `_regular_price`, `_sale_price`, `_stock_status`, `_stock`, `_manage_stock`; bulk-edit modifiers `change_regular_price` / `change_sale_price` / `change_stock` (absent/empty = no change); featured image `_thumbnail_id` where **any value `<= 0` means "no image"**; gallery `product_image_gallery` (comma-separated ids); quick-edit marker `woocommerce_quick_edit`. **Isolates all `$_POST` knowledge in one file.** | `read( array $post_data, array $insert_data ): array`, `detect_source(): string` | `Publish_Guard` |
| `src/Engine/Validator.php` | Runs active rules against a context, applies configured severity to rule outcomes (§5.5), builds `Validation_Result`, fires the `sit_wcpg_validation_result` filter. Wraps each rule call in try/catch so one bad rule cannot break the screen. | `validate( Product_Context ): Validation_Result` | `Checklist_Service` |
| `src/Rules/Rules_Provider.php` | Instantiates the 11 built-in rules into the registry, then `do_action( 'sit_wcpg_register_rules', $registry )`. | `populate( Rule_Registry )` | `Plugin::registry()` |
| `src/Rules/*_Rule.php` | One rule each (§5.6). | `check( Product_Context, Settings ): Rule_Result` | `Validator` |
| `src/Support/Checklist_Service.php` | Public façade. Owns memoization: a static per-request array plus optional `wp_cache_get/set` in group `sit_wcpg`, keyed `v1:{id}:{post_modified_gmt}:{settings_hash}` — only for override-free validations. | `validate_post( $id, $overrides = [] )`, `get_summary_for_post_id( $id )`, `flush_post( $id )` | Meta box, REST, list column, guard |
| `src/Rest/Validate_Controller.php` | Registers `sit-wcpg/v1`, the full `args` schema with per-field `sanitize_callback`/`validate_callback`, the `permission_callback`, and response assembly. | `register_routes()`, `validate_item()`, `permissions_check()` | `rest_api_init` |
| `src/Publishing/Publish_Guard.php` | The three enforcement layers (§6.3), the skip matrix, scope resolution, override checks, notice queuing. | `filter_insert_post_data()`, `guard_product_object_save()`, `guard_scheduled_publish()`, `can_override()` | `wp_insert_post_data`, `woocommerce_before_product_object_save`, `future_to_publish` |
| `src/Admin/Screen.php` | Screen predicates: `is_product_edit_screen()`, `is_product_list_screen()`, `is_settings_screen()`, `current_product_id()`. The single place that knows hook suffixes. | static predicates | Assets, meta box, list column |
| `src/Admin/Assets.php` | Conditional enqueue (§11.1). Reads `build/editor.asset.php` for deps/version. `wp_set_script_translations()`. Prints the bootstrap payload via `wp_add_inline_script()` with `wp_json_encode()`, never string concatenation. | `enqueue( $hook_suffix )` | `admin_enqueue_scripts` |
| `src/Admin/Editor_Meta_Box.php` | Registers the meta box; renders the mount node plus a server-rendered, fully escaped no-JS fallback list. | `register()`, `render( WP_Post )` | `add_meta_boxes_product` |
| `src/Admin/Product_List_Column.php` | Column registration, cache priming on `the_posts`, cell rendering. | `add_column()`, `prime_caches()`, `render_column()` | `manage_edit-product_columns`, `the_posts`, `manage_product_posts_custom_column` |
| `src/Admin/Notices.php` | Per-user transient queue for "publishing blocked" events; renders on `admin_notices`; overrides `post_updated_messages` for products; adds the `sit_wcpg_blocked` redirect arg. | `queue( $user_id, $post_id, Validation_Result )`, `render()` | `Publish_Guard`, `admin_notices` |

### 4.2 Files deliberately **not** created

`Rule_Factory`, `Rule_Collection`, `Container`, `Hooks_Loader`, a service provider per component, `Abstract_Admin_Page`, a view/template engine, per-rule settings classes, a `Logger`. Each adds indirection without removing duplication at this size (constraint 10).

### 4.3 JavaScript structure

```text
assets/js/editor/
├── index.js                 # entry: reads window.sitWcpgEditorData, renders <ChecklistApp/> into #sit-wcpg-checklist-root
├── api.js                   # apiFetch wrapper: builds the request, owns AbortController, maps errors
├── field-watcher.js         # subscribes to classic-editor fields, emits debounced snapshots
│                            # (delegated jQuery on #woocommerce-product-data; MutationObserver on the
│                            #  #postimagediv and #woocommerce-product-images *containers*, not the inputs)
├── snapshot.js              # pure: DOM → draft object; computes a stable signature string
├── hooks/useValidation.js   # useReducer state machine: idle | loading | ready | error (+ stale flag)
├── components/
│   ├── ChecklistApp.js      # composition root; wires useValidation + field-watcher
│   ├── ChecklistSummary.js  # status pill, "n of m checks passed", issue count
│   ├── ChecklistGroup.js    # group header (content/media/pricing/organization/inventory)
│   ├── ChecklistItem.js     # icon + label + message + optional fix action
│   ├── StatusIcon.js        # pure presentational
│   └── ChecklistFooter.js   # last-checked time, manual "Re-check" fallback, error retry
└── utils/focusField.js      # scrolls to / focuses the DOM node named by a rule's `fix` target
```

**State rules:** all validation state lives in `useValidation`; components are presentational and receive props. No `@wordpress/data` store in V1 (one mount point, no cross-tree sharing — premature abstraction). No Redux, no context provider.

---

## 5. Rule engine design

### 5.1 The rule contract

```php
interface Rule_Interface {
    public function get_id(): string;                 // stable, snake_case, used in settings + JSON
    public function get_label(): string;              // translated, short, shown in the checklist row
    public function get_description(): string;        // translated, shown as settings help text
    public function get_group(): string;              // content|media|pricing|organization|inventory
    public function get_priority(): int;              // display order, 10-step increments
    public function get_default_severity(): string;   // Severity::REQUIRED | Severity::WARNING
    public function is_enabled_by_default(): bool;
    public function supports( Product_Context $context ): bool;   // product-type applicability
    public function get_fix_target(): array;          // ['selector'=>'#_sku','label'=>…,'panel'=>'inventory'] or []
    public function check( Product_Context $context, Settings $settings ): Rule_Result;
}
```

`Abstract_Rule` implements everything except `get_id()`, `get_label()`, `check()`, with defaults: group `content`, priority `50`, severity `REQUIRED`, enabled `true`, `supports()` → `true`, `get_fix_target()` → `[]`, `get_description()` → `''`. **A new rule is therefore ~30 lines.**

### 5.2 Registry, discovery, enable/disable, priority

* `Rules_Provider::populate( $registry )` registers the 11 built-ins in priority order, then fires `do_action( 'sit_wcpg_register_rules', $registry )`.
* The registry is built **lazily** — only when a validation, the settings page, or the sanitizer needs it. No rule objects are constructed on unrelated admin pages.
* Duplicate ids are rejected; under `WP_DEBUG` this calls `_doing_it_wrong()`.
* `get_active( Settings $s, Product_Context $c )` returns rules where `$s->rule_is_enabled( $id ) && $rule->supports( $c )`, sorted by `get_priority()` then `get_id()` — deterministic ordering for a stable UI and stable test assertions.
* **Priority is display order only.** Rules must not depend on each other or on execution order; this is what keeps them independently testable (general-plan §5).

### 5.3 `Product_Context` — the data boundary

Normalized accessors (all memoized, all null-safe):

```text
get_product_id(): int            get_product_type(): string       get_post_status(): string
get_title(): string              get_description(): string        get_short_description(): string
get_description_length(): int    get_short_description_length(): int
get_featured_image_id(): int     has_valid_featured_image(): bool
get_gallery_image_ids(): int[]   get_image_count(): int
get_regular_price(): string      get_sale_price(): string
get_sale_from(): string          get_sale_to(): string
get_sku(): string                get_stock_status(): string
get_manage_stock(): bool         get_stock_quantity(): ?int
get_category_ids(): int[]        get_tag_ids(): int[]
get_default_category_id(): int   is_only_default_category(): bool
get_product(): ?WC_Product
```

**Text length normalization (one definition, used by both length rules):**

```text
mb_strlen( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $raw ), true ) ) ) )
```

Strip shortcodes → strip tags (line breaks removed) → collapse whitespace → trim → count characters. Thresholds are in **characters**, matching general-plan §3.4. `do_shortcode()` is never called.

**Factories**

| Factory | Input | Used by | Behaviour |
|---|---|---|---|
| `from_product( WC_Product $p )` | saved product | list column, meta box first paint, Layer B guard | reads everything through WooCommerce CRUD getters + `get_the_terms()` |
| `from_product_with_overrides( WC_Product $p, array $o )` | saved product + sanitized draft snapshot | REST live validation | an override wins **only for keys present** in `$o`; everything else falls back to the saved product |
| `from_save_request( ?WC_Product $p, array $overrides )` | stored product (may be `null` for a new post) + `Save_Request_Reader` output | `Publish_Guard` Layer A | same merge semantics; solves R1 (unsaved meta) |
| `from_array( array $data )` | plain array | **unit tests only** | pre-fills the memo cache so no WP function is ever called |

The merge semantics are the crux: *present key → override; absent key → stored value*. This makes Quick Edit (which posts price/SKU/status but not description) and Bulk Edit (which posts only status) correct with **zero** special-casing inside the rules.

### 5.4 Result structures

```php
// Rule_Result::to_array()
[
  'rule_id'  => 'featured_image',
  'status'   => 'fail',              // pass | warning | fail | skipped
  'severity' => 'required',          // required | warning  (the CONFIGURED severity)
  'label'    => 'Featured image',    // translated
  'message'  => 'This product has no featured image.',
  'group'    => 'media',
  'data'     => [ 'image_count' => 0 ],      // integers/booleans only — never product text
  'fix'      => [ 'selector' => '#set-post-thumbnail', 'label' => 'Set featured image', 'panel' => '' ],
]

// Validation_Result::to_array()
[
  'product_id'   => 123,
  'product_type' => 'simple',
  'results'      => [ /* Rule_Result[] in display order */ ],
  'counts'       => [ 'evaluated' => 9, 'passed' => 6, 'warnings' => 2, 'failed' => 1, 'skipped' => 2 ],
  'is_ready'     => false,                     // true iff no result has status 'fail'
  'required_failure_ids' => [ 'featured_image' ],
  'summary_label'        => '6 of 9 checks passed',   // translated server-side
  'generated_at' => 1716900000,
  'settings_hash'=> 'a1b2…',
]
```

`counts['evaluated'] = passed + warnings + failed`. Skipped rules are excluded from the denominator so a variable product does not look permanently incomplete because price is checked per-variation in V2.

### 5.5 Status derivation — the one non-obvious mechanism

Rules report an **outcome**; the engine derives the **status**:

| Rule returns | Configured severity `required` | Configured severity `warning` |
|---|---|---|
| `pass()` | `pass` | `pass` |
| `fail( msg )` | `fail` | `warning` |
| `warn( msg )` | `warning` | `warning` |
| `skip( reason )` | `skipped` | `skipped` |

* `fail()` = "this requirement is not met" → the merchant's severity setting decides whether it blocks publishing.
* `warn()` = "this is advisory by nature" (description below the threshold, only the default category assigned, out of stock) → **never** escalated to `fail`, even when severity is `required`. This prevents a threshold tweak from silently blocking every product in the store.
* `skip()` = not applicable to this product type/state → excluded from counts and from publish enforcement.

Readiness: `is_ready === true` iff no result has status `fail`. Publishing is blocked iff `required_failure_ids` is non-empty **and** `publishing.block_on_required_failure` is on.

### 5.6 Rule catalog (V1 — exactly these 11)

Notation: **I** input, **P** pass, **W** warn, **F** fail, **S** skip.

| id | group | prio | default | Logic |
|---|---|---|---|---|
| `title` | content | 10 | enabled, **required** | **I** `get_title()`. **F** if the trimmed title is empty or is the WP auto-draft placeholder → *"Add a product title."* **P** otherwise. Fix: `#title`. |
| `description` | content | 20 | enabled, **required** | **I** `get_description()`, `get_description_length()`, `thresholds.min_description_chars`. **F** length `0` → *"The product description is empty."* **W** `0 < length < min` → *"The product description is %1$d characters; at least %2$d are recommended."* **P** otherwise. Threshold `0` disables the warning branch. Fix: `#content`. |
| `short_description` | content | 30 | enabled, **warning** | Same shape as `description`, using `min_short_description_chars`. Fix: `#excerpt`. |
| `featured_image` | media | 40 | enabled, **required** | **I** `get_featured_image_id()`, `has_valid_featured_image()`. The context normalizes the DOM/POST `-1` sentinel to `0` before the rule sees it. **F** id `0` → *"This product has no featured image."* **F** attachment missing or not an image → *"The featured image is missing from the media library."* **P** otherwise. Fix: `#set-post-thumbnail`. |
| `image_count` | media | 50 | enabled, **warning** | **I** `get_image_count()` (featured + gallery, de-duplicated), `thresholds.min_images`. **S** if `min_images <= 1`. **F** `count < min` → *"This product has %1$d image(s); at least %2$d are recommended."* **P** otherwise. Fix: `#product_images_container`. |
| `price` | pricing | 60 | enabled, **required** | **I** `get_product_type()`, `get_regular_price()`. **S** for `variable` (*"Variable product pricing is validated per variation, which is not part of this version."*) and `grouped`. **F** empty → *"Set a regular price."* **F** non-numeric or `< 0` → *"The regular price is not a valid number."* **P** otherwise. A price of exactly `0` **passes** — free products are legitimate. Fix: `#_regular_price`, panel `general`. |
| `sale_price` | pricing | 70 | enabled, **warning** | **I** `get_sale_price()`, `get_regular_price()`, `get_sale_from()`, `get_sale_to()`. **S** if no sale price set. **W** non-numeric / `< 0` / `>= regular price` / sale end earlier than sale start — each with its own message. **P** otherwise. Fix: `#_sale_price`, panel `general`. |
| `category` | organization | 80 | enabled, **required** | **I** `get_category_ids()`, `is_only_default_category()`. **F** empty → *"Assign at least one product category."* **W** only the store default term → *"This product only uses the default category."* **P** otherwise. Fix: `#product_catdiv`. |
| `tags` | organization | 90 | enabled, **warning** | **I** `get_tag_ids()`. **F** empty → *"Add at least one product tag."* (default severity `warning` ⇒ surfaces as a warning). **P** otherwise. Fix: `#tagsdiv-product_tag`. |
| `sku` | inventory | 100 | enabled, **warning** | **I** `get_sku()`. **F** trimmed SKU empty → *"Add a SKU for this product."* **P** otherwise. Uniqueness is WooCommerce's own job; duplicate detection is V3. Fix: `#_sku`, panel `inventory`. |
| `stock_status` | inventory | 110 | enabled, **warning** | **I** `get_stock_status()`, `get_manage_stock()`, `get_stock_quantity()`. **F** empty or not one of `instock`/`outofstock`/`onbackorder` → *"Select a stock status."* **W** `outofstock` → *"This product is marked out of stock."* **W** stock management on and quantity `<= 0` while status is `instock` → *"Stock management is on but the quantity is %d."* **P** otherwise. Fix: `#_stock_status`, panel `inventory`. |

Every message above is a translated literal, contains **no product-supplied text**, and interpolates **integers only** (§9.5).

### 5.7 Adding a rule later (the extensibility test)

```php
// In a third-party plugin, or in Rules_Provider for a first-party V2 rule:
add_action( 'sit_wcpg_register_rules', function ( $registry ) {
    $registry->register( new My_Alt_Text_Rule() );
} );
```

Handled automatically: the settings page gains a row (rows are built from the registry), the sanitizer whitelists the new id, defaults apply until saved, the React panel renders it (the UI is data-driven), and publish enforcement honours it. **Zero edits to existing files.**

---

## 6. WordPress / WooCommerce integration points

### 6.1 Bootstrap & compatibility

| Hook | Priority | Why / what we do | Side effects |
|---|---|---|---|
| `plugins_loaded` | 5 | `Requirements::check()` then `Plugin::boot()`. Late enough that WooCommerce has defined `WC_VERSION`; early enough to register everything else. | If requirements fail, only an `admin_notices` callback is registered. |
| `before_woocommerce_init` | 10 | `FeaturesUtil::declare_compatibility( 'custom_order_tables', … , true )` — HPOS compatible. Nothing else is declared. | Prevents WooCommerce's "incompatible plugin" warning on the HPOS screen. (The block-editor declaration was dropped: the feature id no longer exists.) |
| `init` | 10 | `load_plugin_textdomain()`. **Not earlier** — WP 6.7+ warns when translations load before `init`. | — |

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
| `admin_notices` | server | Publishing-blocked feedback + requirement failures. | — | `Notices::render()`. |
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
* **Feedback (R5):** `Notices::queue()` writes a 60-second transient `sit_wcpg_blocked_{user_id}_{post_id}` containing only rule ids/labels/statuses; `redirect_post_location` adds `sit_wcpg_blocked=1`; `admin_notices` renders and deletes it.

#### 6.3.2 Layer B — `woocommerce_before_product_object_save` (CRUD gate)

* **Hook:** `add_action( 'woocommerce_before_product_object_save', …, 10, 2 )` → `( WC_Product $product, $data_store )`.
* **Why also here:** WooCommerce CRUD writes (REST `/wc/v3/products`, WP-CLI, importers, `$product->save()` from other plugins) set props on the object and write the post row afterwards; at Layer A those props are invisible. At this hook the object holds the **intended final state**.
* **Action:** when `$product->get_status()` is `publish`/`future`, the stored status is not already `publish`, the skip matrix passes, and `Product_Context::from_product( $product )` yields required failures → `$product->set_status( 'draft' )` and queue a notice if a user context exists.
* **Verified (`verification-notes.md` §1):** the action fires in `WC_Product::save()` (`abstract-wc-product.php:1559`) **after** `validate_props()` and **before** the data store write, and core's own docblock sanctions adjusting props there — so `set_status()` at this point is supported, not a hack. Because `validate_props()` has already run, stock status is post-normalization here; read stock from the object, never from POST, in this layer.
* **Re-entrancy:** a static in-progress flag keyed by product id prevents Layers A and B both acting on the same write.

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
2. Backstop: `add_action( 'future_to_publish', …, 5 )` — if the product now fails required checks, revert it to `draft` with `wp_update_post()`, guarded by a static re-entrancy flag, and queue a notice for the post author.

**Documented limitation:** in case 2 the product is technically published for the duration of that request. Acceptable because measure 1 makes it rare — it requires the product to have been broken *after* it was scheduled.

#### 6.3.5 Client-side assistance (UX only, never enforcement)

When required failures exist, the React panel shows an inline warning and, on `submit` of `#post` via the Publish button, a confirmation prompt. **It never disables the Publish button** — hiding the real server-side outcome is exactly the anti-pattern general-plan §15.11 warns about. The server decision is always authoritative and always produces a notice.

### 6.4 Products already published (decision A4)

Enforcement applies to transitions **into** `publish`/`future`. A live product that later fails a rule is surfaced in the checklist and the list column but is **never** auto-demoted. Auto-unpublishing live products is destructive, is not requested anywhere in general-plan, and would make a settings change capable of taking a whole catalogue offline.

---

## 7. Admin UI specification

### 7.1 Product editor panel (`side` context, above the fold)

```text
┌─ Product Readiness ─────────────────┐
│  ⚠  Not ready to publish            │   ← summary pill: Ready / Not ready / Checking
│  6 of 9 checks passed · 1 issue     │
├─────────────────────────────────────┤
│  CONTENT                            │   ← group header (uppercase, muted)
│  ✓  Product title                   │
│  ✓  Description                     │
│  !  Short description               │
│     42 characters; 50 recommended.  │   ← message shown only for warning/fail rows
│  MEDIA                              │
│  ✗  Featured image                  │
│     This product has no featured    │
│     image.  [ Set featured image ]  │   ← fix action (focuses the DOM node)
│  …                                  │
├─────────────────────────────────────┤
│  Checked just now       [ Re-check ]│
└─────────────────────────────────────┘
```

* **States:** `pass` ✓ green, `warning` ! amber, `fail` ✗ red, `skipped` – muted (rendered last, collapsed under "Not applicable (n)").
* **Messages** appear only on non-pass rows — a wall of green explanations is noise.
* **Fix action** appears only when the rule declares `fix.selector`; clicking scrolls to and focuses the element and opens the containing WooCommerce product-data panel when `fix.panel` is set. It never navigates away (unsaved changes).
* **Interaction:** no accordion, no tabs, no modal. A read-only list plus fix links.
* **Refresh:** automatic (§8.2). The `Re-check` button is a secondary fallback for fields the watcher cannot observe and for retrying after a network error; it is never required in the normal flow.
* **Loading:** the previous result stays visible, dimmed, with a small spinner in the header (`stale` flag). Never blank the panel — flicker on every keystroke is worse than a briefly stale list.
* **Error:** an inline `Notice` ("Could not refresh the checklist.") plus retry; the last good result remains visible.
* **No-JS fallback:** the meta box server-renders the current result as a plain escaped list, which React replaces on mount.
* **Accessibility:** status is conveyed by icon **and** text, never colour alone; the summary region is `aria-live="polite"`; icons are `aria-hidden` with visually-hidden text labels.

### 7.2 Products list column

* Column title **Readiness**, inserted before **Date**.
* Cell values: `✓ Ready` / `! 2 warnings` / `✗ 3 errors` (plural-aware via `_n()`), linking to the product's edit screen with `#sit_wcpg_product_checklist`.
* `auto-draft` and trashed products render `—`.
* Drafts and published products are treated identically — the indicator describes **content completeness**, not publication state. A published product with errors is precisely the case a merchant needs to see.
* Hidden entirely when `product_list.show_column` is off or the user lacks `edit_products`.
* No sorting, no filtering (§13).

### 7.3 Settings page — `WooCommerce → Product Checklist`

```text
Product Checklist

[x] Enable the product readiness checklist

── Rules ──────────────────────────────────────────────
 Rule                Enabled   Severity
 Product title        [x]      (•) Required  ( ) Warning
 Description          [x]      (•) Required  ( ) Warning
 Short description    [x]      ( ) Required  (•) Warning
 Featured image       [x]      (•) Required  ( ) Warning
 Minimum images       [x]      ( ) Required  (•) Warning
 Regular price        [x]      (•) Required  ( ) Warning
 Sale price validity  [x]      ( ) Required  (•) Warning
 Product category     [x]      (•) Required  ( ) Warning
 Product tags         [x]      ( ) Required  (•) Warning
 SKU                  [x]      ( ) Required  (•) Warning
 Stock status         [x]      ( ) Required  (•) Warning

── Content thresholds ─────────────────────────────────
 Minimum description length        [ 150 ] characters  (0 = off)
 Minimum short description length  [  50 ] characters  (0 = off)
 Minimum number of images          [   2 ] images      (0 or 1 = off)

── Publishing ─────────────────────────────────────────
 [x] Prevent publishing when required checks fail
 [ ] Allow users who can manage WooCommerce to publish anyway
 Enforcement applies to:  (•) All signed-in requests  ( ) Admin editor screens only
 [x] Show the Readiness column in the products list

[ Save changes ]
```

**Storage:** the single option `sit_wcpg_settings`.

```php
[
  'version'      => 1,
  'enabled'      => true,
  'rules'        => [ '<rule_id>' => [ 'enabled' => bool, 'severity' => 'required'|'warning' ], … ],
  'thresholds'   => [ 'min_description_chars' => int, 'min_short_description_chars' => int, 'min_images' => int ],
  'publishing'   => [ 'block_on_required_failure' => bool, 'allow_admin_override' => bool, 'enforce_scope' => 'authenticated'|'editor' ],
  'product_list' => [ 'show_column' => bool ],
]
```

**Validation/sanitization (`Settings_Sanitizer`, §9.3):** rule ids are whitelisted against the registry (unknown keys dropped, not stored); `enabled` → `(bool) isset()`; `severity` → whitelisted against `Severity::all()` with the rule's default as fallback; thresholds → `absint()` then clamped (`min_description_chars` 0–10000, `min_short_description_chars` 0–5000, `min_images` 0–20) with a `settings_errors()` notice when clamping occurs; `enforce_scope` → enum whitelist; `version` forced to the code constant, never taken from input. Unknown top-level keys are discarded so the option can never accumulate arbitrary data.

---

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
| `excerpt` | `#excerpt` | plain textarea |
| `product_type` | `#product-type` | also re-snapshot on the `woocommerce-product-type-change` jQuery event — a type change alters which rules apply |
| `regular_price` / `sale_price` | `#_regular_price` / `#_sale_price` | ids confirmed in `html-product-data-general.php` |
| `sku` | `#_sku` | confirmed in `html-product-data-inventory.php` |
| `stock_status` / `manage_stock` / `stock_quantity` | `#_stock_status` / `#_manage_stock` / `#_stock` | confirmed in `html-product-data-inventory.php` |
| `featured_image_id` | `#_thumbnail_id` | **core writes `-1`, not `''`, when there is no image — map any value `<= 0` to `0`** |
| `gallery_image_ids` | `#product_image_gallery` | comma-separated id list |
| `category_ids` / `tag_ids` | `#product_catchecklist` checked boxes / `#tagsdiv-product_tag` | terms are read as ids where available, otherwise by presence |

Binding rules that follow from the above:

* Product-data fields are bound by **delegation** on `#woocommerce-product-data` (`change`, `input`). WooCommerce shows and hides panels by class on type change rather than re-rendering them, but delegation is immune either way.
* The featured-image and gallery blocks **are** replaced wholesale by their AJAX responses, destroying `#_thumbnail_id`. Observe the containers `#postimagediv` and `#woocommerce-product-images` with `MutationObserver({ childList: true, subtree: true })` and re-read the hidden input on each mutation. Never observe the input node itself.

**Request-avoidance rules (R3):**

1. 800 ms trailing debounce.
2. Signature dedupe — identical snapshots never produce a request (covers focus/blur churn and TinyMCE's chatty events).
3. A single in-flight request; a newer snapshot aborts the older request.
4. A hard floor of 1500 ms between two completed requests (leading-edge throttle on top of the debounce).
5. Watching pauses on `visibilitychange` → hidden, and stops on form submit.
6. No polling, no interval timers.

**Error handling:** aborts are silent. Real failures set `status: 'error'` while keeping the last good result visible. `403` (expired nonce after a long idle session) → "Your session expired — reload the page to continue checking." `5xx` → the generic retry notice. The client never invents a pass/fail.

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

---

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
| `$_POST` at save time | Read only inside `Save_Request_Reader`, always via `wp_unslash()` then field-appropriate sanitizers (`sanitize_text_field`, `absint`, `wp_parse_id_list`). The reader never writes anything. |
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

---

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
* `load_plugin_textdomain()` on `init`.
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

---

## 11. Performance

### 11.1 Conditional asset loading (exact matrix)

| Screen | JS | CSS | Inline payload |
|---|---|---|---|
| `post.php` / `post-new.php` with `post_type=product` | `sit-wcpg-editor` (+ core deps) | `editor.css` | `sitWcpgEditorData` |
| `edit.php?post_type=product` | **none** | `admin.css` | none |
| `woocommerce_page_sit-wcpg-settings` | **none** | `admin.css` | none |
| Everything else | none | none | none |

Determined from `admin_enqueue_scripts`'s `$hook_suffix` plus `get_current_screen()->post_type`, centralised in `Admin\Screen`. The settings page and the products list are plain server-rendered HTML — shipping React to them would be pure waste.

### 11.2 Products list priming (R4)

On `the_posts` for the product list screen only, with the full row set in hand:

1. `update_post_caches( $posts, 'product', true, true )` — post + meta caches in one query pair.
2. `update_object_term_cache( $ids, 'product' )` — all categories and tags in one query.
3. Collect `_thumbnail_id` values (now cached) and prime those attachment posts with `_prime_post_caches( $ids, false, true )` (`wp-includes/post.php:8494`) — one query, no term cache, meta cache on.
4. Collect gallery ids **only if** the `image_count` rule is enabled, and prime those too.

Result: per-row validation performs **zero** additional queries. Added cost for a 20-row page is ~4 queries regardless of row count.

### 11.3 Memoization

`Checklist_Service` layers:

1. **Static per-request array** keyed by product id — the meta box and any other caller never validate the same product twice in one request.
2. **Object cache** (`wp_cache_get/set`, group `sit_wcpg`, key `v1:{id}:{post_modified_gmt}:{settings_hash}`, TTL 300 s) — only for override-free validations. Safe by construction: the key changes when the product or the settings change, so there is no invalidation logic to get wrong. Without a persistent object cache this degrades to layer 1 and costs nothing.
3. **Never cached:** validations with overrides (editor live checks, publish-guard checks). They must reflect the exact in-flight data.

Explicitly **not** done: storing readiness in post meta (§13). The seam for adding it later is `Checklist_Service::get_summary_for_post_id()` (§14.4).

### 11.4 Avoiding duplicate product reads

`Product_Context` memoizes every getter and holds a single `WC_Product` instance. Rules read the context, never the product. A full 11-rule run therefore touches the WooCommerce data store once.

### 11.5 Request frequency

See §8.2: debounce, signature dedupe, single in-flight request, 1500 ms floor, pause on hidden tab, stop on submit. Worst realistic case during continuous typing is one small, cache-free, write-free PHP run per 1.5 s.

### 11.6 Known future bottlenecks (document, do not pre-optimize)

* **Bulk audits (V3):** validating thousands of products in one request will need batching plus `wc_get_products( [ 'return' => 'ids' ] )` and a persisted readiness store. `Checklist_Service` is where that plugs in.
* **Variation rules (V2):** a variable product with 200 variations would multiply the rule count; V2 must add a variation-specific context and a per-product variation budget.
* **`the_posts` priming** grows with `posts_per_page`; at 999 per page the query *count* stays constant but the payload grows. Acceptable; note it in `README.md`.

---

## 12. Testing plan

Framework: PHPUnit + the WordPress test suite + WooCommerce test helpers, orchestrated with `wp-env` (`.wp-env.json` pins WP and WooCommerce). JS unit tests via `wp-scripts test-unit-js` (Jest).

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

---

## 13. V1 — Do Not Implement

The following appear in `general-plan.md` as future work. **They are out of scope for V1 and must not be implemented, not even "simply".**

1. Variation-level checks of any kind (per-variation price / SKU / stock / image). `price` skips variable products; that is the whole V1 story.
2. Attribute, brand/manufacturer, weight, dimensions, shipping-class or tax-status rules.
3. Image dimension, image quality, file-size or alt-text rules.
4. Any quality **score**, percentage or grade. Counts and states only (general-plan §13.6).
5. Duplicate title or duplicate SKU detection.
6. Bulk audits, "scan all products", store-wide reports, dashboards or widgets.
7. Scheduled or cron audits of any kind. The only cron interaction is the Layer C backstop, which is a guard, not an audit.
8. Email notifications, CSV export, webhooks, or any external HTTP request. **V1 makes no outbound network calls at all.**
9. Approval workflow, custom statuses (`needs-review` / `approved`), reviewer notes, activity or audit history.
10. Custom roles, capability editing, or role-based publishing rules.
11. A custom-rule builder UI, or per-product rule overrides.
12. AI features of any kind.
13. SEO-specific rules or integrations with SEO plugins.
14. Sorting or filtering the products list by readiness (requires persisted, indexed state — see A5 and §14.4).
15. Persisting readiness results in post meta, a custom table, or a per-product transient.
16. A custom product editor, custom product data panels, or new tabs in the WooCommerce product data box.
17. Support for the WooCommerce product **block** editor UI.
18. Compatibility shims for subscriptions, bookings, memberships, composite or bundled products, or any third-party product type.
19. Admin AJAX endpoints (`admin-ajax.php`) — REST only.
20. Onboarding wizards, upsell or Pro notices, telemetry, or review prompts.

---

## 14. Future extension points (designed now, empty in V1)

Only these five seams exist. Each costs ~0 lines in V1 and removes a rewrite later.

| # | Seam | Enables | V1 cost |
|---|---|---|---|
| 14.1 | `do_action( 'sit_wcpg_register_rules', Rule_Registry $registry )` | Custom rules, V2 rule packs (variations, images, alt text, attributes), Pro add-ons — without touching core files. | 1 line |
| 14.2 | `Rule_Interface::supports( Product_Context )` + `Status::SKIPPED` | Product-type-specific rule sets (variation rules that apply only to variable products) with correct counts from day one. | already required by §5.6 |
| 14.3 | `apply_filters( 'sit_wcpg_validation_result', Validation_Result $r, Product_Context $c )` | Result post-processing: suppressing rules per product, synthetic results, Pro reporting hooks. | 1 line in `Validator` |
| 14.4 | `Checklist_Service::get_summary_for_post_id()` as the **only** read path for non-editor consumers | A persisted readiness store (post meta + settings-hash stamp) can be introduced behind this one method to enable list sorting/filtering, bulk audits and reports without changing a single caller. | already required by §11.3 |
| 14.5 | `apply_filters( 'sit_wcpg_should_enforce', bool, Product_Context, string $source )` and `sit_wcpg_can_override_publish_guard` | Role-based publishing rules, approval workflows, per-integration exemptions. | 2 lines in `Publish_Guard` |

Also extensible without extra work because they are data-driven: the settings page (rows built from the registry), the React panel (renders whatever results the payload contains), and `Settings::get_defaults()` (derived from rule defaults).

**Not built as extension points** (speculative): a rule-result storage interface, a notification abstraction, a reporting interface, a job/queue abstraction, a template override system, a JS filter/slot-fill registry.

---

## 15. Implementation order

Each phase must be green before the next begins. "Green" = its validation criteria pass.

### Phase 1 — Project foundation
* **Prerequisites:** none.
* **Objective:** an installable, activatable plugin that boots safely and does nothing else.
* **Tasks:** plugin header (incl. `Requires Plugins: woocommerce`) + constants; `src/Autoloader.php`; `src/Plugin.php` skeleton; `Compat/Requirements.php`; `Compat/Woo_Compat.php`; `init` text-domain load; `uninstall.php`; `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `package.json`, `webpack.config.js`, `.wp-env.json`, `.distignore`, `LICENSE`, `README.md`, `readme.txt` stub.
* **Files affected:** all of the above.
* **Deliverable:** activates cleanly; deactivating WooCommerce shows the notice and causes no errors.
* **Validation:** `phpcs` clean; activation with `WP_DEBUG=true` produces zero notices; WooCommerce → Status shows no incompatibility warning.

### Phase 2 — Engine primitives
* **Prerequisites:** Phase 1.
* **Objective:** value objects and contracts, fully unit-tested, with no rules yet.
* **Tasks:** `Status`, `Severity`, `Rule_Interface`, `Abstract_Rule`, `Rule_Result`, `Validation_Result`, `Rule_Registry`, `Validator` (including the §5.5 matrix and per-rule try/catch), `Product_Context` (all factories except `from_save_request`), plus unit tests.
* **Files affected:** `src/Engine/*`, `tests/Unit/Engine/*`.
* **Deliverable:** `Validator` runs a fake rule against a `from_array()` context and produces a correct `Validation_Result`.
* **Validation:** all §12.1 engine tests pass; `Validation_Result::to_array()` matches §5.4 exactly in shape.

### Phase 3 — Settings
* **Prerequisites:** Phase 2 (defaults derive from the registry).
* **Objective:** settings readable, writable and safely sanitized.
* **Tasks:** `Settings`, `Settings_Sanitizer`, `Settings_Page` (menu, `register_setting`, capability filter, form rendering), tests.
* **Files affected:** `src/Settings/*`, `tests/Unit/Settings_Sanitizer_Test.php`, `tests/Integration/Settings_Test.php`.
* **Deliverable:** `WooCommerce → Product Checklist` saves and reloads correctly.
* **Validation:** the §12.3 settings security tests pass; unknown keys and an injected `version` are dropped; thresholds clamp.

### Phase 4 — The 11 rules
* **Prerequisites:** Phases 2 and 3.
* **Objective:** the complete V1 rule catalog.
* **Tasks:** `Rules_Provider` plus the 11 rule classes, plus one unit test class per rule covering every branch in §5.6.
* **Files affected:** `src/Rules/*`, `tests/Unit/Rules/*`.
* **Deliverable:** `Validator` produces a correct result for a realistic product context.
* **Validation:** every branch in the §12.1 table has a passing assertion; a test asserts that **no rule message contains interpolated non-integer values** (§9.5).

### Phase 5 — Checklist service + editor panel (server side)
* **Prerequisites:** Phase 4.
* **Objective:** a working, server-rendered checklist in the product editor.
* **Tasks:** `Checklist_Service` (memoization layers 1–2); `Admin/Screen`; `Admin/Editor_Meta_Box` with the escaped no-JS fallback; `Admin/Assets` with the §11.1 matrix and the inline payload.
* **Files affected:** `src/Support/Checklist_Service.php`, `src/Admin/{Screen,Editor_Meta_Box,Assets}.php`.
* **Deliverable:** opening a product shows a correct, static checklist **with JavaScript disabled**.
* **Validation:** correct results for manual rows 1–3; zero assets on unrelated screens (row 23).

### Phase 6 — REST endpoint
* **Prerequisites:** Phase 5.
* **Objective:** authenticated live validation against unsaved data.
* **Tasks:** `Rest/Validate_Controller` — route, complete `args` schema, `permission_callback`, override plumbing into `Product_Context::from_product_with_overrides()`.
* **Files affected:** `src/Rest/Validate_Controller.php`, `tests/Integration/Rest_Validate_Test.php`, `tests/Security/Rest_*`.
* **Deliverable:** a `POST` with a draft payload returns a result reflecting the unsaved data.
* **Validation:** all §12.3 REST security tests pass; array caps enforced; the product is provably unchanged after the call.

### Phase 7 — React checklist UI
* **Prerequisites:** Phases 5 and 6.
* **Objective:** the live panel.
* **Tasks:** entry, components, `useValidation`, `api.js`, `snapshot.js`, `field-watcher.js`, `focusField.js`, `editor.scss`; wire `wp_set_script_translations`; Jest tests for `snapshot.js` (signature stability) and `useValidation` (state transitions including abort and error).
* **Files affected:** `assets/js/editor/**`, `assets/scss/editor.scss`, `tests/js/*`.
* **Deliverable:** editing any watched field updates the panel within ~1 s without a page reload.
* **Validation:** manual rows 7–9, 12, 14, 26, 27; the network panel shows **no** request for keystrokes that do not change the snapshot, and never more than one in-flight request.

### Phase 8 — Publishing enforcement
* **Prerequisites:** Phases 3 and 4. May run in parallel with Phase 7.
* **Objective:** publishing is impossible while required checks fail.
* **Tasks:** `Engine/Save_Request_Reader`; `Publishing/Publish_Guard` (Layers A/B/C, skip matrix, scope, override); `Admin/Notices` (transient queue, `admin_notices`, `post_updated_messages`, `redirect_post_location`); the client-side advisory notice in the panel.
* **Files affected:** `src/Engine/Save_Request_Reader.php`, `src/Publishing/Publish_Guard.php`, `src/Admin/Notices.php`, `tests/Integration/Publish_Guard_*`.
* **Deliverable:** every bypass path in §9.8 is closed.
* **Validation:** manual rows 2, 17, 18, 19, 20, 21; all `Publish_Guard_*` integration tests pass, including fail-open on an internal exception.

### Phase 9 — Products list integration
* **Prerequisites:** Phase 5.
* **Objective:** at-a-glance readiness without opening products.
* **Tasks:** `Admin/Product_List_Column` (column, `the_posts` priming, cell render); `assets/scss/admin.scss`.
* **Files affected:** `src/Admin/Product_List_Column.php`, `assets/scss/admin.scss`.
* **Deliverable:** the Readiness column on `edit.php?post_type=product`.
* **Validation:** manual row 22 — query count for a 50-product page within ~5 of the deactivated baseline, asserted in `Product_List_Column_Test`.

### Phase 10 — Security hardening pass
* **Prerequisites:** Phases 1–9.
* **Objective:** verify, do not assume.
* **Tasks:** audit every `echo`/`printf` for escaping; every superglobal read for `wp_unslash` + sanitization; every route and handler for `permission_callback` / capability; confirm there is no `$wpdb`, no `admin-ajax`, no `dangerouslySetInnerHTML`, no outbound HTTP; run the §12.3 suite; run PHPCS with security sniffs at error severity.
* **Files affected:** all.
* **Deliverable:** `.claude/plan/security-audit.md` mapping each §9 control to the file and line implementing it.
* **Validation:** §12.3 fully green; PHPCS zero errors and zero warnings.

### Phase 11 — Test completion & performance verification
* **Prerequisites:** Phases 1–10.
* **Objective:** the suite is a safety net, not a formality.
* **Tasks:** fill gaps in §12.1 and §12.2; run the full §12.4 matrix on minimum and current WP/WC; profile the editor screen and the 50-product list.
* **Deliverable:** `.claude/plan/test-report.md`.
* **Validation:** 100 % of the §12.4 matrix passes; unit and integration suites green on both version targets.

### Phase 12 — Documentation, i18n and packaging
* **Prerequisites:** Phase 11.
* **Objective:** releasable.
* **Tasks:** finalize `readme.txt` (description, installation, FAQ, changelog, `Requires at least` / `Tested up to` / `Requires PHP` / `WC requires at least` / `WC tested up to`); `README.md` with the developer hook reference (§14) and the enforcement model (§6.3) including documented limitations; generate `languages/product-publish-guard.pot`; verify every string is translatable; `npm run build`; verify `.distignore` excludes `assets/`, `tests/`, `node_modules/`, `vendor/`, dotfiles and `.claude/`; build and install the zip on a clean site.
* **Deliverable:** `product-publish-guard.zip`.
* **Validation:** the zip installs and passes manual rows 1, 2, 22 and 24 on a clean site; it contains no `node_modules`, `vendor`, `tests` or source `assets/`; `build/editor.js` is under 40 KB minified (React is external).

---

## 16. Definition of Done (V1)

V1 is complete when **all** of the following are true. Each is objectively checkable.

**Functionality**
1. All ten V1 capabilities in §1.1 are implemented and demonstrable.
2. All 11 rules in §5.6 exist, with every documented branch reachable.
3. The checklist refreshes automatically while editing, with no mandatory manual action.
4. Every general-plan §22 step (install → configure → open → see issues → fix → see readiness update → be blocked → identify from the list) completes end to end on a clean install.
5. Nothing from §13 has been implemented.

**Security**
6. Every control in §9 is implemented and mapped in `.claude/plan/security-audit.md`.
7. All §12.3 security tests pass.
8. Every §9.8 bypass path is verified closed by an automated test.
9. PHPCS (WordPress ruleset, security sniffs at error severity) reports zero errors and zero warnings.

**Quality**
10. Unit and integration suites pass on current WP/WC **and** at the declared floor (PHP 8.0 / WP 6.5 / WC 9.0, settled 2026-09-24 — §17.24). PHP 8.0 is the Laragon CLI version and WC 9.0 is reachable through a pinned `wp-env` profile, so both halves of this item are now substantiable.
11. The full §12.4 manual matrix passes, recorded in `.claude/plan/test-report.md`.
12. No PHP notices, warnings or deprecations with `WP_DEBUG=true` on any touched screen.
13. No JavaScript console errors or React warnings on the product editor screen.
14. ESLint and Prettier pass.

**Compatibility & performance**
15. Deactivating WooCommerce leaves the site functional with a clear admin notice; the plugin does not boot.
16. HPOS compatibility is declared; the WooCommerce status page shows no incompatibility warning.
17. With the product block editor active (older WooCommerce only — WC 11 force-disables it for products), enforcement still works and the limitation is communicated. Not verifiable on the current stack; test only if a WC version that still ships the feature is targeted.
18. No plugin assets load outside the three screens in §11.1.
19. A 50-product list adds no more than ~5 queries versus the deactivated baseline.

**Release readiness**
20. `npm run build` produces `build/` from a clean checkout, and the plugin runs from the zip alone.
21. All user-facing strings are translatable with translator comments on every placeholder string; `product-publish-guard.pot` is current.
22. `readme.txt` and `README.md` are complete, including the developer hook reference and the documented enforcement limitations (the Layer C window; the block editor).
23. `uninstall.php` removes `sit_wcpg_settings` and all `sit_wcpg_*` transients, leaving no orphan data, roles or tables.
24. The packaged zip installs on a clean site and passes the Phase 12 smoke test.

---

## 17. Implementation Risks / Things to Verify Before Coding

> **Status: items 1–20 verified on 2026-09-22 against WordPress 7.1.1 / WooCommerce 11.1.1 / PHP 8.0–8.3 / Node 24.16.0.**
> The answers, with file:line evidence, are in **`.claude/plan/verification-notes.md`**. Six amendments were applied
> to this plan as a result (listed at the end of that file). The list below is kept as the provenance record and as
> the checklist to re-run when the plugin is tested on a different stack.
>
> Items **14** and **15** (product-panel events and TinyMCE) are only partially answerable from source and still need
> an interactive check during Phase 7. Items **21–23** plus two new items, **D** and **E** below, are user decisions.

Verify each against the **actual** WordPress/WooCommerce versions in the target environment (`wp-content/plugins/woocommerce`) and record the findings in `.claude/plan/verification-notes.md`. If an answer contradicts this plan, amend the plan first, then code.

**WooCommerce APIs**
1. Confirm the exact action name and signature of `woocommerce_before_product_object_save` in the installed version, and that the object's status is final at that point (Layer B depends on both).
2. Confirm `Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility()` exists and the correct feature slugs (`custom_order_tables`, `product_block_editor`).
3. Confirm how to detect an active product block editor (`Automattic\WooCommerce\Admin\Features\Features::is_enabled( 'product-block-editor' )` or another path) — A7 and manual row 25 depend on it.
4. Confirm the gallery meta key (`_product_image_gallery`) and the classic-editor POST field name (`product_image_gallery`) are still current.
5. Confirm the key sets returned by `wc_get_product_types()` and `wc_get_product_stock_status_options()`.
6. Confirm the default-category option name (`default_product_cat`) and that it holds an integer term id.

**WordPress core behaviour**
7. Confirm `wp_insert_post_data` receives **slashed** `$data` / `$postarr` (the plan assumes yes and unslashes), and that it fires for the Quick Edit (`inline-save`) and Bulk Edit paths in the installed version.
8. Confirm `wp_publish_post()` still bypasses `wp_insert_post_data` (this drives the Layer C design).
9. Confirm the exact Quick Edit and Bulk Edit POST field names for status, price, SKU and stock status, including the `-1` "no change" sentinel in Bulk Edit.
10. Confirm `option_page_capability_{$option_group}` is still honoured by `options.php` for a non-`manage_options` capability.
11. Confirm core still attaches the REST nonce middleware automatically when `wp-api-fetch` is enqueued in admin (§9.2); if not, add an explicit nonce middleware via `wp_add_inline_script`.
12. Confirm the current guidance on `load_plugin_textdomain()` timing for the installed WP version (it must not run before `init`).
13. Confirm the signatures of `update_post_caches()` and the attachment-priming function used in §11.2.

**Editor integration**
14. Verify which DOM/jQuery events the installed WooCommerce product data panel emits for price, SKU and stock changes, and whether the panel re-renders nodes (which would break naive event binding — use delegation).
15. Verify how TinyMCE change events behave in both Visual and Text modes, and whether `wp.editor` / `tinymce.get( 'content' )` is reliably available when the panel mounts (a race with the editor's own init).
16. Verify the featured-image and gallery DOM structures targeted by the MutationObservers, and prefer the most stable node (the hidden `#_thumbnail_id` input over markup).

**Environment**
17. Confirm the PHP version in this Laragon environment supports the declared baseline (now 8.0), and that Node and npm are available for `wp-scripts`.
18. Confirm whether a persistent object cache is present (this affects §11.3 layer 2, which must be correct either way).
19. Confirm whether this directory will become a git repository; if so, decide up front whether `build/` is committed or produced by CI, and record the choice.
20. Check for other active plugins that filter `wp_insert_post_data` for products or alter product statuses, which could interact with Layer A.

**Product decisions to confirm with the user before Phase 3**
21. The default severities in §5.6 — notably that SKU and tags default to `warning` while description defaults to `required`. These shape first-run behaviour.
22. The default `enforce_scope` of `authenticated` (covers REST) versus `editor` (safer for integrations).
23. That a price of exactly `0` should pass (the plan says yes — free products are legitimate). *Evidence in favour: WooCommerce's own `is_purchasable()` tests `'' !== $this->get_price()`, so core already treats `0` as a usable price.*
24. **(new)** The declared floors. **SETTLED 2026-09-24: raised to PHP 8.0 / WP 6.5 / WC 9.0.** PHP 7.4 and WooCommerce 8.2 could not be tested in this environment — only PHP 8.0/8.1/8.3 and WC 11.1.1 are installed — so the floor was raised rather than left unsubstantiated. §2 and DoD item 10 amended accordingly.
25. **(new)** Version control. The plugin directory is not a git repository. Decide whether to `git init` it and whether `build/` is committed or produced by CI. *Recommendation: `git init` the plugin, commit `build/` (there is no CI here and the zip must install from a clean checkout), ignore `node_modules/` and `vendor/`.*
