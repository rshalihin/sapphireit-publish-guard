<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

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
4. Collect gallery ids **only if** the `image_count` rule is enabled, and prime those too. *Amended in Phase 9:* steps 3 and 4 share **one** `_prime_post_caches()` call over the merged, de-duplicated id list, so the attachments cost one query pair whatever the page holds. Steps 1–2 usually find everything already cached (core's main query primes post, meta and term caches); they stay for a main query filtered to skip that.

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
