<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 3 — Settings

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 3` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §5.2 — enable/disable, priority → `../sections/05-rule-engine.md`
* §7.3 — settings page → `../sections/07-admin-ui.md`
* §9.3 — sanitization → `../sections/09-security.md`
* §9.7 — settings privilege → `../sections/09-security.md`
* §12.3 — settings security tests → `../sections/12-testing.md`

---

* **Prerequisites:** Phase 2 (defaults derive from the registry).
* **Objective:** settings readable, writable and safely sanitized.
* **Tasks:** `Settings`, `Settings_Sanitizer`, `Settings_Page` (menu, `register_setting`, capability filter, form rendering), tests.
* **Files affected:** `src/Settings/*`, `tests/Unit/Settings_Sanitizer_Test.php`, `tests/Integration/Settings_Test.php`.
* **Deliverable:** `WooCommerce → Product Checklist` saves and reloads correctly.
* **Validation:** the §12.3 settings security tests pass; unknown keys and an injected `version` are dropped; thresholds clamp.
