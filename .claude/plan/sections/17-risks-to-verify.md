<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

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
