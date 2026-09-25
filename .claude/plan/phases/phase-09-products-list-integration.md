<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 9 — Products list integration

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 9` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §7.2 — products list column → `../sections/07-admin-ui.md`
* §11.2 — list priming → `../sections/11-performance.md`
* §12.4 — manual row 22 → `../sections/12-testing.md`

---

* **Prerequisites:** Phase 5.
* **Objective:** at-a-glance readiness without opening products.
* **Tasks:** `Admin/Product_List_Column` (column, `the_posts` priming, cell render); `assets/scss/admin.scss`.
* **Files affected:** `src/Admin/Product_List_Column.php`, `assets/scss/admin.scss`.
* **Deliverable:** the Readiness column on `edit.php?post_type=product`.
* **Validation:** manual row 22 — query count for a 50-product page within ~5 of the deactivated baseline, asserted in `Product_List_Column_Test`.
