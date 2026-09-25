<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 14. Future extension points (designed now, empty in V1)

Only these five seams exist. Each costs ~0 lines in V1 and removes a rewrite later.

| # | Seam | Enables | V1 cost |
|---|---|---|---|
| 14.1 | `do_action( 'sit_wcpg_register_rules', Rule_Registry $registry )` | Custom rules, V2 rule packs (variations, images, alt text, attributes), Pro add-ons — without touching core files. | 1 line |
| 14.2 | `Rule_Interface::supports( Product_Context )` + `Status::SKIPPED` | Product-type-specific rule sets (variation rules that apply only to variable products) with correct counts from day one. | already required by §5.6 |
| 14.3 | `apply_filters( 'sit_wcpg_validation_result', Validation_Result $r, Product_Context $c )` | Result post-processing: suppressing rules per product, synthetic results, Pro reporting hooks. | 1 line in `Validator` |
| 14.4 | `Checklist_Service::get_summary_for_post_id()` as the **only** read path for non-editor consumers | A persisted readiness store (post meta + settings-hash stamp) can be introduced behind this one method to enable list sorting/filtering, bulk audits and reports without changing a single caller. | already required by §11.3 |
| 14.5 | `apply_filters( 'sit_wcpg_should_enforce', bool, Product_Context, string $source )` and `sit_wcpg_can_override_publish_guard` | Role-based publishing rules, approval workflows, per-integration exemptions. | 2 lines in `Publish_Guard` |

Also extensible without extra work because they are data-driven: the settings page (rows built from the registry), the React panel (renders whatever results the payload contains), and `Settings::get_defaults()` (derived from rule defaults).

**Not built as extension points** (speculative): a rule-result storage interface, a notification abstraction, a reporting interface, a job/queue abstraction, a template override system, a JS filter/slot-fill registry.
