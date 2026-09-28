=== SapphireIT Publish Guard for WooCommerce ===
Contributors: sapphireit
Tags: woocommerce, products, checklist, quality control, publishing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
WC requires at least: 9.0
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A pre-publish quality checklist for WooCommerce products, with optional server-side publishing enforcement.

== Description ==

SapphireIT Publish Guard checks every product against a configurable checklist before it goes live, so incomplete listings never reach customers.

**A live checklist in the product editor.** A panel in the classic WooCommerce product editor lists every check, grouped by content, media, pricing, organization and inventory. It refreshes on its own as you type, upload images or pick categories. You don't need to save first. Each failing item links to the field that fixes it.

**Eleven built-in checks:**

* Product title
* Description (with a minimum length)
* Short description (with a minimum length)
* Featured image
* Product images (a minimum total count)
* Regular price (variable and grouped products are skipped)
* Sale price validity (lower than the regular price, sensible dates)
* Product category (warns when only the store default is used)
* Product tags
* SKU
* Stock status

Each check can be turned off, or set to **required** (it must pass before the product can be published) or **warning** (advisory only).

**Real enforcement, on the server.** When a required check fails, the product is saved as a draft instead of being published, and a notice explains why. This is enforced on the server, so it also covers Quick Edit, Bulk Edit, scheduled publishing, the WooCommerce REST API and code that saves products through WooCommerce. A disabled button in the browser would not be enforcement.

**A readiness column in the products list.** See at a glance which products are ready and which need work.

**Light footprint.**

* One settings row. No custom tables, post meta, roles or capabilities.
* Assets load only on the product editor, the products list and the settings screen.
* No outbound network requests, tracking or upsell notices.

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

No. Requests made through WP-CLI and requests with no signed-in user are never blocked, so imports and system jobs are not silently turned into drafts. Scheduled publishing is the one exception: a scheduled product that fails its required checks when its time comes is returned to draft.

= Can I limit enforcement to the admin editor screens? =

Yes. Under "Enforcement applies to", choose "Admin editor screens only". The default, "All signed-in requests", also covers the REST API and code that saves products for a signed-in user.

= Does it work with the new WooCommerce product block editor? =

The checklist panel is built for the classic product editor. Under the product block editor, which older WooCommerce versions offer as an optional feature, the panel does not appear. Publishing is still enforced, because that editor saves through the WooCommerce REST API.

= Why is the regular price check skipped for variable products? =

A variable product's price lives on its variations, and variation-level checks are not part of this version.

= Can I sort or filter the products list by readiness? =

Not in this version. Readiness is calculated when the list is shown and is not stored.

= Can developers add their own checks? =

Yes. Register a rule on the `sit_wcpg_register_rules` action. It gets a settings row, a checklist row and enforcement automatically. The developer documentation is in `README.md` inside the plugin folder.

= Does it slow down the products list? =

The readiness column adds two database queries for a whole page of products, whatever the page size.

== Screenshots ==

1. The checklist panel in the product editor.
2. The readiness column in the products list.
3. The settings screen under WooCommerce, then Product Checklist.
4. The notice shown when a product could not be published.

== Source Code ==

The editor script in `build/` is compiled. Its human-readable React and SCSS sources ship with the plugin in the `assets/` folder, and the full development repository is public at [https://github.com/rshalihin/sapphireit-publish-guard](https://github.com/rshalihin/sapphireit-publish-guard).

To rebuild the compiled files from source, install Node.js and npm, then run:

1. `git clone https://github.com/rshalihin/sapphireit-publish-guard.git`
2. `cd sapphireit-publish-guard`
3. `npm install`
4. `npm run build`

The build uses `@wordpress/scripts` and writes the compiled files to `build/`.

== Changelog ==

= 1.0.0 =
* Initial release.
* Live product checklist in the classic product editor, with eleven configurable checks.
* Server-side publishing enforcement covering the editor, Quick Edit, Bulk Edit, scheduled publishing and WooCommerce CRUD and REST saves.
* Readiness column in the products list.
* Settings screen under WooCommerce, then Product Checklist.
* Developer hooks: `sit_wcpg_register_rules`, `sit_wcpg_validation_result`, `sit_wcpg_should_enforce` and `sit_wcpg_can_override_publish_guard`.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
