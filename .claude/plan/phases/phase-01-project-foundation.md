<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 1 — Project foundation

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 1` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §4 — directory & file structure → `../sections/04-directory-structure.md`
* §6.1 — bootstrap & compatibility → `../sections/06-wp-wc-integration.md`
* §10 — coding standards → `../sections/10-coding-standards.md`
* §17 — verify before coding → `../sections/17-risks-to-verify.md`

---

* **Prerequisites:** none.
* **Objective:** an installable, activatable plugin that boots safely and does nothing else.
* **Tasks:** plugin header (incl. `Requires Plugins: woocommerce`) + constants; `src/Autoloader.php`; `src/Plugin.php` skeleton; `Compat/Requirements.php`; `Compat/Woo_Compat.php`; `init` text-domain load; `uninstall.php`; `composer.json`, `phpcs.xml.dist`, `phpunit.xml.dist`, `package.json`, `webpack.config.js`, `.wp-env.json`, `.distignore`, `LICENSE`, `README.md`, `readme.txt` stub.
* **Files affected:** all of the above.
* **Deliverable:** activates cleanly; deactivating WooCommerce shows the notice and causes no errors.
* **Validation:** `phpcs` clean; activation with `WP_DEBUG=true` produces zero notices; WooCommerce → Status shows no incompatibility warning.
