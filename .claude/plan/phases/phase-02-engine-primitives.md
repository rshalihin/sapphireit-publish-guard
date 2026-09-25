<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 2 — Engine primitives

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 2` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §3 — architecture → `../sections/03-architecture.md`
* §4.1 — PHP file responsibilities → `../sections/04-directory-structure.md`
* §5 — rule engine (all but §5.6) → `../sections/05-rule-engine.md`
* §12.1 — unit tests → `../sections/12-testing.md`

---

* **Prerequisites:** Phase 1.
* **Objective:** value objects and contracts, fully unit-tested, with no rules yet.
* **Tasks:** `Status`, `Severity`, `Rule_Interface`, `Abstract_Rule`, `Rule_Result`, `Validation_Result`, `Rule_Registry`, `Validator` (including the §5.5 matrix and per-rule try/catch), `Product_Context` (all factories except `from_save_request`), plus unit tests.
* **Files affected:** `src/Engine/*`, `tests/Unit/Engine/*`.
* **Deliverable:** `Validator` runs a fake rule against a `from_array()` context and produces a correct `Validation_Result`.
* **Validation:** all §12.1 engine tests pass; `Validation_Result::to_array()` matches §5.4 exactly in shape.
