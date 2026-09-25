<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

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
