<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 16. Definition of Done (V1)

V1 is complete when **all** of the following are true. Each is objectively checkable.

**Functionality**
1. All ten V1 capabilities in §1.1 are implemented and demonstrable.
2. All 11 rules in §5.6 exist, with every documented branch reachable.
3. The checklist refreshes automatically while editing, with no mandatory manual action.
4. Every general-plan §22 step (install → configure → open → see issues → fix → see readiness update → be blocked → identify from the list) completes end to end on a clean install.
5. Nothing from §13 has been implemented.

**Security**
6. Every control in §9 is implemented and mapped in `.claude/plan/security-audit.md`.
7. All §12.3 security tests pass.
8. Every §9.8 bypass path is verified closed by an automated test.
9. PHPCS (WordPress ruleset, security sniffs at error severity) reports zero errors and zero warnings.

**Quality**
10. Unit and integration suites pass on current WP/WC **and** at the declared floor (PHP 8.0 / WP 6.5 / WC 9.0, settled 2026-09-24 — §17.24). PHP 8.0 is the Laragon CLI version and WC 9.0 is reachable through a pinned `wp-env` profile, so both halves of this item are now substantiable.
11. The full §12.4 manual matrix passes, recorded in `.claude/plan/test-report.md`.
12. No PHP notices, warnings or deprecations with `WP_DEBUG=true` on any touched screen.
13. No JavaScript console errors or React warnings on the product editor screen.
14. ESLint and Prettier pass.

**Compatibility & performance**
15. Deactivating WooCommerce leaves the site functional with a clear admin notice; the plugin does not boot.
16. HPOS compatibility is declared; the WooCommerce status page shows no incompatibility warning.
17. With the product block editor active (older WooCommerce only — WC 11 force-disables it for products), enforcement still works and the limitation is communicated. Not verifiable on the current stack; test only if a WC version that still ships the feature is targeted.
18. No plugin assets load outside the three screens in §11.1.
19. A 50-product list adds no more than ~5 queries versus the deactivated baseline.

**Release readiness**
20. `npm run build` produces `build/` from a clean checkout, and the plugin runs from the zip alone.
21. All user-facing strings are translatable with translator comments on every placeholder string; `product-publish-guard.pot` is current.
22. `readme.txt` and `README.md` are complete, including the developer hook reference and the documented enforcement limitations (the Layer C window; the block editor).
23. `uninstall.php` removes `sit_wcpg_settings` and all `sit_wcpg_*` transients, leaving no orphan data, roles or tables.
24. The packaged zip installs on a clean site and passes the Phase 12 smoke test.
