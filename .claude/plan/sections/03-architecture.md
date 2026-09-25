<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

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
