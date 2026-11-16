# SapphireIT Publish Guard for WooCommerce

A pre-publish quality checklist for WooCommerce products, with optional **server-side**
publishing enforcement.

The plugin adds a checklist panel and a pre-flight statement to the classic WooCommerce
product editor, a readiness column and filter to the products list, a list of failing live
products under **Products → Publish Guard**, and one settings screen under **WooCommerce →
Product Checklist**. Twelve built-in rules cover the title, description, short description,
featured image, image count, price, sale price validity, category, tags, SKU, stock
status, and variations and grouped products. Each rule can be disabled or set to
*required* / *warning*.

This file is the developer documentation. The user-facing description is `readme.txt`.

## Requirements

| | Minimum | Tested up to |
|---|---|---|
| PHP | 8.1 | 8.3 |
| WordPress | 6.5 | 7.1 |
| WooCommerce | 9.0 | 11.2 |

WordPress 6.5 is the floor because the plugin relies on the `Requires Plugins:` header
for dependency handling. If a requirement is not met, or WooCommerce is inactive, the
plugin shows an admin notice and does not boot.

## Built-in rules

| Id | Group | Default severity | Notes |
|---|---|---|---|
| `title` | content | required | Rejects empty and "Auto Draft" titles. |
| `description` | content | required | Fails when empty; warns below `min_description_chars` (150). |
| `short_description` | content | warning | Same, with `min_short_description_chars` (50). |
| `featured_image` | media | required | Also fails when the attachment no longer exists. Variable: the parent's image only, because variations inherit it. |
| `image_count` | media | warning | Featured + gallery, de-duplicated, against `min_images` (2). Skipped at 0 or 1. Variable: also counts every enabled variation's image and gallery. |
| `price` | pricing | required | Simple and external: the product's own regular price. Variable: every enabled variation needs a valid regular price. Grouped: skipped, it has no price of its own. |
| `sale_price` | pricing | warning | Only when a sale price is set: valid, not negative, below the regular price, dates in order. Variable: every enabled variation that is on sale. Grouped: skipped. |
| `category` | organization | required | Warns when the only category is the store default. |
| `tags` | organization | warning | At least one tag. |
| `sku` | inventory | warning | Presence only. WooCommerce already enforces uniqueness. Variable: passes when the parent has a SKU (WooCommerce inheritance), otherwise every enabled variation needs its own. |
| `stock_status` | inventory | warning | Warns on out of stock, and on managed stock that disagrees with the status. Variable: every enabled variation; parent-managed stock reads the parent's quantity. Grouped and external: skipped, they have no stock of their own. |
| `child_products` | organization | warning | Variable: at least one enabled variation. Grouped: at least one linked product that still exists (not deleted or trashed), and at least one of them published. Other types: skipped. Reports `fail`, so it blocks when set to required. |

All rules are enabled by default. A **warning** never blocks publishing; only a
**required** rule that fails does.

### Product types

Every rule judges a product by its **type family**, from `Product_Context::get_type_family()`:

| Family | Product types |
|---|---|
| `simple` | `simple`, `subscription`, and any type not listed here (Bundles, Composite, Bookings…) |
| `variable` | `variable`, `variable-subscription` |
| `grouped` | `grouped` |
| `external` | `external` |

Virtual and downloadable are flags, not families: such a product is judged exactly like
any other simple product or variation. The `sit_wcpg_product_type_family` filter (below)
moves a third-party type into another family.

`title`, `description`, `short_description`, `category` and `tags` read the parent
product on every family.

On a variable product, an **enabled variation** is one whose own status is `publish`.
Disabled (private) variations are ignored. Hidden out-of-stock variations are still
checked, so the "Hide out of stock items" setting cannot let an unpriced variation
through. A variable product fails a rule when any enabled variation fails it, and the
message gives the failing count, the enabled count and the first failing variation id.
Variations are always read from storage: the classic editor saves them by AJAX before the
form is submitted.

A variable product with **no** enabled variations skips `price`, `sale_price`,
`stock_status` and (without a parent SKU) `sku` with one shared reason, so the problem is
reported once, by `child_products`. `child_products` ships as a warning so that REST and
CSV imports, which create the parent before its children, are never blocked by it. Set it
to required to refuse such products.

A rule that does not apply to a type returns `skip` with the reason, so the merchant sees
it under *Not applicable* instead of the rule disappearing.

## Enforcement model

Publishing is enforced on the server, in three layers. The React panel only assists.

| Layer | Hook | Covers |
|---|---|---|
| A | `wp_insert_post_data` (filter, priority 10) | The classic editor, Quick Edit, Bulk Edit, and `wp_insert_post()` / `wp_update_post()` calls. Runs before the row is written, so nothing needs undoing. |
| B | `woocommerce_before_product_object_save` (action, priority 10) | WooCommerce CRUD writes: `/wc/v3/products`, the product block editor, importers, and `$product->save()` from other code. The object holds the intended final state here. |
| C | `future_to_publish` (action, priority 5) | Backstop for scheduled publishing, which bypasses Layer A (`wp_publish_post()` writes the status directly). |

When a product that is moving **into** `publish` or `future` fails a required rule:

* Layers A and B keep it as it was (`draft` or `pending`), otherwise `draft`. Field edits
  made in the same save are kept; only the publish is refused.
* Layer C reverts it to `draft` and queues a notice for the post author.
* The user sees an admin notice naming the failed rules: on the product editor for that
  product, and on the products list for every product refused by a Quick or Bulk Edit.
  Notices live in a 60-second per-user transient, `sit_wcpg_blocked_{user_id}`.

Nothing is enforced when:

* the checklist is off, or **Prevent publishing when required checks fail** is off;
* the product is already `publish` (an update to a live product);
* the request is an autosave or a revision;
* the enforcement scope excludes the request (below);
* the user may override the guard (below).

### Scope

The **Enforcement applies to** setting (`publishing.enforce_scope`):

* `authenticated` (default): every request with a signed-in user, including REST and
  code that saves products for that user.
* `editor`: only admin save payloads (classic editor, Quick Edit, Bulk Edit).

Always excluded at Layers A and B: `wp_doing_cron()`, WP-CLI, and requests with no user.
Layer C is the cron case, so the cron exclusion does not apply to it.

The WooCommerce CSV product importer is **not** excluded. It runs over AJAX as the
signed-in user and saves through `$product->save()`, so under `authenticated` a row that
fails a required rule is imported as a draft (source `crud`). The `editor` scope exempts
it. Variable and grouped parents are not refused for their missing children, because
`child_products` is a warning by default.

### Override

`can_override()` is true only when **Allow users who can manage WooCommerce to publish
anyway** is on (off by default) and the user has `manage_woocommerce`. An overridden
publish goes through, shows an "override used" notice and is added to the override log
(see "Stored data"). No capability is registered and no role is modified.

### Products that are already live

Enforcement applies only to transitions into `publish` / `future`. A live product that
later fails a rule is shown in its checklist, the list column and the catalog screen, but
it is never demoted. Otherwise, a settings change could take a whole catalogue offline.

### Readiness store

The products list filter and the catalog screen need to query readiness across the whole
catalog, so each product's result is stored in three protected post meta keys:

| Key | Holds |
|---|---|
| `_sit_wcpg_readiness` | `pass`, `warning`, `fail` or `skipped` |
| `_sit_wcpg_readiness_sig` | the signature the status was computed under |
| `_sit_wcpg_readiness_data` | `required_failures` (rule ids) and `checked_at` (a timestamp) |

The keys are written with `update_post_meta()`, so saving a flag changes neither
`post_modified` nor the checklist cache key. A duplicated product does not copy them.

**When it is written.** `Readiness_Indexer` marks a product dirty when it is saved, or when
one of its variations changes, and validates each marked product once, at `shutdown`, so
the classic editor's double save costs one validation. A request from the storefront never
validates: it queues the ids for Action Scheduler instead. That also covers a REST request
from a user who cannot `edit_products`, such as the block checkout. A trashed, deleted or
auto-draft product carries no flag. Indexing fails open, so an error is logged under
`WP_DEBUG` and the save goes ahead. Nothing is written while the checklist is switched off.

**Staleness.** The signature is
`md5( Settings::get_hash() . '|' . Checklist_Service::CACHE_VERSION . '|' . SIT_WCPG_VERSION )`.
A settings change, a newly registered rule or a plugin release therefore makes every stored
flag stale.

**The rebuild.** `Readiness_Rebuild` queues Action Scheduler jobs in the group `sit-wcpg`,
50 products at a time, and records its progress in `sit_wcpg_readiness_index`. It runs after
a settings change, on the first admin request of a new plugin version, and when the products
list, catalog or settings screen finds a stale signature. A job whose signature is no longer
current exits without working. Nothing recurs. While flags are stale, a filtered products
list and the catalog screen show a banner with the progress. Without Action Scheduler nothing
is queued, the banner says so, and `wp sit-wcpg reindex` does the same work in one process.

**The column stays live.** The readiness column validates each row when the list is shown
and never reads the store, so a stale flag cannot mislabel a row. Only the filter and the
catalog screen read stored flags.

### Documented limitations

* **Layer C window.** A scheduled product that was valid when scheduled but broken
  before its time comes is published by cron, then reverted by the backstop in the same
  request. For the length of that request it is technically live. Layer A refuses to
  *schedule* a failing product in the first place, so this needs the product to be
  broken after it was scheduled.
* **Product block editor.** The checklist panel exists only in the classic product
  editor, which from WooCommerce 11.0 is the only product editor. On WooCommerce 9.x and
  10.x, where the product block editor was an optional beta, the panel does not appear
  in it; a warning on the products list and the settings page explains why. Enforcement
  still applies, because that editor saves through `/wc/v3/products`, which Layer B
  covers.
* **Bulk Edit relative prices.** Bulk Edit's "increase / decrease by" price modes are not
  modelled. A product with no price that such an edit would raise from zero is judged on
  its stored, empty price.
* **Unsaved variation values.** The live checklist reads variations from storage. A
  value typed into a variation is not in the checklist until the variation is saved. At
  publish time it is stored, because the classic editor saves dirty variations first.
* **Grouped results on a persistent object cache.** The cached checklist of a variable
  product is flushed whenever one of its variations changes. A grouped product's is not
  flushed when a linked product changes, so on a site with a persistent object cache its
  `child_products` result in the products list can be stale for up to
  `Checklist_Service::CACHE_TTL` (300 seconds). The publish guard is not affected: it
  never reads the cache.
* **Bulk Edit of a grouped product with its children.** Bulk-publishing a grouped
  product together with its draft children can refuse the parent when `child_products`
  is required, because WordPress may save the parent before the children are published.
  Publishing the parent again succeeds.
* **Sorting.** The products list can be filtered by stored readiness, but not sorted by
  it.
* **Concurrent overrides.** The override log is an option that is read and rewritten
  without a lock, so two overrides at the same instant can lose one entry.
* **The client-side prompt is advisory.** The editor warns and asks for confirmation
  before a publish that will be refused, but it never disables the Publish button. The
  server decides and always reports the outcome.

## Hook reference

All hooks are available once the plugin has booted (`plugins_loaded`, priority 5).

### Rule API v2 — stability contract

Pro and third-party rules register through `sit_wcpg_register_rules`. The second argument
is the rule API version, so check `$api_version >= 2` before relying on anything below.

* Members tagged `@api` change only with an `API_VERSION` bump. That covers removing a
  member and changing a signature.
* Additions are not breaking, so a new method or constant does not bump the version.
  They bump `Rule_Registry::API_MINOR` instead (`1` since 1.2.0), so an add-on that needs
  one checks `API_VERSION === 2 && API_MINOR >= 1`.
* The internal Action Scheduler hooks are not part of the API.

`Product_Context::get_save_path()` says how the product is being saved. It returns one of
the `Save_Path` constants: `classic`, `quick_edit`, `bulk_edit`, `rest`, `programmatic`,
`crud`, `scheduled`, `cli` or `none`. These are the same strings `sit_wcpg_should_enforce`
receives as `$source`. A checklist read is always `none`, and no draft or REST payload can
set it. A rule can relax itself on one route:

```php
public function check( Product_Context $context, Settings $settings ): Rule_Result {
	if ( Save_Path::REST === $context->get_save_path() ) {
		return $this->skip( __( 'Not checked for API imports.', 'acme' ) );
	}
	return '' === $context->get_sku() ? $this->fail( __( 'Add a SKU.', 'acme' ) ) : $this->pass();
}
```

### `sit_wcpg_loaded` (action)

```php
do_action( 'sit_wcpg_loaded', \ProductPublishGuard\Plugin $plugin );
```

Fires once, as the last step of the plugin's boot, and only when its requirements passed.
An add-on boots here. `Plugin::instance()`, `settings()` and `registry()` are `@api`.

```php
add_action( 'sit_wcpg_loaded', static function ( \ProductPublishGuard\Plugin $plugin ): void {
	if ( 2 !== \ProductPublishGuard\Engine\Rule_Registry::API_VERSION ) {
		return;
	}
	acme_boot( $plugin->settings() );
} );
```

### `sit_wcpg_register_rules` (action)

```php
do_action( 'sit_wcpg_register_rules', Rule_Registry $registry, int $api_version );
```

Fires once per request, after the 12 built-in rules are registered and before anything
reads the registry. `$api_version` is `Rule_Registry::API_VERSION`; a callback that takes
only `$registry` keeps working. Register your own rules here. A registered rule automatically gets a
settings row (enabled + severity), a checklist row, a list-column contribution and, when
set to required, publishing enforcement. Its default settings apply until the merchant
saves the settings page.

`register()` returns `false`, and triggers `_doing_it_wrong()`, for a duplicate id.

```php
use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Settings\Settings;

final class Acme_Title_Length_Rule extends Abstract_Rule {
	public function get_id(): string { return 'acme_title_length'; }
	public function get_label(): string { return __( 'Title length', 'acme' ); }
	public function get_group(): string { return 'content'; }        // content|media|pricing|organization|inventory
	public function get_priority(): int { return 15; }               // display order only
	public function get_default_severity(): string { return Severity::WARNING; }

	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		if ( mb_strlen( $context->get_title() ) > 70 ) {
			return $this->warn( __( 'The title is longer than 70 characters.', 'acme' ) );
		}
		return $this->pass();
	}
}

add_action(
	'sit_wcpg_register_rules',
	static function ( $registry ) {
		$registry->register( new Acme_Title_Length_Rule() );
	}
);
```

Rule contract (`ProductPublishGuard\Engine\Rule_Interface`):

* **Pure.** Read product data only through `Product_Context`, never through
  `wc_get_product()` or `get_post_meta()`. The context also reflects **unsaved** editor
  values and the in-flight save, which a direct read would miss.
* **Independent.** No dependence on other rules or on execution order.
* **Return, don't throw.** Use `pass()`, `fail()`, `warn()` or `skip()`. The validator
  catches throwables, so one bad rule cannot break the screen, but a throwing rule is a
  bug.
* `fail()` means "not met". The configured severity then decides whether that is a
  required failure or a warning. `warn()` is advisory and never escalated.
* `supports( Product_Context )` returning `false` leaves the rule out entirely.
  `skip()` shows it as *not applicable* with a reason.
* `get_fix_target()` may return `[ 'selector' => '#_sku', 'label' => …, 'panel' => 'inventory' ]`
  for the "fix" link in the panel. To point one result somewhere else, return
  `$this->fail( … )->with_fix( [ … ] )`: `Rule_Result::with_fix()` returns an immutable
  copy with the new target, the way the built-in rules point a variation failure at the
  Variations tab.
* Messages are translated literals. Pass numbers through `%d` placeholders only, never
  product text such as a title or a SKU.

#### Reading the product

Besides the v1.0 field accessors (`get_title()`, `get_regular_price()`, `get_sku()`,
`get_stock_status()` and the rest), `Product_Context` has, since 1.1.0:

| Method | Returns |
|---|---|
| `get_type_family()` | `simple`, `variable`, `grouped` or `external` (the `Type_Family` constants), after the `sit_wcpg_product_type_family` filter. |
| `get_variations()` | `Variation_Data[]`, the enabled variations, read from storage. Empty unless the family is `variable`. |
| `get_child_ids()` | `int[]`, the linked products that still exist (not deleted or trashed). Empty unless the family is `grouped`. |
| `get_published_child_count()` | `int`, how many of those are published. `0` unless the family is `grouped`. |

`ProductPublishGuard\Engine\Variation_Data` is an immutable snapshot of one variation:
`get_id()`, `get_regular_price()`, `get_sale_price()`, `get_sale_from()`,
`get_sale_to()`, `get_sku()`, `get_stock_status()`, `get_manage_stock()` (one of
`Variation_Data::MANAGE_YES`, `MANAGE_NO`, `MANAGE_PARENT`), `get_stock_quantity()`
(the parent's under parent-managed stock), `get_image_id()` and
`get_gallery_image_ids()`.

```php
public function check( Product_Context $context, Settings $settings ): Rule_Result {
	if ( Type_Family::VARIABLE !== $context->get_type_family() ) {
		return $this->skip( __( 'Only variable products have variations.', 'acme' ) );
	}

	foreach ( $context->get_variations() as $variation ) {
		if ( 0 === $variation->get_image_id() ) {
			return $this->warn( __( 'A variation has no image of its own.', 'acme' ) );
		}
	}

	return $this->pass();
}
```

The rule interface and `Product_Context` are covered by the stability contract above.
`Variation_Data` is not tagged `@api`, but it keeps working across 1.x.

### `sit_wcpg_product_type_family` (filter)

```php
apply_filters( 'sit_wcpg_product_type_family', string $family, string $type, Product_Context $context );
```

The family a product type is judged as. `$type` is the WooCommerce product type, and
`$family` the built-in mapping (see "Product types"). Return one of `simple`,
`variable`, `grouped` or `external`; any other value is ignored and the built-in
mapping applies. Use it when a third-party type works like a variable or a grouped
product, for example a bundle whose products are linked like a grouped product's:

```php
use ProductPublishGuard\Engine\Type_Family;

add_filter(
	'sit_wcpg_product_type_family',
	static function ( string $family, string $type ): string {
		return 'bundle' === $type ? Type_Family::GROUPED : $family;
	},
	10,
	2
);
```

The family decides which rules apply and how: here `price`, `sale_price` and
`stock_status` stop judging the bundle as a simple product and skip it. Linked products,
though, are read only from a `WC_Product_Grouped` (its `_children`), or from the classic
form's `grouped_products[]` field. A bundle is neither, so it has no linked products in
the context and `child_products` reports it. Leave that rule a warning, or adjust its
result for your type through `sit_wcpg_validation_result`.

### `sit_wcpg_validation_result` (filter)

```php
apply_filters( 'sit_wcpg_validation_result', Validation_Result $result, Product_Context $context );
```

Filters every completed validation, whether for the editor panel, the REST endpoint,
the list column or the publish guard. The same result drives all of them, so a change
here also changes enforcement. Return a `Validation_Result`; any other return value is
ignored. The result is immutable. To change it, build a new one:

```php
use ProductPublishGuard\Engine\Validation_Result;

add_filter(
	'sit_wcpg_validation_result',
	static function ( Validation_Result $result, $context ) {
		if ( ! has_term( 'digital', 'product_cat', $context->get_product_id() ) ) {
			return $result;
		}

		// Digital products need no stock status.
		$results = array_filter(
			$result->get_results(),
			static fn ( $r ) => 'stock_status' !== $r->get_rule_id()
		);

		return Validation_Result::from_results( $context, array_values( $results ), $result->get_settings_hash() );
	},
	10,
	2
);
```

### `sit_wcpg_should_enforce` (filter)

```php
apply_filters( 'sit_wcpg_should_enforce', bool $enforce, Product_Context $context, string $source );
```

Decides whether the publish guard applies to this save, after the built-in scope and
exclusions. `$source` is one of `classic`, `quick_edit`, `bulk_edit`, `rest`,
`programmatic`, `crud` or `scheduled`. Return `false` to exempt a save, for example
products created by your own integration:

```php
add_filter(
	'sit_wcpg_should_enforce',
	static function ( bool $enforce, $context, string $source ): bool {
		return 'crud' === $source && doing_action( 'acme_sync' ) ? false : $enforce;
	},
	10,
	3
);
```

### `sit_wcpg_can_override_publish_guard` (filter)

```php
apply_filters( 'sit_wcpg_can_override_publish_guard', bool $can, int $user_id );
```

Whether `$user_id` may publish a product that fails required checks. The default is
the **publish anyway** setting AND `manage_woocommerce`. For scheduled publishes, the user
is the post author.

```php
// Only administrators may override, whatever the setting says.
add_filter(
	'sit_wcpg_can_override_publish_guard',
	static fn ( bool $can, int $user_id ): bool => $can && user_can( $user_id, 'manage_options' ),
	10,
	2
);
```

### `sit_wcpg_publish_blocked` (action)

```php
do_action( 'sit_wcpg_publish_blocked', int $product_id, int $user_id, string[] $rule_ids, string $save_path );
```

Fires once when a publish is refused, with the product's final id, including a new product
that only gets its id once the save finishes. `$user_id` is the user told about it, which is
the post author for a scheduled publish. `$rule_ids` lists the required rules that failed, and
`$save_path` is a `Save_Path` value. An exception thrown here is caught and cannot change the
refusal.

```php
add_action( 'sit_wcpg_publish_blocked', static function ( int $product_id, int $user_id, array $rule_ids, string $save_path ): void {
	acme_count_refusal( $product_id, $rule_ids, $save_path );
}, 10, 4 );
```

### `sit_wcpg_publish_overridden` (action)

```php
do_action( 'sit_wcpg_publish_overridden', int $product_id, int $user_id, string[] $rule_ids, string $save_path );
```

Fires once when a user publishes past failing required checks, with the same arguments as
`sit_wcpg_publish_blocked`. The plugin's own override log listens here.

```php
add_action( 'sit_wcpg_publish_overridden', static function ( int $product_id, int $user_id, array $rule_ids, string $save_path ): void {
	acme_notify_reviewer( $product_id, $user_id, $rule_ids );
}, 10, 4 );
```

### `sit_wcpg_settings_hash_salt` (filter)

```php
apply_filters( 'sit_wcpg_settings_hash_salt', string $salt );
```

Adds an add-on's own configuration to the settings hash, which is part of every checklist
cache key and of the readiness signature. Return a short string that changes whenever your
configuration does, and append to the value you receive. Anything but a non-empty string
is ignored, and a salt longer than 200 characters is replaced by its `md5`. With no salt
the hash is unchanged.

```php
add_filter( 'sit_wcpg_settings_hash_salt', static function ( $salt ) {
	return ( is_string( $salt ) ? $salt : '' ) . '|acme:' . md5( (string) wp_json_encode( get_option( 'acme_rules', array() ) ) );
} );
```

### `sit_wcpg_rules_changed` (action)

```php
do_action( 'sit_wcpg_rules_changed' );
```

The plugin never fires this action; add-ons do, after changing anything that alters
validation results. The plugin then reads its settings again, drops what it memoized and
queues a rebuild of the stored readiness flags, exactly as it does after a settings save.

```php
update_option( 'acme_rules', $rules );
do_action( 'sit_wcpg_rules_changed' );
```

### Extending Publish Guard (rule API 2.1)

Version 1.2.0 adds the extension points an add-on needs to ship rules of its own that read
data this plugin does not model. `Rule_Registry::API_VERSION` is still `2`, and
`Rule_Registry::API_MINOR` is `1`. Additions bump `API_MINOR`; a removal or a signature
change bumps `API_VERSION`.

| # | Extension point | Use it to |
|---|---|---|
| S1 | `Rule_Registry::API_MINOR` | detect that the points below exist |
| S2 | `sit_wcpg_loaded` | boot after the plugin |
| S3 | `Managed_Rule_Interface` | decide per product whether a rule runs, and how strictly |
| S4 | fail-open `supports()` | nothing: a rule whose `supports()` throws is skipped |
| S5 | `sit_wcpg_settings_hash_salt` | expire caches and stored readiness when your configuration changes |
| S6 | `sit_wcpg_rules_changed` | ask for stored readiness to be rebuilt |
| S7 | `Product_Context::get_extra()`, `sit_wcpg_context_extra` | give rules data of your own, read from storage |
| S8 | `sit_wcpg_save_request_extras` | judge a field typed in an admin save before it is stored |
| S9 | `draft.extras`, `sit_wcpg_draft_extras` | judge the same field in the live checklist |
| S10 | `sitWcpg.*` JavaScript filters, `sit_wcpg_editor_script_dependencies` | send that field from the editor and watch it for changes |
| S11 | `sit_wcpg_rule_groups` | name a checklist group of your own |

`tests/fixtures/sit-wcpg-probe/` is a complete add-on built on these and nothing else. It
adds one check, "Probe code", which needs a value in the post meta `_probe_code`, and
`tests/Integration/Probe_Addon_Test.php` asserts each behaviour through it. Read it as the
worked example.

#### Managed rules

```php
interface Managed_Rule_Interface extends Rule_Interface {
	public function get_severity_for( Product_Context $context ): string;
}
```

An ordinary rule is switched on and given a severity on the settings screen. A managed rule
answers for itself:

* it runs whenever `supports()` returns true, and `Settings::rule_is_enabled()` is not asked;
* its result carries `get_severity_for()` when that is a valid `Severity`, otherwise
  `get_default_severity()`, otherwise `required`;
* it is never stored, sanitized, hashed or listed on the settings screen.

A rule whose `supports()`, `get_severity_for()` or `check()` throws leaves no row and
breaks nothing else. Return false from `supports()` for a product outside your scope: the
rule is then not listed at all, so it cannot block.

```php
final class Probe_Rule extends Abstract_Rule implements Managed_Rule_Interface {
	public function get_id(): string { return 'probe_code'; }
	public function get_label(): string { return __( 'Probe code', 'acme' ); }
	public function get_group(): string { return 'probe'; }

	public function get_severity_for( Product_Context $context ): string {
		return Type_Family::EXTERNAL === $context->get_type_family() ? Severity::WARNING : Severity::REQUIRED;
	}

	public function check( Product_Context $context, Settings $settings ): Rule_Result {
		return '' !== (string) $context->get_extra( 'probe_code', '' )
			? $this->pass()
			: $this->fail( __( 'This product has no probe code.', 'acme' ) );
	}
}
```

#### Context extras

```php
$context->get_extra( string $key, $default = null );
$context->with_extras( array $extras ): Product_Context;
Product_Context::from_array( array( 'extras' => array( 'probe_code' => 'abc' ) ) );
```

Rules read only the context, so data of your own travels as an extra. A key is lowercase
letters, digits and underscores, starts with a letter and is at most 40 characters. A value
is a scalar, null, or an array of those nested at most three deep; anything else is
dropped. A seeded value wins; anything else is resolved by `sit_wcpg_context_extra`, once
per key for each context; null gives `$default`. Extras are a property of the context, like
the save path: no override array can set them. `with_extras()` returns a copy, and a later
seed wins over an earlier one.

Where each path gets its extras:

| Path | Source |
|---|---|
| Classic editor, Quick Edit, Bulk Edit | `sit_wcpg_save_request_extras`, then `sit_wcpg_context_extra` for what it left out |
| CRUD save, REST, scheduled publish, WP-CLI, stored readiness | `sit_wcpg_context_extra` |
| Live checklist in the editor | `sit_wcpg_draft_extras`, then `sit_wcpg_context_extra` for what it left out |

Leave out a field the request does not carry. An absent field must fall back to the stored
value; if you seed it as empty, a Quick Edit will refuse a product that is complete.

### `sit_wcpg_context_extra` (filter)

```php
apply_filters( 'sit_wcpg_context_extra', mixed $value, string $key, Product_Context $context );
```

Resolves an extra nothing seeded. `$value` starts as null. Return null for a key that is
not yours.

```php
add_filter( 'sit_wcpg_context_extra', static function ( $value, string $key, Product_Context $context ) {
	if ( 'probe_code' !== $key || null === $context->get_product() ) {
		return $value;
	}
	return (string) $context->get_product()->get_meta( '_probe_code' );
}, 10, 3 );
```

### `sit_wcpg_save_request_extras` (filter)

```php
apply_filters( 'sit_wcpg_save_request_extras', array $extras, array $postarr, string $source, int $post_id );
```

Runs in the publish guard when `$source` is one of `Save_Path::admin_paths()`. `$postarr`
is slashed, exactly as `wp_insert_post_data` received it, so unslash and sanitize what you
read. `$post_id` is 0 for a new product. A result that is not an array is ignored.

```php
add_filter( 'sit_wcpg_save_request_extras', static function ( $extras, array $postarr, string $source ) {
	if ( is_array( $extras ) && Save_Path::CLASSIC === $source && isset( $postarr['_probe_code'] ) ) {
		$extras['probe_code'] = sanitize_text_field( wp_unslash( $postarr['_probe_code'] ) );
	}
	return $extras;
}, 10, 3 );
```

### `sit_wcpg_draft_extras` (filter)

```php
apply_filters( 'sit_wcpg_draft_extras', array $extras, array $raw, WC_Product $product );
```

The validate route accepts `draft.extras`: an object of at most 50 keys and 20 000 bytes
encoded, otherwise the request is refused with 400 `sit_wcpg_invalid_draft`. Nothing in it
is seeded as sent. `$raw` is that object, unsanitized; return the keys you accept, cleaned.
A request that carries extras is never cached.

`Checklist_Service::validate_post()` and `validate_product()` take the extras to seed as a
third parameter, `array $extras = array()`.

```php
add_filter( 'sit_wcpg_draft_extras', static function ( $extras, $raw ) {
	if ( is_array( $extras ) && is_array( $raw ) && isset( $raw['probe_code'] ) && is_string( $raw['probe_code'] ) ) {
		$extras['probe_code'] = sanitize_text_field( $raw['probe_code'] );
	}
	return $extras;
}, 10, 2 );
```

#### Editor JavaScript filters

The editor panel applies three `@wordpress/hooks` filters:

```js
applyFilters( 'sitWcpg.snapshot.extras', {}, document, window ); // → object
applyFilters( 'sitWcpg.watcher.fields', string[] );              // → string[]
applyFilters( 'sitWcpg.watcher.containers', string[] );          // → string[]
```

* `sitWcpg.snapshot.extras` returns the values of your own controls. A non-empty plain
  object is sent as `draft.extras` and takes part in the draft's signature, so a change to
  it triggers a new check. Leave a key out when its control is not on the page.
* `sitWcpg.watcher.fields` adds selectors, inside the post form, whose `input` and `change`
  events mean the draft changed. Fields inside `#woocommerce-product-data` are watched
  already.
* `sitWcpg.watcher.containers` adds containers whose children are replaced without an
  event; they are observed for mutations.

The core selectors cannot be removed, and anything that is not a string is dropped. The
filters are read when the panel starts, so your script has to run first:

```js
import { addFilter } from '@wordpress/hooks';

addFilter( 'sitWcpg.snapshot.extras', 'acme/probe', ( extras, doc ) => {
	const field = doc.querySelector( '#_probe_code' );
	return field ? { ...extras, probe_code: field.value } : extras;
} );
```

### `sit_wcpg_editor_script_dependencies` (filter)

```php
apply_filters( 'sit_wcpg_editor_script_dependencies', string[] $deps );
```

Adds script handles the editor panel's script (`sit-wcpg-editor`) depends on, so they load
before it. Register your script first: a handle that is not registered is dropped. The
panel's own dependencies cannot be removed.

```php
add_action( 'admin_enqueue_scripts', static function (): void {
	wp_register_script( 'acme-probe-editor', plugins_url( 'build/editor.js', __FILE__ ), array( 'wp-hooks' ), '1.0.0', true );
}, 5 );

add_filter( 'sit_wcpg_editor_script_dependencies', static function ( $deps ) {
	$deps[] = 'acme-probe-editor';
	return $deps;
} );
```

### `sit_wcpg_rule_groups` (filter)

```php
apply_filters( 'sit_wcpg_rule_groups', array<string, string> $labels );
```

Adds checklist groups. A key is lowercase letters, digits and underscores, starts with a
letter and is at most 30 characters; the value is its translated label. Added groups follow
the five core ones in the order given, ten at most. The core groups keep their labels and
positions whatever is returned. The result is used by the editor panel, its no-JavaScript
fallback and the settings screen.

```php
add_filter( 'sit_wcpg_rule_groups', static function ( $labels ) {
	$labels['probe'] = __( 'Probe', 'acme' );
	return $labels;
} );
```

#### Admin screens (1.2.0)

Version 1.2.0 draws a branded header above every Publish Guard screen: the logo and version,
the tabs (Dashboard, Checklist, Live products), a Docs link and, while the upgrade prompts are
on, an "Upgrade to Pro" button and a "Channel Readiness" tab linking to the Pro sales page. An
add-on can join it. These ship in the same release as rule API 2.1, so
`Rule_Registry::API_MINOR` stays `1`.

| # | Extension point | Use it to |
|---|---|---|
| S12 | `sit_wcpg_admin_tabs` | add a tab for your own page |
| S13 | `sit_wcpg_admin_header_items` | show up to three status pills in the header |
| S14 | `Admin_Shell::render()`, `Admin_Shell::STYLE_HANDLE`, the `--sit-wcpg-*` CSS custom properties | draw the same header and use the same look on your own page |
| S15 | `sit_wcpg_show_upgrade_prompts` | hide the Pro prompts on Publish Guard's screens |

Everything an add-on passes in is validated after the filter runs and escaped on output;
anything malformed is dropped (and logged when `WP_DEBUG` is on). The core tabs cannot be
removed, replaced or relabelled.

```php
use ProductPublishGuard\Admin\Admin_Shell;

add_filter( 'sit_wcpg_admin_tabs', static function ( $tabs ) {
	$tabs[] = array(
		'id'         => 'acme-feeds',
		'label'      => __( 'Feeds', 'acme' ),
		'url'        => admin_url( 'admin.php?page=acme-feeds' ),
		'capability' => 'manage_woocommerce',
	);
	return $tabs;
} );

add_filter( 'sit_wcpg_show_upgrade_prompts', '__return_false' );

// The page callback of `acme-feeds`; enqueue Admin_Shell::STYLE_HANDLE on its screen.
function acme_feeds_page() {
	Admin_Shell::render( 'acme-feeds' );
	echo '<div class="wrap sit-wcpg-ui">…</div>';
}
```

`Admin_Shell::render()` prints `<hr class="wp-header-end">` after the header, so WordPress
moves admin notices below it. The stylesheet is registered on every admin request; enqueue it
(or list it as a dependency) on your screen. The `--sit-wcpg-*` custom properties set on
`.sit-wcpg-ui` are part of the API and change only with `API_VERSION`.

#### Admin UI kit

Inside an element with the `sit-wcpg-ui` class, the shell stylesheet sets these custom
properties. Use them in your own CSS (`color: var(--sit-wcpg-ink)`) so an add-on screen keeps
the same look. They are `@api`: renamed or removed only with a new `API_VERSION`, and
`tests/fixtures/rule-api-v2.json` lists them. The values may be tuned in a minor release.

| Property | Value | Use |
|---|---|---|
| `--sit-wcpg-navy-900` | `#16224A` | logo tile, dark surfaces |
| `--sit-wcpg-navy-700` | `#1B2A5C` | primary buttons, active tab underline |
| `--sit-wcpg-shield-500` | `#00A32A` | "on", "ready" and progress fills; never text on white |
| `--sit-wcpg-shield-700` | `#0A6B26` | success text |
| `--sit-wcpg-shield-50` | `#E6F6EB` | success tint |
| `--sit-wcpg-gold-300` | `#FFD27A` | Pro prompts only |
| `--sit-wcpg-ink` | `#0F1834` | body text |
| `--sit-wcpg-muted` | `#5B6478` | secondary text |
| `--sit-wcpg-line` | `#E3E7EF` | borders |
| `--sit-wcpg-line-strong` | `#D5DBE7` | input and button borders |
| `--sit-wcpg-ground` | `#F4F6FA` | page and muted backgrounds |
| `--sit-wcpg-surface` | `#FFFFFF` | cards |
| `--sit-wcpg-required-700`, `--sit-wcpg-required-50` | `#B42318`, `#FEECEB` | required, failing |
| `--sit-wcpg-warning-700`, `--sit-wcpg-warning-50` | `#8A5300`, `#FFF4DE` | warning |
| `--sit-wcpg-info-700`, `--sit-wcpg-info-50` | `#1B3FA6`, `#EEF2FC` | links, info, focus ring |
| `--sit-wcpg-radius-control`, `-card`, `-panel` | `9px`, `14px`, `16px` | corner radii |
| `--sit-wcpg-space-1` … `--sit-wcpg-space-8` | `4 8 12 16 20 24 32 48` px | spacing |
| `--sit-wcpg-font` | Inter, then the system UI stack | type |

Every text colour above passes WCAG 2.1 AA (4.5:1) on the backgrounds the kit pairs it with:
`ink` and `muted` on `surface` and `ground`, each `-700` on its `-50` tint and on `surface`, white
on `navy-700` and `navy-900`, `navy-900` on `gold-300`. `shield-500` is for fills, not text.

The component classes (`sit-wcpg-ui-card`, `sit-wcpg-ui-button`, `sit-wcpg-ui-pill`,
`sit-wcpg-ui-switch`, `sit-wcpg-ui-banner`, …) style Publish Guard's own screens. They are not
`@api`: use them if they help, but they may change in any release. Inter ships inside the plugin
(`assets/fonts/`); nothing is loaded from a font service.

### `sit_wcpg_admin_tabs` (filter)

```php
apply_filters( 'sit_wcpg_admin_tabs', array $tabs, string $active );
```

Adds tabs to the header. Each entry is
`array{ id: string, label: string, url: string, capability: string, badge?: string }`:

* `id` matches `^[a-z][a-z0-9-]{0,39}$` and is unique; the first entry with an id wins.
  `dashboard`, `checklist`, `live-products` and `upgrade` are Free's.
* `label` (at most 40 characters) and `badge` (at most 12) are plain text.
* `url` is an absolute URL inside this site's admin (`admin_url( … )`); anything else drops
  the tab.
* `capability` is checked with `current_user_can()`; a user without it does not see the tab.

At most four add-on tabs are printed, in the order added, after Free's tabs and before the
upgrade tab. `$active` is the id of the current tab. `Admin_Shell::tabs( $active )` returns
the validated list.

### `sit_wcpg_admin_header_items` (filter)

```php
apply_filters( 'sit_wcpg_admin_header_items', array $items, string $active );
```

Adds status pills between the Docs link and the upgrade button. Each entry is
`array{ id: string, label: string, url?: string, tone?: 'neutral'|'success'|'warning'|'danger' }`;
the rules for `id`, `label` and `url` are those of `sit_wcpg_admin_tabs`. A pill with a
`url` is a link. At most three are printed. Free adds none.

### `sit_wcpg_show_upgrade_prompts` (filter)

```php
apply_filters( 'sit_wcpg_show_upgrade_prompts', bool $show = true );
```

Return `false` to hide every Pro prompt on Publish Guard's own screens: the "Upgrade to Pro"
header button, the Channel Readiness tab, the Dashboard's Pro card and the Checklist's
"Need channel rules?" box. Publish Guard Pro returns `false` whenever it is active. Nothing
outside Publish Guard's screens ever mentions Pro.

### Reading readiness from your own code

```php
$service = \ProductPublishGuard\Plugin::instance()->checklist();

$result  = $service->validate_post( $product_id );          // ?Validation_Result, memoized per request
$summary = $service->get_summary_for_post_id( $product_id ); // ?array, the list-column summary
```

`get_summary_for_post_id()` is the one read path for anything that is not the editor.
Build on it rather than on `validate_post()` if you need readiness outside the editor.

### REST endpoint

`POST /wp-json/sit-wcpg/v1/products/{id}/validate`: returns the checklist for a product,
optionally with an unsaved `draft` object overriding stored values. Requires a
logged-in user who can `edit_post` the product, plus the usual REST cookie nonce. The
endpoint performs no writes. Its response includes `preflight`, the decision behind the
editor's one-sentence statement. It exists for the editor panel and is not a stable public
API in 1.x.

`POST /wp-json/sit-wcpg/v1/review-notice`: records the answer to the review request, with
`choice` set to `review`, `later` or `never`. Requires `manage_woocommerce` and the REST
cookie nonce. It exists for the notice's script and is not a public API.

## WP-CLI

Registered as `wp sit-wcpg` only when WP-CLI is running. Output holds product ids, statuses
and rule ids, never product text. A WP-CLI request is still excluded from enforcement, so
these commands report and never block.

### `wp sit-wcpg check [<id>...]`

Validates products live and writes nothing.

| Option | Default | Values |
|---|---|---|
| `<id>...` | the whole catalog | product ids; anything that is not a product is skipped |
| `--status=<status>` | `any` | `any` (published, scheduled, draft, pending and private), `publish`, `draft`, `pending`, `future`, `private`. Ignored when ids are given |
| `--fail-only` | off | list only products that fail a required check |
| `--format=<format>` | `table` | `table`, `json`, `count`, `ids` |
| `--strict` | off | exit 1 when any product fails a required check |

Columns: `id`, `status` (`pass`, `warning`, `fail` or `skipped`), `required_failures`
(comma-separated rule ids) and `warnings` (how many non-required checks did not pass).
The `table` format also prints a summary line, counted across everything checked even
with `--fail-only`.

### `wp sit-wcpg reindex`

Rechecks every product in this process and updates the stored flags behind the products
list filter and the catalog screen. It needs no Action Scheduler.

### Exit codes

| Code | When |
|---|---|
| `0` | the command ran; without `--strict`, failing products are still `0` |
| `1` | the checklist is switched off, or `--strict` found a failing product |

### Examples

```bash
wp sit-wcpg check --status=publish --fail-only
wp sit-wcpg check --format=json
wp sit-wcpg reindex
```

## Stored data

| Name | Kind | Removed on uninstall |
|---|---|---|
| `sit_wcpg_settings` | option (autoloaded, one row) | yes |
| `sit_wcpg_activation_notice` | option (not autoloaded), set on activation and deleted once the welcome notice is shown | yes |
| `sit_wcpg_override_log` | option (not autoloaded), at most 200 entries, each dropped after 90 days. Holds ids and rule ids, never product text | yes |
| `sit_wcpg_readiness_index` | option (not autoloaded), the state and progress of the last background recheck | yes |
| `sit_wcpg_review_notice` | option (not autoloaded): when the review request's clock started, when the checklist first stopped a product, when to ask next and whether to stop asking | yes |
| `sit_wcpg_blocked_{user_id}` | transient, 60 s | yes |
| `sit-wcpg` | Action Scheduler group of the background recheck jobs, `sit_wcpg_rebuild_readiness` and `sit_wcpg_reindex_products` (internal, not part of the rule API). They run only after a settings change, a plugin update or a save on the storefront; nothing recurs | unscheduled if Action Scheduler is loaded |
| `_sit_wcpg_readiness`, `_sit_wcpg_readiness_sig`, `_sit_wcpg_readiness_data` | protected post meta on each product: its stored readiness status, the signature it was computed under, and the failing rule ids with the time of the check | yes |
| `sit_wcpg` | object-cache group (non-persistent unless you run an object cache) | n/a |

No custom tables, post types, taxonomies, capabilities or role edits.

## Development

```bash
composer install     # dev only: PHPCS, WPCS, PHPUnit. No runtime dependencies.
npm install          # wp-scripts, wp-env, Playwright

npm run build        # build/editor.js, build/editor.css, build/admin.css, build/review-notice.js
npm run start        # watch mode

composer lint        # PHPCS (WordPress ruleset, security sniffs at error severity) + prefix guard
composer test:unit   # PHPUnit unit suite (no WordPress)
composer test:integration  # integration + security suites (see "Test environments")
npm run lint:js
npm run test:unit:js
npm run test:e2e:local     # Playwright, against the dedicated Laragon site (see "End-to-end tests")
```

There is no `vendor/` directory in the release zip: PHP classes are loaded by a
hand-written PSR-4 autoloader in `src/Autoloader.php`, and React comes from WordPress's
own `wp-element` / `wp-components` script handles rather than a bundled copy.

`build/` **is** committed, because there is no CI in this project and the plugin must
install and run from a clean checkout.

### Translations

```bash
npm run makepot      # languages/sapphireit-publish-guard.pot (needs WP-CLI on PATH)
```

The POT is generated from the PHP sources and from `build/editor.js`, not from
`assets/`. That way the JavaScript references point at the shipped file, which
`wp i18n make-json` needs. Rebuild before regenerating it. Every string with a
placeholder carries a `translators:` comment.

### Release

```bash
php bin/check-version.php 1.2.0   # every version string agrees, and equals 1.2.0
npm run package      # version check + build + makepot + version check + dist/sapphireit-publish-guard.zip
```

The version lives in eight places: the main file's `Version:` header and
`SIT_WCPG_VERSION`, `SIT_WCPG_VERSION` in `tests/bootstrap.php`, `package.json`,
`package-lock.json` (top level and `packages[""]`), `readme.txt`'s `Stable tag` and the
POT's `Project-Id-Version`. `bin/check-version.php` exits 1 unless they all agree, and,
when given a version, equal it. `npm run package` runs it twice: first with `--skip-pot`,
because the POT is regenerated by `makepot` in the same script, then in full before the
zip is built.

`bin/build-zip.php` zips the working tree under a `sapphireit-publish-guard/` folder,
leaving out everything listed in `.distignore` and every dotfile. The zip contains
`sapphireit-publish-guard.php`, `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`,
`src/`, `build/`, `assets/` (the human-readable sources of `build/`, which WordPress.org
requires) and `languages/`. It contains no `bin/`, `tests/`, `vendor/`, `node_modules/`
or tooling files.

### Test environments

The integration and security suites run on two targets, with no Docker needed:

| Target | WordPress | WooCommerce | PHP | Database |
|---|---|---|---|---|
| current | 7.1.3 | 11.2.1 | `SIT_WCPG_CURRENT_PHP`, else the PHP running Composer | `sit_wcpg_tests` |
| floor | 6.5.11 | 9.0.0 | `SIT_WCPG_FLOOR_PHP`, else `php` on `PATH` (the PHP 8.1 floor) | `sit_wcpg_tests_floor` |

```bash
composer test:setup              # install the current target (once)
composer test:setup:floor        # install the floor target (once)

composer test:integration        # integration + security suites, current target
composer test:integration:floor  # the same on the floor target
composer test:integration -- --filter=Rest_Validate_Test   # extra arguments go to PHPUnit
```

**The WordPress test suite drops every table in its database on every run.** Never point
it at a site database. `bin/install-wp-tests.php` refuses `flow_manager`, and any
database that holds an options table with a `siteurl` row under a prefix other than the
test prefix `wptests_`.

`bin/install-wp-tests.php` downloads WordPress core, the
[wp-phpunit](https://github.com/wp-phpunit/wp-phpunit) test library of the same version
(from its GitHub tag, one per target) and WooCommerce into that core's
`wp-content/plugins/`. It then creates the database and writes
`wp-tests-config-<target>.php`. It needs ext/curl, ext/zip and ext/mysqli, and MySQL
running. Options: `--target=current|floor`, `--dir`, `--db`, `--user` (default `root`),
`--pass`, `--host` (default `127.0.0.1`), `--force` (download again). Everything goes into
`--dir`, outside the repository: `SIT_WCPG_WP_TESTS_DIR` if set, else
`%LOCALAPPDATA%/sit-wcpg-wp-tests` (or the system temp dir). A part that is already
installed at the pinned version is skipped.

`bin/run-wp-tests.php`, behind the `test:integration*` scripts, points `WP_PHPUNIT__DIR`
and `WP_PHPUNIT__TESTS_CONFIG` at the target and runs PHPUnit. When the chosen PHP binary
doesn't load ext/mysqli, it adds `-d extension=mysqli` (for example with a stock system
PHP). To run the current target on another PHP:

```bash
SIT_WCPG_CURRENT_PHP=G:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe composer test:integration
```

As an alternative, `wp-env` (Docker) is configured with two profiles:

```bash
npm run env:current  # latest WordPress + latest WooCommerce, PHP 8.3
npm run env:floor    # WordPress 6.5 + WooCommerce 9.0.0, PHP 8.1 (the declared floor)
```

`env:floor` works by copying `.wp-env.floor.json` over `.wp-env.override.json`;
`env:current` removes that override again.

### End-to-end tests

The Playwright specs in `tests/e2e/` (SIT-WCPG-E2E-1…9) run in a real browser against a
dedicated Laragon site, `http://sit-wcpg-e2e.test` in `G:/laragon/www/sit-wcpg-e2e` with
the database `sit_wcpg_e2e`. They never run against a development site.

```bash
php bin/setup-e2e-site.php --install   # provision the site (once)
# Reload Laragon (Menu > Apache > Reload) so it creates the sit-wcpg-e2e.test host.
npm run test:e2e:local                 # the suite
npm run test:e2e:local -- --headed tests/e2e/e2e-1-variation-price-refused.spec.js
npm run screenshots                    # regenerates .wordpress-org/screenshot-5.png
```

`--install` downloads WordPress core into the site folder, creates the database, writes
`wp-config.php`, installs WordPress, installs WooCommerce 11.2.1 and links this plugin
into the site with a directory junction (`mklink /J`), so the site always runs the
working tree. Run without `--install`, it only activates WooCommerce and the plugin,
sets post-name permalinks and makes sure the administrator `admin` / `password` exists.
It refuses the `flow_manager` database, the flow-manager site and any site this plugin's
own folder lives in. Other options: `--path`, `--url`, `--wp`, `--wc`, `--db`,
`--db-user`, `--db-pass`, `--db-host`, `--admin-user`, `--admin-pass`,
`--reset-password` and `--with-plugin-check` (also installs and activates Plugin Check).
It needs ext/mysqli, and ext/curl and ext/zip for `--install`; a PHP without ext/mysqli
is run again with `-d extension=mysqli`.

`npm run test:e2e:local` (`bin/e2e-local.js`) maps `--base-url`, `--user` and `--pass` to
`WP_BASE_URL`, `WP_USERNAME` and `WP_PASSWORD`, defaulting to `http://sit-wcpg-e2e.test/`,
`admin` and `password`, installs Playwright's Chromium if it is missing and runs
`wp-scripts test-playwright` with `tests/e2e/playwright.config.js`. Other arguments go to
Playwright. Global setup signs in, puts the settings the specs change back to their
defaults, and creates every fixture product as a draft through the WooCommerce REST API;
global teardown deletes them. Playwright output goes to `artifacts/`, `test-results/`
and `playwright-report/`, which are neither committed nor shipped.

## Layout

```
sapphireit-publish-guard.php  Plugin header, constants, sit_wcpg_bootstrap()
uninstall.php                 Removes everything listed under "Stored data"
src/                          PHP only, PSR-4 under ProductPublishGuard\
  Autoloader.php              src/Some/Class_Name.php -> ProductPublishGuard\Some\Class_Name
  Plugin.php                  Lazy service locator + hook wiring
  Compat/                     Requirements gate, WooCommerce feature declarations
  Engine/  Rules/             The rule engine and the 12 built-in rules
  Settings/  Admin/  Rest/  Publishing/  Support/
  Cli/                        The wp sit-wcpg commands (registered only under WP-CLI)
assets/                       React and SCSS sources of build/ (shipped)
  js/editor/                  The checklist panel and the pre-flight statement
  js/review-notice/           The review request's buttons
build/                        Generated by wp-scripts (shipped)
languages/                    sapphireit-publish-guard.pot (shipped)
bin/                          Dev scripts: prefix guard, version check, release zip, test environments, E2E site (not shipped)
tests/                        PHPUnit, Jest and Playwright (not shipped)
```

## Conventions

* Everything the plugin stores or registers globally carries one prefix, spelled per
  context: `sit_wcpg_` for functions, hooks, options, transients and error codes;
  `SIT_WCPG_` for constants; `sit-wcpg-` for script handles, admin slugs, HTML ids and
  CSS classes; `sit-wcpg/v1` for the REST namespace; `sitWcpg…` for JS globals.
  `composer lint:prefix` fails on any leftover of the retired prefix.
* Options and post meta only, all listed under "Stored data". No custom tables, post
  types, taxonomies or capabilities, and no role edits.
* Rules exist only in PHP. The browser sends a draft snapshot and PHP validates it, so
  the live checklist and the publish guard can never disagree.

## License

GPL-2.0-or-later. See `LICENSE`.
