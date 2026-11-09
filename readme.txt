=== SapphireIT Publish Guard for WooCommerce ===
Contributors: sapphireit
Tags: woocommerce, products, checklist, quality control, publishing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 9.0
WC tested up to: 11.2
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A pre-publish quality checklist for WooCommerce products, with optional server-side publishing enforcement.

== Description ==

Products published without an identifier or an image can get disapproved by shopping channels, and a disapproved product earns nothing. SapphireIT Publish Guard stops them at the publish button. It checks every product against a configurable checklist of required fields before it goes live, so incomplete products never reach your customers.

It is product validation for the WooCommerce catalog you already have. A missing SKU, an empty description, a variation without a price or a thin image gallery shows up while you edit, and a product that fails a required check is kept as a draft until it is fixed.

Google expects an identifier such as a GTIN. This plugin checks the identifier WooCommerce itself has, the SKU, so a product with none is caught before it goes live.

**A live checklist in the product editor.** A panel in the classic WooCommerce product editor lists every check, grouped by content, media, pricing, organization and inventory. It refreshes on its own as you type, upload images or pick categories. You don't need to save first. Each failing item links to the field that fixes it.

**Twelve built-in checks:**

* Product title
* Description (with a minimum length)
* Short description (with a minimum length)
* Featured image
* Product images (a minimum total count)
* Regular price (every enabled variation on variable products)
* Sale price validity (lower than the regular price, sensible dates)
* Product category (warns when only the store default is used)
* Product tags
* Variations and grouped products (a variable product needs an enabled variation, a grouped product a published linked product)
* SKU
* Stock status

Every check knows the product type. Variable products are checked variation by variation. A check that does not apply to a type, such as stock status on an external product, is shown as not applicable with the reason, instead of blocking the product. Every failure says what is missing and where to fix it, for example "Product data → Variations".

Each check can be turned off, or set to **required** (it must pass before the product can be published) or **warning** (advisory only).

**Real enforcement, on the server.** When a required check fails, the product is saved as a draft instead of being published, and a notice explains why. This is enforced on the server, so it also covers Quick Edit, Bulk Edit, scheduled publishing, the WooCommerce REST API and code that saves products through WooCommerce. A disabled button in the browser would not be enforcement.

**A readiness column in the products list.** See at a glance which products are ready and which need work.

**A readiness filter across the whole catalog.** A dropdown above the products list finds products that are Ready, have Warnings, are Not ready or are Not checked yet, on every page of the list and not just the one you are looking at.

**Live products that would fail your rules.** A screen under Products, then Publish Guard, lists every published product that would be refused if it were published today, and the required checks it fails. They stay live: Publish Guard never takes a product offline.

**A pre-flight statement in the editor.** One sentence tells you whether this product can be published right now, how many required checks stand in the way, and what will happen when you press Publish.

**WP-CLI.** `wp sit-wcpg check` runs the checklist across the catalog from the command line, and `--strict` exits with an error when a product fails a required check. `wp sit-wcpg reindex` rechecks every product straight away.

**Overrides are recorded.** If you let some users publish anyway, each override is recorded for your records; nothing is shown or sent anywhere.

**Light footprint.**

* Five options (the settings, a temporary flag removed once the welcome notice has been shown, the capped override record, the progress of the background recheck and the state of the review request) and three hidden post meta keys on each product holding its stored readiness. No custom tables, roles or capabilities. All of it is removed when you delete the plugin.
* Background rechecks run through WooCommerce's own Action Scheduler, only after something has changed, such as your settings or the plugin version. Nothing runs on a recurring schedule.
* Assets load only on the product editor, the products list, the catalog screen and the settings screen, once on the Plugins screen for the welcome notice, and, when the review request is due, a small script for it on those list, catalog and settings screens.
* No outbound network requests, tracking or upsell notices. After 30 days, and only once the checklist has stopped an incomplete product, it asks once for a review; you can dismiss it for good.

SapphireIT Publish Guard for WooCommerce is an independent plugin. It is not affiliated with or endorsed by WooCommerce or Automattic.

== Installation ==

1. Install and activate WooCommerce 9.0 or newer.
2. Upload the plugin to `/wp-content/plugins/sapphireit-publish-guard`, or go to Plugins, then Add New, then Upload Plugin and choose `sapphireit-publish-guard.zip`.
3. Activate SapphireIT Publish Guard for WooCommerce.
4. Go to WooCommerce, then Product Checklist. Choose which checks run, which are required, the content thresholds and the publishing behaviour.

The settings screen is available to anyone who can manage WooCommerce (administrators and shop managers).

== Frequently Asked Questions ==

= Does it unpublish products that are already live? =

No. Enforcement applies only when a product moves into Published or Scheduled. A live product that later fails a check shows the problem in its checklist and in the products list, but it is never taken offline automatically.

= Can an administrator publish anyway? =

Only if you allow it. Turn on "Allow users who can manage WooCommerce to publish anyway" on the settings screen. It is off by default, so nobody can publish past a failing required check.

= Does it block imports, WP-CLI or cron jobs? =

WP-CLI, cron jobs and requests with no signed-in user are never blocked.

The WooCommerce CSV product importer and REST API clients run as a signed-in user, so their saves are checked like any other. Under the default "All signed-in requests" scope, an imported product that fails a required check is saved as a draft instead of being published. To exempt them, choose "Admin editor screens only" under "Enforcement applies to".

Variable and grouped products are not blocked because their variations or linked products come later in the import file: "Variations and grouped products" is a warning by default.

Scheduled publishing is the one exception to the cron rule: a scheduled product that fails its required checks when its time comes is returned to draft.

= Can I limit enforcement to the admin editor screens? =

Yes. Under "Enforcement applies to", choose "Admin editor screens only". The default, "All signed-in requests", also covers the REST API and code that saves products for a signed-in user.

= Which product editor does it support? =

The classic WooCommerce product editor, which from WooCommerce 11.0 is the only one. On WooCommerce 9.x and 10.x, where the product block editor was an optional beta, the checklist panel does not appear in it, but publishing is still enforced, because that editor saves through the WooCommerce REST API.

= Does it check variations? =

Yes. Regular price, sale price, SKU and stock status are checked on every enabled variation, and a variable product fails a check when any enabled variation fails it. Disabled variations are ignored. As in WooCommerce, a variation without a SKU of its own passes when the parent product has one.

= Can it block a variable product that has no variations? =

Yes. Raise "Variations and grouped products" to Required in the settings. It then also requires a grouped product to link at least one published product. It ships as a warning, so imports that create the product before its variations are never blocked by it.

= Does it check GTIN, UPC or EAN barcodes? =

Not in this plugin. The SKU check covers the identifier WooCommerce itself uses.

= Can I sort or filter the products list by readiness? =

You can filter it but not sort it. The dropdown above the products list shows the products that are Ready, have Warnings, are Not ready or are Not checked yet, across the whole catalog.

= What happens when I change the settings? =

Your products are rechecked against the new settings in the background, through WooCommerce's Action Scheduler. While that runs, a filtered products list shows how far the recheck has got, so you know the results aren't complete yet. The readiness column is worked out live, so it is right straight away. Nothing is unpublished.

If Action Scheduler isn't available, the list says so, and `wp sit-wcpg reindex` updates the filter.

= Who overrode a publish? =

When you allow overrides, each one is recorded: who published, which required checks failed and when. Up to 200 entries are kept, each for up to 90 days, and they hold ids and check names only, never product text. If that user is later deleted, their entries are anonymized. The record stays in your database and is never sent anywhere. Viewing it is not part of the free plugin.

= Can developers add their own checks? =

Yes. Register a rule on the `sit_wcpg_register_rules` action. It gets a settings row, a checklist row and enforcement automatically. The developer documentation is in `README.md` inside the plugin folder.

= Does it slow down the products list? =

The readiness column adds three database queries for a page of simple products, and four for a page that includes variable or grouped products, whatever the page size or the number of variations. Filtering by readiness adds at most one more.

== Screenshots ==

1. The checklist panel in the product editor.
2. The readiness column in the products list.
3. The settings screen under WooCommerce, then Product Checklist.
4. The notice shown when a product could not be published.
5. Publishing refused, with the reason and where to fix it.
6. Live products that would fail your rules.
7. The pre-flight statement on a product that can't be published yet.

== Source Code ==

The editor script in `build/` is compiled. Its human-readable React and SCSS sources ship with the plugin in the `assets/` folder, and the full development repository is public at [https://github.com/rshalihin/sapphireit-publish-guard](https://github.com/rshalihin/sapphireit-publish-guard).

To rebuild the compiled files from source, install Node.js and npm, then run:

1. `git clone https://github.com/rshalihin/sapphireit-publish-guard.git`
2. `cd sapphireit-publish-guard`
3. `npm install`
4. `npm run build`

The build uses `@wordpress/scripts` and writes the compiled files to `build/`.

== Changelog ==

= 1.2.0 =
* New: a readiness filter on the products list finds products that are ready, have warnings, are not ready or have not been checked yet, across the whole catalog. Readiness is stored when a product is saved and rechecked in the background when the settings change.
* New: overrides are recorded: who published past which required checks, and when. At most 200 entries, each kept for up to 90 days. Nothing is shown or sent anywhere.
* New: a pre-flight statement in the product editor says in one sentence whether the product can be published right now.
* Improved: the settings screen explains what each check protects against, and groups the checks.
* Changed: Requires PHP 8.1.
* New for developers: rules can read how a product is being saved through `Product_Context::get_save_path()`.
* New for developers: the rule API is versioned and documented. `sit_wcpg_register_rules` now passes the API version, and `sit_wcpg_publish_blocked` and `sit_wcpg_publish_overridden` report each outcome.
* New: Products, then Publish Guard, lists the live products that would fail your rules, and why.
* New: WP-CLI commands `wp sit-wcpg check` and `wp sit-wcpg reindex`.
* New: after 30 days, and only once the checklist has stopped an incomplete product, it asks once for a review. You can dismiss it for good.
* Improved: the plugin description and FAQ cover these features.

= 1.1.0 =
* New: variation-level checks. On variable products, regular price, sale price, SKU and stock status are checked on every enabled variation. Disabled variations are ignored.
* New: the "Variations and grouped products" check. A variable product needs at least one enabled variation, and a grouped product at least one linked product that exists and is published. It ships as a warning.
* New: a defined product-type matrix. Every check either applies to a simple, variable, grouped or external product (subscriptions included), or is shown as not applicable with the reason, so no product is blocked by a check that does not apply to its type.
* Improved: every failure message is rewritten to name the field, the tab and the fix. A variation failure gives the first failing variation and opens the Variations tab.
* New: a one-time welcome notice on the Plugins screen after activation, linking to the settings.
* New: a Settings link in the plugin's row on the Plugins screen.
* New: the settings screen's footer shows the plugin version and a link to leave a review on WordPress.org.
* Fixed: the validation REST endpoint now refuses ids that are not products.
* Fixed: when one request refused several new products, such as a CSV import, only one of their notices was kept.
* Tested up to WooCommerce 11.2.

= 1.0.0 =
* Initial release.
* Live product checklist in the classic product editor, with eleven configurable checks.
* Server-side publishing enforcement covering the editor, Quick Edit, Bulk Edit, scheduled publishing and WooCommerce CRUD and REST saves.
* Readiness column in the products list.
* Settings screen under WooCommerce, then Product Checklist.
* Developer hooks: `sit_wcpg_register_rules`, `sit_wcpg_validation_result`, `sit_wcpg_should_enforce` and `sit_wcpg_can_override_publish_guard`.

== Upgrade Notice ==

= 1.2.0 =
Requires PHP 8.1. After updating, products are rechecked in the background so the new readiness filter can find them.

= 1.1.0 =
Variable products are now checked per variation. A variable product with an unpriced enabled variation can no longer be published while Regular price is required. Products that are already live are not changed.

= 1.0.0 =
Initial release.
