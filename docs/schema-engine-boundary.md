# Why the schema engine stays in core

Issue [#257](https://github.com/adamgreenwell/kitsune/issues/257) reconciles the proposed first-party module in
ADR-001 with the engine built into `kitsune/core`. The decision is to retain the current boundary. This assessment
does not extract a package or change runtime behavior; ADR-001 and ADR-038 retain the earlier reasoning and record
the decision.

## Dependencies and cost

The inventory read tracked bytes at main `4e6f9059d841533b7ff45e18685da2c7022392ab`. It counted explicit top-level
`use Kitsune\…` imports across two possible boundaries:

| Candidate file set | Files / PHP source lines | Incoming imports | Outgoing imports |
| --- | ---: | ---: | ---: |
| `Fields/` and `Schema/` | 36 / 12,899 | 69 from 22 files | 19 to 8 targets |
| Above plus Entry, EntryType, Field, FieldStorage, EntryRelation and EntryRevision | 42 / 19,605 | 142 from 48 files | 40 to 22 targets |

These counts are lower bounds. They omit fully qualified references, strings, container resolution and runtime
calls; an import can remain valid after a package move, so they are neither a namespace rewrite count nor a time
estimate. No alternative package was built or benchmarked.

The dependencies cross both ways: core Permissions reads Entry/EntryType, and Entry uses core permissions, audit
and scope enforcement. Core's provider binds FieldTypeRegistry and RecordedRevisions before its module booting
callback; it loads the schema migrations and installs the Entry policy. Blueprints, Person, media and reader
downloads use the shared content models. The current package has Laravel provider discovery, not an
`extra.kitsune` manifest or a module provider.

| Choice | Work and lifecycle consequence |
| --- | --- |
| Keep the engine in core | Documentation reconciliation; zero production code, manifest, migration or stored-row changes. Keeps the existing install and upgrade path. |
| Split a required Composer package | Define package dependencies and provider ordering, migration ownership and release/install tooling. The engine still cannot be omitted; a separate package alone provides no optional lifecycle or demonstrated resource saving. |
| Make it optional under the module kernel | The required split work plus admission rules for dependent consumers, missing/stale/disabled runtime behavior, retained-content and privacy access, destructive uninstall refusal, and migration/rollback recovery for existing installations. None follows from moving the 36 or 42 files. |

No current consumer requirement justifies the latter two costs. This is a bounded decision for the present
architecture, not proof that extraction is impossible or an estimate of how long it would take. A later proposal
must identify its consumer and demonstrate those lifecycle transitions before changing this decision.

## Measured current behavior

Fresh-process probes used PHP 8.5.10, the root development lock, a private Laravel application with no panel and
private SQLite databases. The source was main `4e6f905`; the behavioral cases were repeated after integrating
#313's `ed51194` candidate. Every process began with no Eloquent connection resolver. No application source or
Composer dependency was removed or replaced.

| Case | Observation |
| --- | --- |
| Empty database, no `modules` table | Application boots; all 12 field types and SchemaManager resolve without a receipt. |
| Enabled fixture with the real installed version | Its provider registers and boots; the same engine resolves. |
| Disabled fixture / stale receipt / enabled receipt for an absent package | The fixture runtime does not register; the same engine resolves. These exercise current module admission, not a hypothetical extracted engine. |
| Core passed to module install / disable / uninstall | Install refuses the absent module manifest; disable and uninstall refuse the absent module receipt. |
| New core migrations, no module receipts | Migrations succeed and create the content tables without installing a schema module. This is a private application probe, not a complete `create-project` installation. |
| Real Person install, enable, synthetic entry, then disable | Entry, type, fields and receipt remain. Uninstall refuses while the person remains, with the retained type still present. |
| Core migrations repeated on the populated database | Exit 0 and every table's rows remain byte-equivalent in the probe's serialized comparison. This measures already-applied migration idempotence, not migration relocation or recovery from an interrupted upgrade. |
| Receipt update inside a real database transaction, then rollback | Person remains disabled. This narrow row rollback does not claim DDL rollback or recovery on MySQL/MariaDB. |

An additional autoloader fault injection refused the Fields namespace without editing any source. Console
bootstrap still completed; resolving FieldTypeRegistry then raised the injected missing-class error. Lazy binding
therefore does not make the engine independently optional. This is a dependency fault probe, not a completed
package extraction or proof that every request fails at boot.

Five further fresh processes per admission state measured the bootstrap call only:

| State | Median milliseconds | Range milliseconds | PHP allocated peak |
| --- | ---: | ---: | ---: |
| No receipt table | 58.423 | 55.478–173.156 | 28 MiB |
| Enabled fixture | 59.525 | 57.400–76.911 | 28 MiB |
| Disabled fixture | 58.249 | 53.988–59.484 | 28 MiB |
| Stale fixture | 59.776 | 57.967–62.344 | 28 MiB |
| Absent package | 58.020 | 56.562–59.848 | 28 MiB |

These are local console observations with development dependencies, no CPU/RAM limit and an OS page cache shared
between processes. They do not measure HTTP, onboarding, minimal production dependencies, an extracted alternative
or the ADR-027 floor, and they establish no speed or memory benefit from either boundary. The five-process sample
is descriptive, not a statistical comparison.

## Existing guarantees and limits

Retaining core preserves migration names and ownership, the one installed engine and the existing content model
identities. It adds no service, worker, Node runtime or container requirement. The default suite remains SQLite on
a bare clone; other database engines retain their existing test obligations. No new resource-floor qualification
is claimed by these host probes.

Actual module behavior remains covered by `ModuleKernelTest`, `ModuleBootOrderTest`, `ModuleLifecycleTest`,
`ModuleRouteCacheTest`, `ModuleMaintenanceCommandsTest` and `PersonModuleTest`. Their scopes differ: the fresh
application tests cross provider ordering, while seam tests exercise lifecycle refusals and transactions. The
full gate must still pass; a probe is not a substitute for it. No admin page, route or runtime code changes here.

The core-migration idempotence result does not cure edited historical migrations or the partial-DDL limitations
in ADR-038. It provides no backup, rollback, version-transfer or already-deployed upgrade guarantee. A future split
would need those procedures and native-engine evidence of its own. Extraction is not selected, so #257 creates no
extraction implementation follow-ups.
