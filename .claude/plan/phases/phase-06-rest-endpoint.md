<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 6 — REST endpoint

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 6` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §5.3 — Product_Context → `../sections/05-rule-engine.md`
* §8.2 — live revalidation → `../sections/08-data-flow.md`
* §9.1 — capabilities → `../sections/09-security.md`
* §9.2 — nonces → `../sections/09-security.md`
* §9.3 — sanitization → `../sections/09-security.md`
* §9.10 — REST hardening → `../sections/09-security.md`
* §12.3 — REST security tests → `../sections/12-testing.md`

---

* **Prerequisites:** Phase 5.
* **Objective:** authenticated live validation against unsaved data.
* **Tasks:** `Rest/Validate_Controller` — route, complete `args` schema, `permission_callback`, override plumbing into `Product_Context::from_product_with_overrides()`.
* **Files affected:** `src/Rest/Validate_Controller.php`, `tests/Integration/Rest_Validate_Test.php`, `tests/Security/Rest_*`.
* **Deliverable:** a `POST` with a draft payload returns a result reflecting the unsaved data.
* **Validation:** all §12.3 REST security tests pass; array caps enforced; the product is provably unchanged after the call.
