<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

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
├── .wordpress-org/                    # WP.org listing assets (banner, icon, screenshots; SVN /assets) — never shipped
│   ├── icon.svg  icon-128x128.png  icon-256x256.png
│   ├── banner-772x250.png  banner-1544x500.png
│   ├── screenshot-1.png … screenshot-4.png  # match the readme == Screenshots == captions, in order
│   └── src/                           # SVG sources the PNGs are rendered from
├── bin/                               # DEV ONLY (not shipped)
│   ├── check-prefix.php               # `composer lint:prefix`
│   └── build-zip.php                  # `npm run package` → dist/product-publish-guard.zip (Phase 12)
├── dist/                              # GENERATED release zip — git-ignored, never shipped
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
├── assets/                            # SOURCES (shipped: WordPress.org requires human-readable source)
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
| `src/Engine/Save_Request_Reader.php` | Knows the shape of every admin save payload (classic editor, quick edit, bulk edit) and converts it to a normalized override array. Handles `wp_unslash`, the missing-vs-empty distinction, `-1` sentinels, per-field sanitization. **Verified field names** (`verification-notes.md` §9): status `_status` (`-1` = no change, and core may omit `post_status` entirely); WooCommerce `_sku`, `_regular_price`, `_sale_price`, `_stock_status`, `_stock`, `_manage_stock`; bulk-edit modifiers `change_regular_price` / `change_sale_price` / `change_stock` (absent/empty = no change); featured image `_thumbnail_id` where **any value `<= 0` means "no image"**; gallery `product_image_gallery` (comma-separated ids); quick-edit marker `woocommerce_quick_edit`. **Isolates all `$_POST` knowledge in one file.** | `read( array $post_data, array $insert_data, int $post_id = 0 ): array`, `detect_source( array $post_data, int $post_id = 0 ): string` | `Publish_Guard` |
| `src/Engine/Validator.php` | Runs active rules against a context, applies configured severity to rule outcomes (§5.5), builds `Validation_Result`, fires the `sit_wcpg_validation_result` filter. Wraps each rule call in try/catch so one bad rule cannot break the screen. | `validate( Product_Context ): Validation_Result` | `Checklist_Service` |
| `src/Rules/Rules_Provider.php` | Instantiates the 11 built-in rules into the registry, then `do_action( 'sit_wcpg_register_rules', $registry )`. | `populate( Rule_Registry )` | `Plugin::registry()` |
| `src/Rules/*_Rule.php` | One rule each (§5.6). | `check( Product_Context, Settings ): Rule_Result` | `Validator` |
| `src/Support/Checklist_Service.php` | Public façade. Owns memoization: a static per-request array plus optional `wp_cache_get/set` in group `sit_wcpg`, keyed `v1:{id}:{post_modified_gmt}:{settings_hash}` — only for override-free validations. | `validate_post( $id, $overrides = [] )`, `validate_context( Product_Context )` (never cached; the guard's entry point), `get_summary_for_post_id( $id )`, `flush_post( $id )` | Meta box, REST, list column, guard |
| `src/Rest/Validate_Controller.php` | Registers `sit-wcpg/v1`, the full `args` schema with per-field `sanitize_callback`/`validate_callback`, the `permission_callback`, and response assembly. | `register_routes()`, `validate_item()`, `permissions_check()` | `rest_api_init` |
| `src/Publishing/Publish_Guard.php` | The three enforcement layers (§6.3), the skip matrix, scope resolution, override checks, notice queuing. | `filter_insert_post_data()`, `guard_product_object_save()`, `guard_scheduled_publish()`, `can_override()` | `wp_insert_post_data`, `woocommerce_before_product_object_save`, `future_to_publish` |
| `src/Admin/Screen.php` | Screen predicates: `is_product_edit_screen()`, `is_product_list_screen()`, `is_settings_screen()`, `current_product_id()`. The single place that knows hook suffixes. | static predicates | Assets, meta box, list column |
| `src/Admin/Assets.php` | Conditional enqueue (§11.1). Reads `build/editor.asset.php` for deps/version. `wp_set_script_translations()`. Prints the bootstrap payload via `wp_add_inline_script()` with `wp_json_encode()`, never string concatenation. | `enqueue( $hook_suffix )` | `admin_enqueue_scripts` |
| `src/Admin/Editor_Meta_Box.php` | Registers the meta box; renders the mount node plus a server-rendered, fully escaped no-JS fallback list. | `register()`, `render( WP_Post )` | `add_meta_boxes_product` |
| `src/Admin/Product_List_Column.php` | Column registration, cache priming on `the_posts`, cell rendering. | `add_column()`, `prime_caches()`, `render_column()` | `manage_edit-product_columns`, `the_posts`, `manage_product_posts_custom_column` |
| `src/Admin/Block_Editor_Notice.php` | The A7 / §7.2.1 warning that the checklist panel is unavailable while WooCommerce's product block editor is on. Screen, setting and capability are checked before the (cheap) editor detection. *Added 2026-09-27.* | `register()`, `render()` | `admin_notices` |
| `src/Admin/Notices.php` | Per-user transient queue (`sit_wcpg_blocked_{user_id}`, §6.3.1) for "publishing blocked" and "override used" events; renders on `admin_notices`; overrides `post_updated_messages` for products; adds the `sit_wcpg_blocked` redirect arg. | `queue( $user_id, $post_id, Validation_Result, $kind )`, `flag_redirect( $post_id )`, `render()` | `Publish_Guard`, `admin_notices` |

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
