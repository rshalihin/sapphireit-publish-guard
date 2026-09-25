<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 4 — The 11 rules

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 4` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §5 — rule engine, esp. §5.3 and §5.6 → `../sections/05-rule-engine.md`
* §9.5 — stored XSS / rule messages → `../sections/09-security.md`
* §12.1 — unit test table → `../sections/12-testing.md`

---

* **Prerequisites:** Phases 2 and 3.
* **Objective:** the complete V1 rule catalog.
* **Tasks:** `Rules_Provider` plus the 11 rule classes, plus one unit test class per rule covering every branch in §5.6.
* **Files affected:** `src/Rules/*`, `tests/Unit/Rules/*`.
* **Deliverable:** `Validator` produces a correct result for a realistic product context.
* **Validation:** every branch in the §12.1 table has a passing assertion; a test asserts that **no rule message contains interpolated non-integer values** (§9.5).
