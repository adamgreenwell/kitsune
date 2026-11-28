## Kitsune extension authoring

Use a blueprint for content structure, a Blade theme for presentation, and a Composer
module for optional behavior. An installation can combine these pieces for content,
digital assets, and learning workflows. Check the targeted Kitsune release’s extension
contract before choosing classes, interfaces, or hooks. Treat `@internal` classes as
implementation details.

### Scope models, reads, and writes

- Declare exactly one scope per model and apply `Kitsune\Core\Tenancy\Concerns\EnforcesScope`.
  Use `SiteScoped` for Site-owned rows, `OrgScoped` for rows shared within an Org,
  `OrgScopedThroughPivot` for membership-scoped models, and `Unscoped` for genuinely
  global rows. The attributes are in `Kitsune\Core\Tenancy\Attributes`.
- Include each model scope in the module’s Composer manifest under
  `extra.kitsune.scoping`. A module with only a Site-scoped model declares `["site"]`.
  Lead lookup indexes with the scope key, `site_id` or `org_id`, where applicable. Treat Org and Site as
  separate boundaries.
- Use scoped Eloquent queries for reads, writes, relations, and route binding.
  Keep them scoped through both record lookup and authorization. A missing Org or
  Site context must produce a refusal or no results where that scope is required.
- Exercise a sibling Site, a foreign Org, and missing context through the real entry
  point. Check denial for a caller who can see the resource but lacks the requested
  permission. Scope and operation-level authorization are separate checks.

### Validate within the current scope

Import `Kitsune\Core\Validation\Rule`. Use model-backed rules such as
`Rule::scopedUnique(TrainingNote::class, 'slug')` and
`Rule::scopedExists(TrainingNote::class, 'id')`. Use the declared Site-scoped model in
this example. Bare Laravel `unique` and `exists` rules use table queries that miss
Eloquent scope. This applies to validation strings, Laravel’s rule builders, and
Filament’s bare validation helpers.

### Route by admitted content type

Treat route parameters as untrusted. Resolve requested entry types inside the current
Org and Site, including Site enablement. Reject foreign, unknown, and disabled types.
Use the entry type’s reserved-handle validation and avoid Filament action and page
segments such as `create` and `edit`. For a new admin route shape, include a browser
check that opens a page outside the custom content resource.

### Keep field types portable

Use Kitsune’s schema driver abstraction for generated columns and indexing. SQLite,
MySQL, and PostgreSQL differ in their DDL and capabilities. Keep engine-specific SQL
in drivers. Publish rule constraints in the field’s API schema and verify validation,
storage round trips, scoping, and indexes on the supported engines.

### Run the validator and the host checks

Run the plugin validator from a trusted Kitsune checkout against the separate
plugin directory. Resolve both failures and manual review findings. A validator pass
covers the source checks it can recognize. Verify authorization, route behavior,
schema portability, and disabled or missing-context behavior in the running host.
