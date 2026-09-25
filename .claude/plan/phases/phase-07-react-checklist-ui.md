<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 7 — React checklist UI

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 7` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §4.3 — JavaScript structure → `../sections/04-directory-structure.md`
* §7.1 — editor panel → `../sections/07-admin-ui.md`
* §8 — client ⇄ server data flow → `../sections/08-data-flow.md`
* §10.3 — JS / React → `../sections/10-coding-standards.md`
* §10.4 — CSS → `../sections/10-coding-standards.md`
* §11.5 — request frequency → `../sections/11-performance.md`
* §12.4 — manual rows 7–9, 12, 14, 26, 27 → `../sections/12-testing.md`

---

* **Prerequisites:** Phases 5 and 6.
* **Objective:** the live panel.
* **Tasks:** entry, components, `useValidation`, `api.js`, `snapshot.js`, `field-watcher.js`, `focusField.js`, `editor.scss`; wire `wp_set_script_translations`; Jest tests for `snapshot.js` (signature stability) and `useValidation` (state transitions including abort and error).
* **Files affected:** `assets/js/editor/**`, `assets/scss/editor.scss`, `tests/js/*`.
* **Deliverable:** editing any watched field updates the panel within ~1 s without a page reload.
* **Validation:** manual rows 7–9, 12, 14, 26, 27; the network panel shows **no** request for keystrokes that do not change the snapshot, and never more than one in-flight request.
