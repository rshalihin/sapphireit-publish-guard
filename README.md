# Product Publish Guard

A pre-publish quality checklist for WooCommerce products, with optional **server-side**
publishing enforcement.

The plugin adds a checklist panel to the classic WooCommerce product editor, a readiness
column to the products list, and one settings screen under **WooCommerce → Product
Checklist**. Eleven built-in rules cover the title, description, short description,
featured image, image count, price, sale price validity, category, tags, SKU and stock
status. Each rule can be disabled or set to *required* / *warning*.

This file is the developer documentation. The user-facing description is `readme.txt`.

## Requirements

| | Minimum | Tested up to |
|---|---|---|
| PHP | 8.0 | 8.3 |
| WordPress | 6.5 | 7.1 |
| WooCommerce | 9.0 | 11.1 |

WordPress 6.5 is the floor because the plugin relies on the `Requires Plugins:` header
for dependency handling. If a requirement is not met, or WooCommerce is inactive, the
plugin shows an admin notice and does not boot.

## Built-in rules

| Id | Group | Default severity | Notes |
|---|---|---|---|
| `title` | content | required | Rejects empty and "Auto Draft" titles. |
| `description` | content | required | Fails when empty; warns below `min_description_chars` (150). |
| `short_description` | content | warning | Same, with `min_short_description_chars` (50). |
| `featured_image` | media | required | Also fails when the attachment no longer exists. |
| `image_count` | media | warning | Featured + gallery, de-duplicated, against `min_images` (2). Skipped at 0 or 1. |
| `price` | pricing | required | Skipped for variable and grouped products. |
| `sale_price` | pricing | warning | Only when a sale price is set: valid, not negative, below the regular price, dates in order. |
| `category` | organization | required | Warns when the only category is the store default. |
| `tags` | organization | warning | At least one tag. |
| `sku` | inventory | warning | Presence only. WooCommerce already enforces uniqueness. |
| `stock_status` | inventory | warning | Warns on out of stock, and on managed stock that disagrees with the status. |

All rules are enabled by default. A **warning** never blocks publishing; only a
**required** rule that fails does.

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
Imports and system jobs are therefore never silently demoted. Layer C is the cron case,
so the cron exclusion does not apply to it.

### Override

`can_override()` is true only when **Allow users who can manage WooCommerce to publish
anyway** is on (off by default) and the user has `manage_woocommerce`. An overridden
publish goes through and shows an "override used" notice. No capability is registered
and no role is modified.

### Products that are already live

Enforcement applies only to transitions into `publish` / `future`. A live product that
later fails a rule is shown in its checklist and the list column, but it is never
demoted. Otherwise, a settings change could take a whole catalogue offline.

### Documented limitations

* **Layer C window.** A scheduled product that was valid when scheduled but broken
  before its time comes is published by cron, then reverted by the backstop in the same
  request. For the length of that request it is technically live. Layer A refuses to
  *schedule* a failing product in the first place, so this needs the product to be
  broken after it was scheduled.
* **Product block editor.** The checklist panel exists only in the classic product
  editor. Under the product block editor (an optional feature in older WooCommerce,
  force-disabled for products in WooCommerce 11), the panel does not appear; a warning on
  the products list and the settings page explains why. Enforcement still applies,
  because that editor saves through `/wc/v3/products`, which Layer B covers.
* **Bulk Edit relative prices.** Bulk Edit's "increase / decrease by" price modes are not
  modelled. A product with no price that such an edit would raise from zero is judged on
  its stored, empty price.
* **Variations.** `price` skips variable products; variations are not checked.
* **Sorting and filtering.** Readiness is computed on demand and never stored, so the
  products list cannot be sorted or filtered by it.
* **The client-side prompt is advisory.** The editor warns and asks for confirmation
  before a publish that will be refused, but it never disables the Publish button. The
  server decides and always reports the outcome.

## Hook reference

All hooks are available once the plugin has booted (`plugins_loaded`, priority 5).

### `sit_wcpg_register_rules` (action)

```php
do_action( 'sit_wcpg_register_rules', Rule_Registry $registry );
```

Fires once per request, after the 11 built-in rules are registered and before anything
reads the registry. Register your own rules here. A registered rule automatically gets a
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
  for the "fix" link in the panel.

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
endpoint performs no writes. It exists for the editor panel and is not a stable public
API in 1.x.

## Stored data

| Name | Kind | Removed on uninstall |
|---|---|---|
| `sit_wcpg_settings` | option (autoloaded, one row) | yes |
| `sit_wcpg_blocked_{user_id}` | transient, 60 s | yes |
| `sit_wcpg` | object-cache group (non-persistent unless you run an object cache) | n/a |

No custom tables, post types, taxonomies, post meta, capabilities or role edits.

## Development

```bash
composer install     # dev only: PHPCS, WPCS, PHPUnit. No runtime dependencies.
npm install          # wp-scripts + wp-env

npm run build        # build/editor.js, build/editor.css, build/admin.css
npm run start        # watch mode

composer lint        # PHPCS (WordPress ruleset, security sniffs at error severity) + prefix guard
composer test        # PHPUnit
npm run lint:js
npm run test:unit:js
```

There is no `vendor/` directory in the release zip: PHP classes are loaded by a
hand-written PSR-4 autoloader in `src/Autoloader.php`, and React comes from WordPress's
own `wp-element` / `wp-components` script handles rather than a bundled copy.

`build/` **is** committed, because there is no CI in this project and the plugin must
install and run from a clean checkout.

### Translations

```bash
npm run makepot      # languages/product-publish-guard.pot (needs WP-CLI on PATH)
```

The POT is generated from the PHP sources and from `build/editor.js`, not from
`assets/`. That way the JavaScript references point at the shipped file, which
`wp i18n make-json` needs. Rebuild before regenerating it. Every string with a
placeholder carries a `translators:` comment.

### Release

```bash
npm run package      # build + makepot + dist/product-publish-guard.zip
```

`bin/build-zip.php` zips the working tree under a `product-publish-guard/` folder,
leaving out everything listed in `.distignore` and every dotfile. The zip contains
`product-publish-guard.php`, `uninstall.php`, `readme.txt`, `README.md`, `LICENSE`,
`src/`, `build/` and `languages/`. It contains no `assets/`, `tests/`, `vendor/`,
`node_modules/` or tooling files.

### Test environments

`wp-env` is configured with two profiles:

```bash
npm run env:current  # latest WordPress + latest WooCommerce, PHP 8.3
npm run env:floor    # WordPress 6.5 + WooCommerce 9.0.0, PHP 8.0 (the declared floor)
```

`env:floor` works by copying `.wp-env.floor.json` over `.wp-env.override.json`;
`env:current` removes that override again.

## Layout

```
product-publish-guard.php   Plugin header, constants, sit_wcpg_bootstrap()
uninstall.php               Removes sit_wcpg_settings and the notice transients
src/                        PHP only, PSR-4 under ProductPublishGuard\
  Autoloader.php            src/Some/Class_Name.php -> ProductPublishGuard\Some\Class_Name
  Plugin.php                Lazy service locator + hook wiring
  Compat/                   Requirements gate, WooCommerce feature declarations
  Engine/  Rules/           The rule engine and the 11 built-in rules
  Settings/  Admin/  Rest/  Publishing/  Support/
assets/                     React and SCSS sources of build/ (shipped)
build/                      Generated by wp-scripts (shipped)
languages/                  product-publish-guard.pot (shipped)
bin/                        Dev scripts: prefix guard, release zip (not shipped)
tests/                      PHPUnit and Jest (not shipped)
```

## Conventions

* Everything the plugin stores or registers globally carries one prefix, spelled per
  context: `sit_wcpg_` for functions, hooks, options, transients and error codes;
  `SIT_WCPG_` for constants; `sit-wcpg-` for script handles, admin slugs, HTML ids and
  CSS classes; `sit-wcpg/v1` for the REST namespace; `sitWcpg…` for JS globals.
  `composer lint:prefix` fails on any leftover of the retired prefix.
* One option row (`sit_wcpg_settings`). No custom tables, post types, taxonomies or
  capabilities, and no role edits.
* Rules exist only in PHP. The browser sends a draft snapshot and PHP validates it, so
  the live checklist and the publish guard can never disagree.

## License

GPL-2.0-or-later. See `LICENSE`.
