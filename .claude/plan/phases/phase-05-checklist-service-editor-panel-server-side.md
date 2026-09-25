<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 5 — Checklist service + editor panel (server side)

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 5` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §3.2 — request paths → `../sections/03-architecture.md`
* §4.1 — PHP file responsibilities → `../sections/04-directory-structure.md`
* §6.2 — admin surfaces → `../sections/06-wp-wc-integration.md`
* §7.1 — editor panel → `../sections/07-admin-ui.md`
* §8.1 — first paint → `../sections/08-data-flow.md`
* §9.4 — output escaping → `../sections/09-security.md`
* §11.1 — asset loading matrix → `../sections/11-performance.md`
* §11.3 — memoization → `../sections/11-performance.md`
* §11.4 — duplicate reads → `../sections/11-performance.md`
* §12.4 — manual rows 1–3, 23 → `../sections/12-testing.md`

---

* **Prerequisites:** Phase 4.
* **Objective:** a working, server-rendered checklist in the product editor.
* **Tasks:** `Checklist_Service` (memoization layers 1–2); `Admin/Screen`; `Admin/Editor_Meta_Box` with the escaped no-JS fallback; `Admin/Assets` with the §11.1 matrix and the inline payload.
* **Files affected:** `src/Support/Checklist_Service.php`, `src/Admin/{Screen,Editor_Meta_Box,Assets}.php`.
* **Deliverable:** opening a product shows a correct, static checklist **with JavaScript disabled**.
* **Validation:** correct results for manual rows 1–3; zero assets on unrelated screens (row 23).
