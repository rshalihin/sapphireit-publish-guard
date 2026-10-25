# SapphireIT Publish Guard for WooCommerce

A pre-publish quality checklist for WooCommerce products, with optional **server-side**
publishing enforcement.

The plugin adds a checklist panel to the classic WooCommerce product editor, a readiness
column to the products list, and one settings screen under **WooCommerce → Product
Checklist**. Twelve built-in rules cover the title, description, short description,
featured image, image count, price, sale price validity, category, tags, SKU, stock
status, and variations and grouped products. Each rule can be disabled or set to
*required* / *warning*.

This file is the developer documentation. The user-facing description is `readme.txt`.

## Requirements

| | Minimum | Tested up to |
|---|---|---|
| PHP | 8.0 | 8.3 |
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

Fires once per request, after the 12 built-in rules are registered and before anything
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

The rule interface, `Product_Context` and `Variation_Data` keep working across 1.x, but
their formal stability contract (what may change, and with how much notice) arrives in
v1.3.

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
npm install          # wp-scripts, wp-env, Playwright

npm run build        # build/editor.js, build/editor.css, build/admin.css
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
php bin/check-version.php 1.1.0   # every version string agrees, and equals 1.1.0
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
| floor | 6.5.11 | 9.0.0 | `SIT_WCPG_FLOOR_PHP`, else `php` on `PATH` (the PHP 8.0 floor) | `sit_wcpg_tests_floor` |

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
PHP 8.0). To run the current target on another PHP:

```bash
SIT_WCPG_CURRENT_PHP=G:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe composer test:integration
```

As an alternative, `wp-env` (Docker) is configured with two profiles:

```bash
npm run env:current  # latest WordPress + latest WooCommerce, PHP 8.3
npm run env:floor    # WordPress 6.5 + WooCommerce 9.0.0, PHP 8.0 (the declared floor)
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
uninstall.php                 Removes sit_wcpg_settings and the notice transients
src/                          PHP only, PSR-4 under ProductPublishGuard\
  Autoloader.php              src/Some/Class_Name.php -> ProductPublishGuard\Some\Class_Name
  Plugin.php                  Lazy service locator + hook wiring
  Compat/                     Requirements gate, WooCommerce feature declarations
  Engine/  Rules/             The rule engine and the 12 built-in rules
  Settings/  Admin/  Rest/  Publishing/  Support/
assets/                       React and SCSS sources of build/ (shipped)
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
* One option row (`sit_wcpg_settings`). No custom tables, post types, taxonomies or
  capabilities, and no role edits.
* Rules exist only in PHP. The browser sends a draft snapshot and PHP validates it, so
  the live checklist and the publish guard can never disagree.

## License

GPL-2.0-or-later. See `LICENSE`.
