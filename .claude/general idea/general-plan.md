Below is a product-level plan designed specifically so your coding agent can later turn it into a **detailed implementation plan and then code**. I am keeping this at the architecture/product-spec level rather than prescribing every function and file.

# WooCommerce Publishing / Product Checklist Plugin — General Plan

## 1. Product Goal

Build a lightweight WooCommerce plugin that helps store owners **verify a product before publishing or updating it**.

The plugin should answer:

> “Is this product ready to publish?”

Instead of replacing WooCommerce's editor, it adds a **checklist/validation layer** around the existing product workflow.

### Main goal

A merchant opens a WooCommerce product and sees:

```text
Product Readiness
────────────────────────────

✓ Product title
✓ Featured image
✓ Price
✗ Short description
✓ Product category
✗ SKU
✓ Stock status
! Product description too short

Readiness: 6 / 8

[ View Issues ]
```

The plugin should make it immediately obvious:

* what is missing
* what needs attention
* what is optional
* whether the product meets the store's publishing rules

### Primary target audience

Small and medium WooCommerce stores that have:

* multiple products
* multiple staff members
* inconsistent product data
* product-entry mistakes
* internal publishing standards

The plugin is **not** primarily aimed at developers.

---

# 2. Core Product Concept

The plugin consists of three main concepts.

### A. Checklist Rules

Rules such as:

```text
Title required
Featured image required
Regular price required
Short description required
Category required
SKU required
Minimum description length
Minimum image count
Stock status required
```

### B. Product Readiness

Each product receives a checklist result:

```text
PASS
WARNING
FAIL
```

Example:

```text
Product: Running Shoes

✓ Title
✓ Featured Image
✓ Price
✓ Category
✗ SKU
! Description length

Readiness: 80%
```

### C. Merchant Configuration

Store owners can decide which checks matter.

Example:

```text
Required
[x] Product title
[x] Featured image
[x] Price
[x] Category

Optional
[ ] SKU
[x] Short description

Content rules
[x] Description minimum 100 words
[ ] Description minimum 300 words
[x] Minimum 2 images
```

---

# 3. Version 1 — Free MVP

Keep the first release deliberately small.

## Free Feature Set

### 3.1 Product checklist inside WooCommerce product editor

Display a checklist in the product editing screen.

Support basic checks:

* Product title
* Description exists
* Short description exists
* Featured image exists
* Regular price exists
* Sale price validity
* Product category selected
* Product tags selected
* SKU exists
* Stock status selected

Do not attempt to validate every WooCommerce setting in V1.

---

### 3.2 Pass / Warning / Fail states

Example:

```text
✓ Passed
! Warning
✗ Failed
```

Each rule should have a clear message.

Example:

```text
✗ Product does not have a featured image.

[Add Image]
```

Avoid vague messages such as:

```text
Invalid product.
```

---

### 3.3 Configurable required fields

Admin can enable or disable rules.

Example:

```text
Checklist Settings

[x] Require title
[x] Require featured image
[x] Require price
[x] Require category
[x] Require short description
[ ] Require SKU
```

---

### 3.4 Content length rules

Allow basic thresholds.

Example:

```text
Minimum description length: 150 characters
Minimum short description: 50 characters
Minimum images: 2
```

Keep this basic.

Do not build a full content-quality analyzer yet.

---

### 3.5 Publishing validation

Provide an option:

```text
Prevent publishing when required checks fail
```

Example:

```text
Publishing blocked

3 required checklist items are incomplete:

✗ Featured image
✗ Price
✗ Category
```

Important: the plugin should distinguish between:

```text
Required failure
```

and

```text
Warning
```

Warnings should not block publishing.

---

### 3.6 Product list indicator

Add a lightweight indicator in the WooCommerce products list.

Example:

| Product       | Readiness    |
| ------------- | ------------ |
| Running Shoes | ✓ Ready      |
| Blue Shirt    | ! 2 Warnings |
| Backpack      | ✗ 3 Errors   |

This helps administrators identify problematic products without opening each product.

---

### 3.7 Admin settings

Provide a simple settings page:

```text
Checklist
Publishing Rules
Appearance
Advanced
```

For V1, however, keep the actual settings minimal.

---

# 4. Recommended V1 User Flow

### Product creation

```text
Create Product
      ↓
Enter product information
      ↓
Checklist updates automatically
      ↓
Merchant fixes missing fields
      ↓
All required checks pass
      ↓
Publish
```

### Existing product

```text
Open Product
     ↓
Checklist analyzes product
     ↓
Issues displayed
     ↓
Merchant fixes issues
     ↓
Product becomes Ready
```

The checklist should update without requiring a manual “Run Check” button for normal usage.

---

# 5. Architecture Direction

Use WordPress/WooCommerce APIs rather than manipulating database tables directly whenever possible.

Conceptually:

```text
WooCommerce Product Editor
          │
          ▼
    Checklist UI
          │
          ▼
    Rule Engine
          │
     ┌────┼────┐
     ▼    ▼    ▼
   Title Price Media
     │    │    │
     └────┼────┘
          ▼
     Validation Result
          │
          ▼
   Ready / Warning / Fail
```

### Suggested internal separation

Your coding agent should design the plugin around separate components such as:

```text
Plugin Bootstrap
    ↓
Admin/UI
    ↓
Checklist Engine
    ↓
Rules
    ↓
Validation Result
    ↓
Settings
```

Each rule should be independently testable.

For example:

```text
TitleRule
PriceRule
FeaturedImageRule
CategoryRule
SkuRule
DescriptionLengthRule
```

Do not put all validation logic into one large class.

---

# 6. Rule Engine Design

This is one of the most important architectural decisions.

A rule should conceptually return something like:

```js
{
    id: "featured_image",
    status: "fail",
    severity: "required",
    message: "Featured image is required."
}
```

Or:

```js
{
    id: "description_length",
    status: "warning",
    severity: "warning",
    message: "Description is shorter than 150 characters."
}
```

This makes future rules easier to add.

### Desired principle

```text
Product
   ↓
Run all enabled rules
   ↓
Collect results
   ↓
Calculate readiness
   ↓
Render results
```

---

# 7. Free vs Future Versions

Keep the boundary very clear.

## Free Version

The free version should focus on:

**basic product completeness**

Include:

* Product title check
* Description check
* Short description check
* Price check
* Featured image check
* Category check
* Tag check
* SKU check
* Stock check
* Basic content length
* Minimum image count
* Pass/warning/fail statuses
* Admin rule configuration
* Publishing blocking for failed required rules
* Product-list readiness indicator

This is enough to make the plugin a usable standalone product.

---

# 8. Future Version Features

Do not put these into the initial MVP unless development becomes unexpectedly simple.

## V2 — Advanced Product Rules

Potential features:

```text
✓ Gallery image validation
✓ Image dimension validation
✓ Alt-text validation
✓ Product attribute validation
✓ Brand/manufacturer validation
✓ Weight/dimensions validation
✓ Shipping class validation
✓ Tax status validation
✓ Variation completeness
```

### Variation checks

For variable products:

```text
Variation #1
✓ SKU
✓ Price
✓ Stock

Variation #2
✗ Price missing
```

This could become a strong feature later.

---

# 9. V3 — Product Quality Rules

Move beyond “field exists”.

Examples:

```text
Title length
Description quality
Duplicate titles
Duplicate SKUs
Incomplete attributes
Missing brand
Missing specifications
Poor image quality
```

Potential concept:

```text
Product Quality Score
87 / 100
```

Keep scoring out of V1. A checklist is easier to understand and easier to trust.

---

# 10. Future Team / Workflow Features

Possible future versions:

### User roles

```text
Writer
Editor
Publisher
Admin
```

### Approval workflow

```text
Draft
   ↓
Needs Review
   ↓
Approved
   ↓
Published
```

### Reviewer notes

```text
Reviewer:
"Please add a second product image."
```

### Activity history

```text
Checklist failed
Checklist fixed
Product approved
Product published
```

These features could move the product toward a **WooCommerce product QA workflow** rather than just a checklist.

---

# 11. Future Automation Features

Later versions could add:

```text
Auto-check on product save
Scheduled product audits
Bulk scan all products
Bulk report
Email notifications
```

Example:

```text
Weekly Product Audit

1,248 products scanned

Ready:       1,102
Warnings:       97
Failed:         49
```

This is potentially useful for larger stores.

---

# 12. Future Pro / Paid Direction

A sensible paid boundary is:

### Free

Single-product validation + basic rules.

### Pro

Advanced store-wide quality management.

Potential Pro features:

```text
Advanced rule builder
Custom validation rules
Bulk product audit
Variation validation
Image quality checks
SEO-related completeness checks
Product templates
Role-based publishing rules
Approval workflow
Scheduled scans
Reports
Email notifications
CSV export
Webhook/API support
```

A good conceptual difference is:

```text
FREE
"Check this product."

PRO
"Control product quality across my store."
```

That distinction should guide future development.

---

# 13. What to Avoid

This is important for preventing scope creep.

## Avoid in V1

### 13.1 Do not become another WooCommerce editor

Do not build:

```text
Custom product editor
Custom product builder
Drag-and-drop product editor
```

Use WooCommerce's existing editor.

---

### 13.2 Do not become an SEO plugin

Avoid trying to compete with:

* Yoast
* Rank Math
* AIOSEO
* SEOPress

You can eventually check whether SEO-related product fields exist, but SEO should not be the product's core identity.

---

### 13.3 Do not become an AI product writer

Do not begin with:

```text
AI product descriptions
AI titles
AI SEO
AI product images
AI rewriting
```

Those features drastically increase API cost, complexity, and support requirements.

They can be considered much later.

---

### 13.4 Avoid excessive WooCommerce compatibility in V1

Do not initially promise compatibility with every:

```text
WooCommerce extension
Product add-on
Subscription extension
Booking plugin
Membership plugin
Composite products
Bundled products
```

Start with core WooCommerce product types:

```text
Simple
Variable
```

Then expand.

---

### 13.5 Avoid database-heavy architecture

Do not create custom tables just to store temporary checklist results.

Prefer calculating results from product data unless there is a compelling reason to persist audit/history data later.

---

### 13.6 Avoid complicated scoring

Do not initially create:

```text
SEO = 23
Content = 18
Images = 9
Overall = 73
```

A clear checklist is easier for merchants to understand:

```text
✓ Ready
✗ Missing price
✗ Missing image
```

---

# 14. Coding Standards

The coding agent should follow WordPress/WooCommerce ecosystem conventions.

## PHP

Follow:

* WordPress Coding Standards
* WordPress escaping conventions
* WordPress sanitization APIs
* WordPress internationalization
* PHP strictness appropriate to the supported PHP/WP versions
* namespaces where appropriate
* small focused classes
* dependency injection where useful, without overengineering

Prefer:

```php
sanitize_text_field()
absint()
wp_unslash()
esc_html()
esc_attr()
esc_url()
```

where applicable.

Do not trust admin input just because it came from wp-admin.

---

## JavaScript / React

Use WordPress-supported tooling and patterns.

Prefer:

```text
@wordpress/components
@wordpress/data
@wordpress/api-fetch
@wordpress/i18n
```

where appropriate.

Use:

* functional components
* hooks
* clear component boundaries
* reusable validation components
* predictable state management

Avoid giant React components.

For example:

```text
ProductChecklist
    ├── ChecklistSummary
    ├── ChecklistItem
    ├── ChecklistGroup
    └── ChecklistActions
```

---

## CSS

Use plugin-specific class naming to prevent collisions.

Example:

```css
.sit-wcpg-checklist {}
.sit-wcpg-checklist__item {}
.sit-wcpg-checklist__item--error {}
```

Avoid generic classes such as:

```css
.container
.title
.button
.wrapper
```

---

# 15. Security Risk Checklist

The coding agent should treat security as a required development phase rather than something added at the end.

## 15.1 Capability checks

Admin settings must verify the appropriate capability.

Example concept:

```php
current_user_can( 'manage_woocommerce' )
```

Use the capability appropriate to the specific operation rather than assuming every logged-in user is trusted.

---

## 15.2 Nonces

Use WordPress nonces for actions that modify data or settings.

For example:

```text
Save checklist settings
Run bulk action
Change publishing configuration
```

Nonces are not a replacement for capability checks.

---

## 15.3 Sanitize input

Every external input should be validated/sanitized.

Potential sources:

```text
POST
GET
REST requests
AJAX requests
settings
user-generated product data
```

---

## 15.4 Escape output

Escape according to context:

```text
HTML
Attribute
URL
JavaScript
```

Do not simply trust stored values.

This is especially important because product data may contain merchant-authored HTML.

---

## 15.5 REST API security

If the plugin exposes REST endpoints:

```text
permission_callback
authentication
capability checks
input validation
output sanitization
```

must be implemented.

Do not expose product data through publicly accessible endpoints unless there is a clear reason.

---

## 15.6 AJAX security

For AJAX endpoints:

```text
nonce verification
capability verification
input validation
```

must all be present.

---

## 15.7 Stored XSS

Be especially careful with:

* product titles
* descriptions
* category names
* custom rule messages
* admin notes
* imported product data

Never render arbitrary product/admin input as raw HTML unless it is intentionally passed through the appropriate WordPress HTML-safety mechanism.

---

## 15.8 SQL injection

Prefer:

```php
WP_Query
WC_Product
WooCommerce APIs
$wpdb->prepare()
```

Never concatenate untrusted input directly into SQL.

---

## 15.9 CSRF

Any state-changing admin operation should be protected against CSRF through the appropriate WordPress mechanisms.

---

## 15.10 Privilege escalation

Do not allow a lower-privileged user to:

```text
change global publishing rules
bypass checklist requirements
modify plugin settings
approve products
```

unless explicitly authorized.

---

## 15.11 Publishing bypass

This deserves special attention.

The plugin must not rely only on the UI.

For example, this is insufficient:

```text
Button disabled → therefore product cannot publish
```

A user or another integration may bypass the UI.

Publishing enforcement should happen at an appropriate server-side WooCommerce/WordPress hook.

The exact hook and implementation should be determined during the detailed coding-plan phase.

---

# 16. Performance Requirements

The plugin should be lightweight.

### Avoid

```text
Running expensive queries for every product
Loading large JS bundles on every admin page
Repeatedly querying the database for the same product data
Running all rules on unrelated admin pages
```

### Desired behavior

Load checklist assets primarily on:

```text
WooCommerce product edit screens
Relevant product list screens
Plugin settings pages
```

The rule engine should avoid unnecessary duplicate WooCommerce/database calls.

---

# 17. Compatibility Strategy

Initial compatibility target:

```text
WordPress
WooCommerce
PHP
```

The detailed coding plan should define **minimum supported versions** before implementation.

Also test at least:

```text
Simple product
Variable product
Draft product
Published product
Product with missing fields
Product with invalid price configuration
```

Then test:

```text
Classic editor flow
Block-based/current WooCommerce admin UI where relevant
```

Do not claim compatibility with every WooCommerce extension in V1.

---

# 18. Testing Strategy

Your agent should produce tests alongside implementation.

### Unit tests

Test individual rules:

```text
TitleRule
PriceRule
SkuRule
CategoryRule
FeaturedImageRule
DescriptionLengthRule
```

Example:

```text
Product has title
→ PASS

Product has no title
→ FAIL
```

### Integration tests

Test:

```text
Product save
Checklist calculation
Publishing restriction
Settings save
REST/AJAX requests
```

### Security tests

Test:

```text
Unauthorized user
Invalid nonce
Invalid input
Missing capability
Malicious product data
Unauthorized publishing attempt
```

---

# 19. Suggested V1 Admin UI

Keep the interface simple.

### Product screen

```text
──────────────────────────────────
Product Publishing Checklist

✓ Title
✓ Price
✗ Featured image
✓ Category
! Description length
✓ SKU

Required checks: 5 / 6

[3 Issues]
──────────────────────────────────
```

### Settings

```text
WooCommerce
└── Product Checklist

General
  [x] Enable checklist

Required Rules
  [x] Title
  [x] Price
  [x] Featured image
  [x] Category
  [ ] SKU

Content Rules
  Minimum description: 150 characters
  Minimum images: 2

Publishing
  [x] Block publishing when required checks fail
```

---

# 20. Suggested Internal Data Model

Avoid persistent complexity in V1.

### Rule definition

Conceptually:

```text
id
label
description
enabled
severity
validator
```

### Validation result

```text
rule_id
status
severity
message
```

### Product summary

```text
total_checks
passed
warnings
failed
is_ready
```

This gives the agent a clean abstraction to implement.

---

# 21. Development Phases

### Phase 1 — Foundation

```text
Plugin bootstrap
WooCommerce dependency check
Admin integration
Basic architecture
Settings registration
```

### Phase 2 — Rule Engine

```text
Rule interface
Rule registry
Validation result structure
Basic rules
```

### Phase 3 — Product UI

```text
Checklist UI
Summary
Individual rule messages
Automatic refresh
```

### Phase 4 — Publishing Enforcement

```text
Required-rule validation
Server-side publishing protection
Useful admin notices
```

### Phase 5 — Product List

```text
Readiness indicator
Filtering/sorting only if simple enough
```

### Phase 6 — Security + Compatibility

```text
Capabilities
Nonces
Sanitization
Escaping
REST/AJAX security
WooCommerce compatibility checks
```

### Phase 7 — Testing

```text
Unit tests
Integration tests
Security tests
Manual test matrix
```

### Phase 8 — Release

```text
Readme
Screenshots
Documentation
Translation-ready strings
Plugin metadata
Packaging
```

---

# 22. MVP Definition — Stop Here

Your agent should consider **V1 complete** when a merchant can:

```text
1. Install plugin
2. Configure required product checks
3. Open a WooCommerce product
4. See missing/incomplete information
5. Fix the issues
6. See readiness update
7. Be prevented from publishing when required checks fail
8. Identify failed products from the product list
```

Anything beyond this should be considered optional unless it is necessary for stability/security.

---

# 23. Future Version Roadmap

```text
V1 — Product Publishing Checklist
│
├── Basic product checks
├── Required/warning rules
├── Product editor UI
├── Publishing enforcement
└── Product list readiness
        │
        ▼
V2 — Advanced Product QA
│
├── Variations
├── Attributes
├── Images
├── Alt text
├── Dimensions/weight
└── Advanced rule configuration
        │
        ▼
V3 — Store-wide Product Audit
│
├── Bulk scanning
├── Reports
├── Scheduled audits
├── Email notifications
└── CSV export
        │
        ▼
V4 — Product Workflow
│
├── Roles
├── Approval
├── Reviewer notes
├── Audit history
└── Publishing workflow
        │
        ▼
V5 — Pro / Automation
│
├── Custom rules
├── APIs/webhooks
├── External integrations
├── Advanced analytics
└── Optional AI-assisted checks
```

# 24. Important Product Positioning

I would keep the core product concept very narrow:

> **WooCommerce Product Publishing Checklist — prevent incomplete products from being published.**

Not:

> “All-in-one WooCommerce product management.”

That narrow scope is useful for your MVP, GitHub portfolio, future WordPress.org release, and eventual Pro version.

---

# 25. Instructions for Your Coding Agent

Give your agent this document first, then ask it to produce a **detailed coding plan before writing code**.

The detailed plan should explicitly define:

```text
Plugin directory structure
PHP classes/interfaces
WooCommerce hooks
WordPress hooks
React components
REST/AJAX architecture
Settings API implementation
Rule registration architecture
Publishing validation mechanism
Data flow
State management
Security controls
Testing strategy
Coding standards
Compatibility strategy
Build/package process
```

And use this rule:

> **Do not implement future-version features unless they are required by V1 architecture. Design extension points, but keep V1 implementation small.**

That will help prevent the coding agent from turning a relatively focused plugin into a large WooCommerce management system.
