# Extension surface audit for v1.2

This is the proposed inventory for [#235](https://github.com/adamgreenwell/kitsune/issues/235).
The stable contract starts when the v1.2 release publishes the selected exports and
their compatibility probes. Until then, use the first-party and candidate workflows
described in this guide. [Extension compatibility and deprecation](extension-compatibility.md)

## Proposed author-facing boundaries

| Area | Candidate to retain | Implementation that stays internal |
| --- | --- | --- |
| Model scope and validation | The four scope attributes, `EnforcesScope`, and `Kitsune\Core\Validation\Rule::scopedUnique()` and `scopedExists()`. Audit the supported host context API alongside these primitives. | Raw query escapes, scope walkers, builder guards, and the generated API’s value conversion and validation rules. |
| Module package and boot | The Composer `extra.kitsune.provider`, `extra.kitsune.scoping`, and PSR-4 requirements. Evaluate the provider’s `registerModule()`, `bootModule()`, `migrationPath()`, `install()`, and `uninstall()` hooks with an independently admitted consumer. | The kernel, receipt storage, Laravel registration order, and internal admin, privacy, settings, and maintenance adapters. Approve the enable and disable events separately once their timing and payloads have compatibility probes. |
| Blueprint definition and registration | `BlueprintDefinition`, the declaration objects it accepts, and the proposed boot-time `BlueprintRegistrar::register()`. Keep apply, status, seed, and reverse available through the documented operator workflow. | `BlueprintRegistry`, `BlueprintApplier`, dependency order, receipts, and first-owner provisioning. Review the supported declaration parameters together, including those that compose CMS and DAM content. [Registration draft](https://github.com/adamgreenwell/kitsune/pull/475) |
| Blade themes | The theme layout, manifest, Site selection commands, and admitted template precedence. For contract 1, review the `ThemeView` values and methods below, plus `ThemeViewSelection::forRender(int $orgId, int $siteId, ?string $entryType)` for the host. | Theme discovery, renderers, raw Site and Entry models, request cache plumbing, route resolution, and media delivery internals. [Projection draft](https://github.com/adamgreenwell/kitsune/pull/466) · [Inline image draft](https://github.com/adamgreenwell/kitsune/pull/478) |
| Site draft content | The proposed `SiteDraftFields::describe()` and `validate()`, and `SiteDraftWriter::save()`. Preserve the affirmative staff and Site checks, the reviewed field fingerprint, ordered allowed relations, and the revision comparison. | `EntryFieldContract`, `EntryRelationWriter`, storage rows, API serialization, audit internals, and private importer bookkeeping. [Field draft](https://github.com/adamgreenwell/kitsune/pull/476) · [Write draft](https://github.com/adamgreenwell/kitsune/pull/477) |
| Batch Site address changes | `Site::saveAllInHostOrder()` from #71. Qualify its outer transaction requirement, a single Org and connection, returned saved models, and rollback and retry behavior. | Hostname locks, origin queries, and observer checks. This batch covers Site rows and their address claims. The draft entry writer covers an Entry and its verified relations in the selected Site. Plugin tables retain their own persistence boundary. [Batch draft](https://github.com/adamgreenwell/kitsune/pull/479) |
| Plugin author tools | The SDK test base and scope fixture, the explicit source validator workflow, and the optional Boost guidelines. Qualify the authoritative example as an installed package. | Static-analysis heuristics, review workflow plumbing, and trust-admission decisions. [Plugin integration guide](plugin-integrations.md) |

### Candidate Blade contract

A theme uses a handle of up to 48 characters. Its manifest declares
`entry_types` and can opt into the new view with `"contract": 1`. Contract 1 exposes `siteName`, `homePath`, `locale`, `direction`, `entry`, and
`hostData`. The entry projection has `title`, `type`, and `path`. The host sets
the field and relation allowlist for the rendered Site. Its separate `hostData`
uses host-defined keys and the host’s access checks.

- `menu($handle)` provides authored links with labels, paths, and children.
- `related($handle)` supplies eligible published links in configured order.
- `field($handle)` supplies an allowlisted inline value, its type, and cardinality.
  Use normal Blade escaping for that value.
- `richText($handle)` supplies sanitized rich text for a selected field. Authorized
  public image delivery remains through the existing Site media path.

Qualify old templates and contract 1, each with its own published probe. Use the real
Mactacular theme and a second consumer. Cover sibling Sites, another Org, RTL, menu
and related link shapes, explicit field selection, and long-lived contexts.
[Blade theme operator guide](blade-themes.md)

### CMS, DAM, and learning workflows

A blueprint provides the content types and fields. A versioned theme renders the
published projection and receives the host-selected values for that Site. Readers’
access to gated routes or private learning assets is a separate operation. The
fixture on [#235](https://github.com/adamgreenwell/kitsune/issues/235) used a
Site-bound entitlement and Core’s private media delivery to qualify a course PDF.
Treat the entitlement-writing and reader-account implementations as first-party
while their extension boundaries are under review.

The author workflow must keep a sibling Site and a foreign Org in its tests. Verify
grant, withdrawal, and delivery through the real entry point when a composition
uses private assets. Keep a plugin’s grades, progress, or other custom records under
its own permissions and storage policy.

## Existing first-party exceptions

The named exceptions in [CONTRIBUTING](../CONTRIBUTING.md#things-that-wont-be-merged)
retain the scope of their originating ADRs. The freeze can select an individual
export or leave it first-party.

| Existing surface | Disposition for this proposal |
| --- | --- |
| `ModuleServiceProvider`, `ModuleEnabled`, and `ModuleDisabled` | Evaluate the specific protected provider hooks above. Keep the events first-party pending exact payload and timing checks. |
| `ProvisionsMembership` | Keep the existing first-owner host exception. Review the signature and host upgrade fixture separately. |
| `kitsune.readers.guard`, `ReaderAccount`, and `ReaderRoutes::register()` | Keep the existing host placement and account contract. Review additions through optional capabilities and leave reader-session internals outside theme and plugin projections. |
| `CatalogueRoutes::register()`, `CartRoutes::register()`, and `BoundedWebhookRequest::capture()` | Keep their existing first-party host placement. Provider admission, payments, and commerce data internals require separate qualification. |
| `SiteApiRoutes::register()` | The skeleton calls this internal entry point from its bootstrap. Keep it a first-party dependency while reviewing host upgrades. Qualify documented HTTP routes and token behavior with API consumers. [Site API tokens](api-tokens.md) |

The skeleton and first-party modules also call implementation classes directly.
The copied reader model implements `AnchorsReaderErasure` and uses
`ReaderColumnClass` alongside `ReaderAccount`. Review these dependencies as part
of the host upgrade, including the host-owned erasure anchor. Record direct
internal usages and migrate the ones that need an independent extension path. The Mactacular importer is the first case for the narrow draft
field and write services. Keep packaged baseline consumers when retiring the old
first-party calls.

## Record and verify the inventory

From a Kitsune development checkout, run the source survey.

```sh
php bin/inventory-extension-surface.php > extension-source-survey.json
```

The survey records PHP declarations, visible members and parameter names, literal
Artisan signatures, literal package configuration paths, and direct Core imports
from the skeleton and first-party packages. It preserves
`@internal` and labels the remaining declarations unclassified. Review inherited
and dynamic behavior, named routes, service bindings, setting and environment
keys, and operator commands against the actual host and package workflows. The
survey on the current audit base found 557 named PHP declarations, 30 command
signatures, 14 configuration paths, and 181 direct Core import occurrences of
symbols marked internal. These counts describe the survey input.

The operator contract covers documented commands such as `kitsune:site`,
`kitsune:theme`, `kitsune:blueprint`, and `kitsune:module`. Keep the CLI signatures
and the corresponding command implementations in their respective reviews. The
same distinction applies to documented media disk settings, API configuration,
password settings, and the deployment starting point.
[Application and operator contract](versioning-and-upgrades.md)

At the freeze, bind each selected export to its retained consumer probe and verify
the exact candidate installation. Admit the child changes that supply these
boundaries, review the first-party replacements, and qualify the combined packages.
The [compatibility checker](extension-compatibility.md) compares that published
inventory and retained consumer bytes for subsequent releases.
