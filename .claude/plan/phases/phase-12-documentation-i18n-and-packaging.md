<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 12 — Documentation, i18n and packaging

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 12` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §4 — .distignore / file list → `../sections/04-directory-structure.md`
* §6.3 — enforcement model for README → `../sections/06-wp-wc-integration.md`
* §10.2 — i18n → `../sections/10-coding-standards.md`
* §14 — hook reference → `../sections/14-extension-points.md`
* §16 — definition of done → `../sections/16-definition-of-done.md`

---

* **Prerequisites:** Phase 11.
* **Objective:** releasable.
* **Tasks:** finalize `readme.txt` (description, installation, FAQ, changelog, `Requires at least` / `Tested up to` / `Requires PHP` / `WC requires at least` / `WC tested up to`); `README.md` with the developer hook reference (§14) and the enforcement model (§6.3) including documented limitations; generate `languages/product-publish-guard.pot`; verify every string is translatable; `npm run build`; verify `.distignore` excludes `assets/`, `tests/`, `node_modules/`, `vendor/`, dotfiles and `.claude/`; build and install the zip on a clean site.
* **Deliverable:** `product-publish-guard.zip`.
* **Validation:** the zip installs and passes manual rows 1, 2, 22 and 24 on a clean site; it contains no `node_modules`, `vendor`, `tests` or source `assets/`; `build/editor.js` is under 40 KB minified (React is external).
