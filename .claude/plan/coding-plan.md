# Product Publish Guard — V1 Coding Plan

**Source of truth:** `.claude/general idea/general-plan.md` (product-level spec).
**This document:** implementation-level plan. It is binding for the coding agent.
**Status:** planning only. **No production code is to be written from this document's authoring task.**

---

## Plan layout (split on 2026-09-25)

This file is the **binding core**: every phase reads it. The rest of the plan lives in one file per
section and one file per phase. Section numbers (§) are global and unchanged, so a reference such as
`coding-plan.md §6.3` resolves through the index below.

### Section index

| § | Section | File |
|---|---|---|
| §0 | How to use this document | this file |
| §1 | Scope analysis of `general-plan.md` | `sections/01-scope.md` |
| §2 | Binding technical decisions | this file |
| §3 | Architecture | `sections/03-architecture.md` |
| §4 | Directory and file structure | `sections/04-directory-structure.md` |
| §5 | Rule engine design | `sections/05-rule-engine.md` |
| §6 | WordPress / WooCommerce integration points | `sections/06-wp-wc-integration.md` |
| §7 | Admin UI specification | `sections/07-admin-ui.md` |
| §8 | Client ⇄ server data flow | `sections/08-data-flow.md` |
| §9 | Security plan | `sections/09-security.md` |
| §10 | Coding standards | `sections/10-coding-standards.md` |
| §11 | Performance | `sections/11-performance.md` |
| §12 | Testing plan | `sections/12-testing.md` |
| §13 | V1 — Do Not Implement | this file |
| §14 | Future extension points (designed now, empty in V1) | `sections/14-extension-points.md` |
| §15 | Implementation order | `phases/` (below) |
| §16 | Definition of Done (V1) | `sections/16-definition-of-done.md` |
| §17 | Implementation Risks / Things to Verify Before Coding | `sections/17-risks-to-verify.md` |

### §15 Implementation order — phase files

Each phase must be green before the next begins. "Green" = its validation criteria pass.

| Phase | File |
|---|---|
| 1 — Project foundation | `phases/phase-01-project-foundation.md` |
| 2 — Engine primitives | `phases/phase-02-engine-primitives.md` |
| 3 — Settings | `phases/phase-03-settings.md` |
| 4 — The 11 rules | `phases/phase-04-the-11-rules.md` |
| 5 — Checklist service + editor panel (server side) | `phases/phase-05-checklist-service-editor-panel-server-side.md` |
| 6 — REST endpoint | `phases/phase-06-rest-endpoint.md` |
| 7 — React checklist UI | `phases/phase-07-react-checklist-ui.md` |
| 8 — Publishing enforcement | `phases/phase-08-publishing-enforcement.md` |
| 9 — Products list integration | `phases/phase-09-products-list-integration.md` |
| 10 — Security hardening pass | `phases/phase-10-security-hardening-pass.md` |
| 11 — Test completion & performance verification | `phases/phase-11-test-completion-performance-verification.md` |
| 12 — Documentation, i18n and packaging | `phases/phase-12-documentation-i18n-and-packaging.md` |

---

## 0. How to use this document

1. Read this file (§2 decisions, §13 Do Not Implement), then **only** the phase file you are implementing and the sections it lists. Read §1 (scope) only when a scope question arises.
2. Implement strictly in the order defined in §15. Do not start a phase before its prerequisites are green.
3. Anything listed in §13 (`V1 — Do Not Implement`) is forbidden, even if it looks easy.
4. Before writing the first line of code, work through §17 (`Implementation Risks / Things to Verify Before Coding`) and record the answers in `.claude/plan/verification-notes.md`. If a verified fact contradicts this plan, update this plan first, then code.
5. Companion file: `.claude/plan/implementation-checklist.md` — a flat, ordered, tickable task list derived from §15.

---

## 2. Binding technical decisions

These are settled. Do not re-litigate them during implementation.

| Key | Decision | Rationale |
|---|---|---|
| Plugin slug / dir | `product-publish-guard` | Matches the existing directory. |
| Main file | `product-publish-guard.php` | WP.org convention: main file matches slug. |
| Text domain | `product-publish-guard` | Must equal the slug for WP.org language packs. |
| Global prefix | `sit_wcpg_` (functions, hooks, options, transients, error codes, meta box id; cache group `sit_wcpg`), `SIT_WCPG_` (constants), `sit-wcpg-` (script/style handles, admin slugs, HTML ids, CSS classes; REST namespace `sit-wcpg/v1`), `sitWcpg…` (JS globals). Exact form per context: §10.6. | Vendor-prefixed, distinctive, collision-safe; each global string is defined once as a class constant. Enforced by PHPCS `PrefixAllGlobals` and `composer lint:prefix`. Changed from `wcpg` to `sit_wcpg` by user decision on 2026-09-25 to add a vendor prefix (`prefix-migration-plan.md`). <!-- prefix-guard: ignore --> |
| PHP namespace root | `ProductPublishGuard\` | One root namespace, sub-namespaces per concern. |
| Min PHP | **8.0** | Raised from 7.4 by user decision on 2026-09-24 (§17.24): PHP 7.4 is not installed in this environment, so a 7.4 claim could not be substantiated. Typed properties, arrow functions, constructor promotion, union types, `match` and named arguments are all available. **Do not use** enums, `readonly` properties, `never` return types or first-class callable syntax (all 8.1+). |
| Min WordPress | **6.5** | First version supporting the `Requires Plugins:` header, which gives us dependency handling for free. |
| Min WooCommerce | **9.0** | Raised from 8.2 by user decision on 2026-09-24 (§17.24). Still HPOS-default era; `wc_get_product` / CRUD APIs used here are long-stable, and 9.x is testable via `wp-env`. |
| Autoloading | Hand-written PSR-4 autoloader in `src/Autoloader.php` | Zero runtime dependencies, no `vendor/` in the zip, no autoloader conflicts with other plugins. Composer is dev-only (PHPCS/PHPUnit). |
| File naming | PSR-4 (`src/Engine/Rule_Registry.php` → `ProductPublishGuard\Engine\Rule_Registry`) with WPCS class names (`Snake_Case`) | Keeps WPCS naming while allowing a trivial autoloader. `WordPress.Files.FileName` is excluded in `phpcs.xml.dist`. |
| Settings storage | **One** autoloaded option, `sit_wcpg_settings` (nested array) | One row, atomic sanitization, trivial version/migration, and a cheap settings hash for cache keys. |
| Settings UI | WP Settings API + `options.php`, with `option_page_capability_sit_wcpg_settings` filtered to `manage_woocommerce` | Free nonce/CSRF handling and a core-verified save path, while still letting shop managers (not just admins) configure it. |
| Editor UI | React app mounted in a classic `add_meta_box` panel (`side`, `high`) | §13.1 forbids replacing the editor; a meta box is the supported, lightweight integration point. |
| Build | `@wordpress/scripts` (wp-scripts), output to `build/` | Standard WP tooling, generates `*.asset.php` dependency/version manifests. |
| React provenance | Core-provided script handles (`wp-element`, `wp-components`, `wp-api-fetch`, `wp-i18n`) | React is **not** bundled; keeps the editor bundle small (§16). JSX is compiled with the **classic** runtime to `createElement` from `wp-element` (`babel.config.js`, Phase 7): the preset's automatic runtime depends on the `react-jsx-runtime` handle, which WP 6.5 (the floor) does not register, and bundling a runtime from `node_modules` (React 19) would emit elements core's React 18 cannot render. |
| Transport | REST (`sit-wcpg/v1`), `POST .../validate` | Carries an unsaved draft snapshot in the body; authenticated admin-only; `@wordpress/api-fetch` handles the `X-WP-Nonce` automatically. |
| Enforcement | Server-side, three layers (`wp_insert_post_data`, `woocommerce_before_product_object_save`, `future_to_publish` backstop) | general-plan §15.11: UI-only blocking is not acceptable. |
| Persistence | None beyond `sit_wcpg_settings` + short-lived transients for admin notices | §13.5 of general-plan. |

---

## 13. V1 — Do Not Implement

The following appear in `general-plan.md` as future work. **They are out of scope for V1 and must not be implemented, not even "simply".**

1. Variation-level checks of any kind (per-variation price / SKU / stock / image). `price` skips variable products; that is the whole V1 story.
2. Attribute, brand/manufacturer, weight, dimensions, shipping-class or tax-status rules.
3. Image dimension, image quality, file-size or alt-text rules.
4. Any quality **score**, percentage or grade. Counts and states only (general-plan §13.6).
5. Duplicate title or duplicate SKU detection.
6. Bulk audits, "scan all products", store-wide reports, dashboards or widgets.
7. Scheduled or cron audits of any kind. The only cron interaction is the Layer C backstop, which is a guard, not an audit.
8. Email notifications, CSV export, webhooks, or any external HTTP request. **V1 makes no outbound network calls at all.**
9. Approval workflow, custom statuses (`needs-review` / `approved`), reviewer notes, activity or audit history.
10. Custom roles, capability editing, or role-based publishing rules.
11. A custom-rule builder UI, or per-product rule overrides.
12. AI features of any kind.
13. SEO-specific rules or integrations with SEO plugins.
14. Sorting or filtering the products list by readiness (requires persisted, indexed state — see A5 and §14.4).
15. Persisting readiness results in post meta, a custom table, or a per-product transient.
16. A custom product editor, custom product data panels, or new tabs in the WooCommerce product data box.
17. Support for the WooCommerce product **block** editor UI.
18. Compatibility shims for subscriptions, bookings, memberships, composite or bundled products, or any third-party product type.
19. Admin AJAX endpoints (`admin-ajax.php`) — REST only.
20. Onboarding wizards, upsell or Pro notices, telemetry, or review prompts.
