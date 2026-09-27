<!-- Part of the V1 coding plan. Index and binding core: ../coding-plan.md. Section numbers (§) are global and stable. -->

## 7. Admin UI specification

### 7.1 Product editor panel (`side` context, above the fold)

```text
┌─ Product Readiness ─────────────────┐
│  ⚠  Not ready to publish            │   ← summary pill: Ready / Not ready / Checking
│  6 of 9 checks passed · 1 issue     │
├─────────────────────────────────────┤
│  CONTENT                            │   ← group header (uppercase, muted)
│  ✓  Product title                   │
│  ✓  Description                     │
│  !  Short description               │
│     42 characters; 50 recommended.  │   ← message shown only for warning/fail rows
│  MEDIA                              │
│  ✗  Featured image                  │
│     This product has no featured    │
│     image.  [ Set featured image ]  │   ← fix action (focuses the DOM node)
│  …                                  │
├─────────────────────────────────────┤
│  Checked just now       [ Re-check ]│
└─────────────────────────────────────┘
```

* **States:** `pass` ✓ green, `warning` ! amber, `fail` ✗ red, `skipped` – muted (rendered last, collapsed under "Not applicable (n)").
* **Messages** appear only on non-pass rows — a wall of green explanations is noise.
* **Fix action** appears only when the rule declares `fix.selector`; clicking scrolls to and focuses the element and opens the containing WooCommerce product-data panel when `fix.panel` is set. It never navigates away (unsaved changes).
* **Interaction:** no accordion, no tabs, no modal. A read-only list plus fix links.
* **Refresh:** automatic (§8.2). The `Re-check` button is a secondary fallback for fields the watcher cannot observe and for retrying after a network error; it is never required in the normal flow.
* **Loading:** the previous result stays visible, dimmed, with a small spinner in the header (`stale` flag). Never blank the panel — flicker on every keystroke is worse than a briefly stale list.
* **Error:** an inline `Notice` ("Could not refresh the checklist.") plus retry; the last good result remains visible.
* **No-JS fallback:** the meta box server-renders the current result as a plain escaped list, which React replaces on mount.
* **Accessibility:** status is conveyed by icon **and** text, never colour alone; the summary region is `aria-live="polite"`; icons are `aria-hidden` with visually-hidden text labels.

### 7.2 Products list column

* Column title **Readiness**, inserted before **Date**.
* Cell values: `✓ Ready` / `! 2 warnings` / `✗ 3 errors` (plural-aware via `_n()`), linking to the product's edit screen with `#sit_wcpg_product_checklist`.
* `auto-draft` and trashed products render `—`.
* Drafts and published products are treated identically — the indicator describes **content completeness**, not publication state. A published product with errors is precisely the case a merchant needs to see.
* A product with no evaluated checks (every rule disabled or skipped) also renders `—` — there is no state to report. *(Amended in Phase 9.)*
* Hidden entirely when `product_list.show_column` is off, the checklist is disabled (`enabled` off, matching the meta box), or the user lacks `edit_products`. *(Checklist-disabled case amended in Phase 9.)*
* No sorting, no filtering (§13).

### 7.2.1 Block-editor notice (A7) — *added 2026-09-27, SIT-WCPG-TEST-8*

* When `Requirements::is_product_block_editor_active()` is true (older WooCommerce with the product block editor turned on; never on WC 11), `Admin/Block_Editor_Notice.php` prints one `notice-warning` on `admin_notices`.
* Shown only on the products list and the plugin settings screen: those are the classic screens a merchant still reaches, and the block editor itself is a `wc-admin` React page.
* Shown only when the checklist is enabled and the user has `edit_products`. Not dismissible (no persisted state, §13.15); it disappears when the editor is switched back.
* Text: the checklist panel is available only in the classic product editor; the new product editor is on, so the panel will not appear there; publishing rules are still enforced when products are saved; switch back under WooCommerce → Settings → Advanced → Features. Static, translated, escaped; no product data.

### 7.3 Settings page — `WooCommerce → Product Checklist`

```text
Product Checklist

[x] Enable the product readiness checklist

── Rules ──────────────────────────────────────────────
 Rule                Enabled   Severity
 Product title        [x]      (•) Required  ( ) Warning
 Description          [x]      (•) Required  ( ) Warning
 Short description    [x]      ( ) Required  (•) Warning
 Featured image       [x]      (•) Required  ( ) Warning
 Minimum images       [x]      ( ) Required  (•) Warning
 Regular price        [x]      (•) Required  ( ) Warning
 Sale price validity  [x]      ( ) Required  (•) Warning
 Product category     [x]      (•) Required  ( ) Warning
 Product tags         [x]      ( ) Required  (•) Warning
 SKU                  [x]      ( ) Required  (•) Warning
 Stock status         [x]      ( ) Required  (•) Warning

── Content thresholds ─────────────────────────────────
 Minimum description length        [ 150 ] characters  (0 = off)
 Minimum short description length  [  50 ] characters  (0 = off)
 Minimum number of images          [   2 ] images      (0 or 1 = off)

── Publishing ─────────────────────────────────────────
 [x] Prevent publishing when required checks fail
 [ ] Allow users who can manage WooCommerce to publish anyway
 Enforcement applies to:  (•) All signed-in requests  ( ) Admin editor screens only
 [x] Show the Readiness column in the products list

[ Save changes ]
```

**Storage:** the single option `sit_wcpg_settings`.

```php
[
  'version'      => 1,
  'enabled'      => true,
  'rules'        => [ '<rule_id>' => [ 'enabled' => bool, 'severity' => 'required'|'warning' ], … ],
  'thresholds'   => [ 'min_description_chars' => int, 'min_short_description_chars' => int, 'min_images' => int ],
  'publishing'   => [ 'block_on_required_failure' => bool, 'allow_admin_override' => bool, 'enforce_scope' => 'authenticated'|'editor' ],
  'product_list' => [ 'show_column' => bool ],
]
```

**Validation/sanitization (`Settings_Sanitizer`, §9.3):** rule ids are whitelisted against the registry (unknown keys dropped, not stored); `enabled` → `(bool) isset()`; `severity` → whitelisted against `Severity::all()` with the rule's default as fallback; thresholds → `absint()` then clamped (`min_description_chars` 0–10000, `min_short_description_chars` 0–5000, `min_images` 0–20) with a `settings_errors()` notice when clamping occurs; `enforce_scope` → enum whitelist; `version` forced to the code constant, never taken from input. Unknown top-level keys are discarded so the option can never accumulate arbitrary data.
