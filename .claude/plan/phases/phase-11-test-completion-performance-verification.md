<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 11 — Test completion & performance verification

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 11` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §11 — performance → `../sections/11-performance.md`
* §12 — testing plan → `../sections/12-testing.md`
* §16 — definition of done → `../sections/16-definition-of-done.md`

---

* **Prerequisites:** Phases 1–10.
* **Objective:** the suite is a safety net, not a formality.
* **Tasks:** fill gaps in §12.1 and §12.2; run the full §12.4 matrix on minimum and current WP/WC; profile the editor screen and the 50-product list.
* **Deliverable:** `.claude/plan/test-report.md`.
* **Validation:** 100 % of the §12.4 matrix passes; unit and integration suites green on both version targets.
