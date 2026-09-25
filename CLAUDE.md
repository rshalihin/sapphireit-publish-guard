# Product Publish Guard — working rules

The plan documents in `.claude/plan/` are the authority. When a decision changes, update the
plan documents **before** the code.

The coding plan is split. To implement Phase N, read only:
`coding-plan.md` (binding core + § index) → `phases/phase-NN-*.md` → the sections that phase file
lists (`sections/NN-*.md`) → the `## Phase N` block of `implementation-checklist.md`. Do not read
the whole plan. A `§N.M` reference resolves through the index in `coding-plan.md`.

## Naming contract (coding-plan.md §10.6 → sections/10-coding-standards.md)

One prefix, two spellings. Use exactly the form for the context:

| Context | Form | Example |
|---|---|---|
| PHP constants | `SIT_WCPG_` | `SIT_WCPG_VERSION` |
| Global functions, global variables | `sit_wcpg_` | `sit_wcpg_bootstrap()`, `$sit_wcpg_user_ids` |
| Hooks (actions / filters) | `sit_wcpg_` | `sit_wcpg_register_rules` |
| Options, transients, post meta | `sit_wcpg_` (hidden meta: `_sit_wcpg_`) | `sit_wcpg_settings` |
| Object-cache group | `sit_wcpg` | `'sit_wcpg'` |
| REST namespace | `sit-wcpg/v1` | `/wp-json/sit-wcpg/v1/products/12/validate` |
| REST / `WP_Error` / settings-error codes | `sit_wcpg_` | `sit_wcpg_forbidden` |
| Meta box id, list-column key | `sit_wcpg_` | `sit_wcpg_product_checklist` |
| Script & style handles, admin page slugs, HTML ids | `sit-wcpg-` | `sit-wcpg-editor`, `sit-wcpg-settings` |
| CSS classes (BEM) | `sit-wcpg-block__element--modifier` | `.sit-wcpg-checklist__item--fail` |
| JS globals | `sitWcpg` + PascalCase | `window.sitWcpgEditorData` |
| Test / audit IDs in docs | `SIT-WCPG-` | `SIT-WCPG-REST-1` |
| PHP namespace | `ProductPublishGuard\<Concern>` | `ProductPublishGuard\Engine\Validator` |
| Text domain, slug, main file | `product-publish-guard` | `__( '…', 'product-publish-guard' )` |

* Never add an unprefixed global, and never use the retired bare prefix (the part after
  `sit_`) on its own.
* Define each global identifier **once**, as a class constant, and reference it from
  there. Build derived names from the constant (`'woocommerce_page_' . Settings_Page::MENU_SLUG`,
  `'/' . Validate_Controller::REST_NAMESPACE . '/…'`), never retype them.
* JavaScript takes the REST path from the payload's `restPath`; it never hardcodes it.
* A new global constant belongs in `tests/Unit/Naming_Contract_Test.php`.

## Before finishing any task

```bash
composer lint        # PHPCS + prefix guard (bin/check-prefix.php); must be zero errors, zero warnings
composer test:unit   # composer test when the WordPress test suite is available (wp-env)
```
