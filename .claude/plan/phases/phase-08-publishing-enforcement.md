<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 8 — Publishing enforcement

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 8` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §5.3 — Product_Context::from_save_request → `../sections/05-rule-engine.md`
* §6.3 — publishing enforcement → `../sections/06-wp-wc-integration.md`
* §6.4 — already-published products → `../sections/06-wp-wc-integration.md`
* §9.8 — bypass resistance → `../sections/09-security.md`
* §12.2 — integration tests → `../sections/12-testing.md`
* §12.4 — manual rows 2, 17–21 → `../sections/12-testing.md`

---

* **Prerequisites:** Phases 3 and 4. May run in parallel with Phase 7.
* **Objective:** publishing is impossible while required checks fail.
* **Tasks:** `Engine/Save_Request_Reader`; `Publishing/Publish_Guard` (Layers A/B/C, skip matrix, scope, override); `Admin/Notices` (transient queue, `admin_notices`, `post_updated_messages`, `redirect_post_location`); the client-side advisory notice in the panel.
* **Files affected:** `src/Engine/Save_Request_Reader.php`, `src/Publishing/Publish_Guard.php`, `src/Admin/Notices.php`, `tests/Integration/Publish_Guard_*`.
* **Deliverable:** every bypass path in §9.8 is closed.
* **Validation:** manual rows 2, 17, 18, 19, 20, 21; all `Publish_Guard_*` integration tests pass, including fail-open on an internal exception.
