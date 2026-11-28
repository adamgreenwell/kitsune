# Choosing a plugin integration

Use the schema engine when editors can work with an entry type and its fields. Use a
standalone Filament Resource when a workflow needs its own models, storage, or admin
pages. One installation can combine both with blueprints for content structure and
Blade themes for public presentation.

This guide describes the current author workflow. The v1.2 extension contract will
identify the supported APIs and compatibility guarantees. Use first-party modules
such as `kitsune/person` to understand the implementation, and check the contract
before depending on a class, hook, or view.

## Schema-engine content

An editorial module can use a blueprint to define a `post` type, a related `tag` type,
and grants for writers and editors. The built-in Blog blueprint is a working example.
An Org owner applies it to the Org, selects where its types are enabled, and assigns
the appropriate roles.

| Responsibility | Kitsune provides | The extension or host provides |
| --- | --- | --- |
| Admin routing | The shared content Resource and type-aware routes under the selected Site. Core resolves the type and checks its availability. | Type handles that pass the reserved-handle rules and any independently registered routes. |
| Authorization | Entry policies and the configured create, view, update, publish, and delete checks. | Appropriate grants, role assignment, and policy checks for custom actions or endpoints. |
| Data scope | Scoped entry and relation reads and writes through the shared content Resource. | The intended Site and Org sharing rules for the content. Any separate model needs its own scope. |
| Admin UI | Forms, lists, and related content built from the configured fields. | Field definitions, labels, and any additional workflow beyond the shared Resource. |

The owner can apply the DAM blueprint to the same Org for asset management.
The host can select a Blade theme for public presentation and choose what content it
publishes. Verify Site enablement, entry grants, and theme selection independently.
[Schema engine and module boundaries](schema-engine-boundary.md) · [Blade themes](blade-themes.md)

## Standalone Filament Resources

Consider a training module with a custom grading workflow. It can own a
Site-scoped `TrainingNote` model and register a separate Filament Resource for
its list, create, and edit screens. Here, standalone describes the Resource and its
storage. The Resource still runs inside the host’s panel. The host registers the plugin on the
chosen panel. That panel registration and a Kitsune module’s install and enablement
are separate decisions. [Filament panel plugin guide](https://filamentphp.com/docs/5.x/plugins/building-a-panel-plugin) · [Kitsune module operations](module-operations.md)

| Responsibility | The extension or host provides |
| --- | --- |
| Admin routing | Register the Resource and its pages in the panel. Verify Site context on the list, individual record URLs, and custom actions after rebuilding the route cache. |
| Authorization | Define policies for list, create, and record operations. A user who can open the panel or edit a schema-engine entry may still lack permission to grade a note. |
| Data scope | Declare and enforce the model scope. Protect record lookup, relationships, and writes. Use scoped validation for uniqueness and existence. Scope each custom query and check both sibling Sites and another Org. |
| Admin UI | Supply the table, forms, navigation, and workflow. Verify that hidden navigation and actions agree with direct-route authorization. |

These Resources have their own schema and policy. Filament’s panel context can
support Resource queries, while Kitsune’s shared entry routes, field editor,
and entry permissions apply to schema-engine content. An independent Resource must
enforce its own boundary end to end. [Filament Resource overview](https://filamentphp.com/docs/5.x/resources/overview)

Test an authorized editor creating and updating a note in one Site. Check the saved
Org and Site keys and the visible rows. Then open the same record URL as an editor on
a sibling Site and on a Site in another Org. Check that a staff viewer who can open
the built-in content list cannot use the note Resource without its grant. Remove the
plugin from the host’s panel provider, rebuild the route cache, and verify that
the Resource routes disappear while the selected Blade theme still renders.

## Validate and review

- Use the [plugin SDK candidate](../packages/plugin-sdk/README.md) to test Site and
  Org isolation with a fresh query or request under each scope. Add authorization,
  write, and route tests for the extension’s own entry points.
- For a package with a Kitsune manifest, run the source validator from a trusted
  Kitsune development checkout. The strict check exits with status 3 when findings
  require manual review. Evaluate them alongside actual behavior in a disposable host.

  ```sh
  php bin/check-plugin.php --strict /path/to/extension
  ```

- The validator currently requires a Kitsune manifest. Use installed-source review
  and host tests for a conventional Filament package. Confirm which files were
  installed and which routes, models, and migrations the host actually enables. A static pass covers the source
  checks the validator recognizes. Admission to a supported allowlist also requires
  a scope and authorization audit. [Filament and the two extension tiers](decision-log.md#adr-008--build-on-full-filament-v5-panels)
