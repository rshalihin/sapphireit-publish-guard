<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Moved verbatim from §15 Implementation order. -->

# Phase 10 — Security hardening pass

**Read, in this order, and nothing else from the plan unless a listed section points you there:**

1. `../coding-plan.md` — binding core (§0 How to use, §2 decisions, §13 Do Not Implement).
2. This file.
3. `../implementation-checklist.md` — only the `## Phase 10` block (and its "Deviations" sub-block).
4. Sections this phase depends on (read only the named subsection where one is given):

* §9 — security plan → `../sections/09-security.md`
* §10 — coding standards → `../sections/10-coding-standards.md`
* §12.3 — security tests → `../sections/12-testing.md`

---

* **Prerequisites:** Phases 1–9.
* **Objective:** verify, do not assume.
* **Tasks:** audit every `echo`/`printf` for escaping; every superglobal read for `wp_unslash` + sanitization; every route and handler for `permission_callback` / capability; confirm there is no `$wpdb`, no `admin-ajax`, no `dangerouslySetInnerHTML`, no outbound HTTP; run the §12.3 suite; run PHPCS with security sniffs at error severity.
* **Files affected:** all.
* **Deliverable:** `.claude/plan/security-audit.md` mapping each §9 control to the file and line implementing it.
* **Validation:** §12.3 fully green; PHPCS zero errors and zero warnings.
