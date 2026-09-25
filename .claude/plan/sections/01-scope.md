<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 1. Scope analysis of `general-plan.md`

### 1.1 Exact V1/MVP scope (derived from §3, §7, §19, §22)

The V1 deliverable is complete when a merchant can do the eight things listed in general-plan §22. Concretely:

| # | V1 capability | Source |
|---|---|---|
| 1 | Checklist panel inside the WooCommerce product editor | §3.1, §19 |
| 2 | Pass / Warning / Fail per rule, with a specific, actionable message | §3.2 |
| 3 | 11 built-in rules (title, description, short description, featured image, image count, price, sale price validity, category, tags, SKU, stock status) | §3.1, §7 |
| 4 | Per-rule enable/disable + per-rule severity (required vs warning) | §3.3, §19 |
| 5 | Content-length thresholds + minimum image count | §3.4 |
| 6 | Readiness summary (`n / m` + ready/not-ready state) | §2.B, §19 |
| 7 | Option: prevent publishing when required checks fail, enforced **server-side** | §3.5, §15.11 |
| 8 | Readiness indicator column in the products list | §3.6 |
| 9 | One settings screen under the WooCommerce menu | §3.7, §19 |
| 10 | Checklist refreshes automatically while editing (no mandatory "Run Check" button) | §4 |

### 1.2 Explicitly deferred (V2+)

Variation QA, attribute/brand/weight/dimension/shipping/tax rules, image dimension & alt-text rules, quality scoring, duplicate title/SKU detection, bulk scanning, scheduled audits, reports, CSV export, email notifications, approval workflow, roles, reviewer notes, activity history, custom rule builder UI, webhooks/API integrations, AI features, SEO features. See §13 for the enforced list.

### 1.3 Ambiguities found, and the resolution adopted

| # | Ambiguity in general-plan | Resolution for V1 |
|---|---|---|
| A1 | §3.3 shows "enable/disable" while §2.C and §19 show "Required" vs "Optional" groups — two different concepts. | Unified into **one model**: each rule has `enabled` (bool) + `severity` (`required` \| `warning`). "Optional" = enabled with severity `warning`. "Disabled" = not evaluated at all. This removes a whole settings dimension without losing expressiveness. |
| A2 | §2.A lists "Description required" and "Minimum description length" as separate rules; §19 shows only one description line. | **One rule per field** that reports two distinct outcomes: empty → fail; below threshold → warning. Avoids two checklist rows for one field and matches the §1 mock (`✓ Product description` + `! Product description too short` are the same field in different states). |
| A3 | §1 mock shows "Readiness: 6 / 8"; §2.B shows "Readiness: 80%"; §13.6 forbids scoring. | Use **`passed / evaluated` counts plus a ready/not-ready state**. No percentage, no score. Percentages read as a score and §13.6 forbids scoring. |
| A4 | §3.5 says "prevent publishing"; §1 says "before publishing **or updating**". | Enforcement applies to **transitions into `publish` or `future`** only. An already-published product that later breaks a rule is flagged (checklist + list column) but **not** auto-demoted — silent unpublishing of live products is destructive and is not requested. Documented in §6.4. |
| A5 | §3.6 product-list indicator vs §13.5 "no custom tables / avoid persistence". | Computed on demand, with request-level memoization and optional object-cache reuse. **No persisted readiness meta in V1.** §11 defines the priming strategy that makes this fast; §14 defines the seam where persistence can be added in V2 if profiling demands it. |
| A6 | §3.7 lists four settings tabs (Checklist / Publishing Rules / Appearance / Advanced) but then says "keep the actual settings minimal". | **One page, three sections** (Rules, Content thresholds, Publishing). No Appearance, no Advanced — there is nothing to put in them in V1. |
| A7 | §17 says "Block-based/current WooCommerce admin UI where relevant" — unclear whether the new WooCommerce product block editor is a target. | **V1 targets the classic product editor only.** *Verified (see `verification-notes.md` §3): WooCommerce 11.1.1 returns `false` from `use_block_editor_for_post_type` for `product` unconditionally (`includes/class-wc-post-types.php:777`) and the `product_block_editor` feature no longer exists, so the classic editor is guaranteed on the installed stack.* The plugin still detects an active block editor defensively (for older WooCommerce) and shows an admin notice explaining the checklist panel is unavailable there; server-side publish enforcement works regardless of editor. Supporting two editor UIs doubles the front-end scope and is not in §3. |
| A8 | §3.1 lists "Sale price validity" but §2.A does not. | Included as a rule, defaulting to severity `warning` and skipped when no sale price is set. It is cheap and is on the §3.1 free list. |
| A9 | §3.6 example implies possible filtering/sorting; §21 Phase 5 says "Filtering/sorting only if simple enough". | **Not implemented.** Sorting/filtering by readiness requires an indexed persisted value, which conflicts with A5/§13.5. Listed in §13. |

### 1.4 Technically risky items identified up front

| Risk | Why | Mitigation in this plan |
|---|---|---|
| R1 | At `wp_insert_post_data` time the product's WooCommerce meta (price, SKU, stock) is **not yet saved** — validating the stored product would use stale data and produce false blocks. | `Product_Context` can be built from the in-flight save request (§5.3, §6.3). |
| R2 | A "live" checklist tempts a duplicate rule implementation in JavaScript, which will drift from PHP. | Rules exist **only in PHP**. The browser sends a draft snapshot; PHP validates. §8. |
| R3 | A naive live checklist fires a request per keystroke. | Debounce + payload signature dedupe + `AbortController`. §8.2. |
| R4 | Products list with 100–200 rows × 11 rules = term/meta/attachment queries per row. | Bulk cache priming on `the_posts` for the list screen. §11.2. |
| R5 | Blocking publish server-side changes `post_status` behind the user's back; without feedback this looks like a bug. | Redirect flag + user transient + `post_updated_messages` override + admin notice. §6.3.4. |
| R6 | Enforcing on every programmatic write can break CSV imports and integrations. | Enforcement scope setting, `wp_doing_cron()`/WP-CLI exclusion, and a documented filter. §6.3.3. |

### 1.5 Assumptions (stated explicitly; verify in §17)

1. The site uses the **classic** WooCommerce product editor (`post.php` / `post-new.php`, `post_type=product`).
2. Only **simple** and **variable** product types are formally supported; other types (grouped, external, and third-party types) are handled gracefully — rules that cannot apply return `skipped` rather than failing.
3. The plugin is distributed as a self-contained zip. **No Composer runtime dependencies**; `vendor/` is dev-only.
4. The target audience is merchants, so the settings screen is plain PHP/HTML (no React) and only the editor panel is a React app.
5. Shop managers (`manage_woocommerce`) are trusted to configure the plugin; only users who can edit a given product may validate it.
6. No custom database tables, no custom post types, no custom taxonomies, one options row.
