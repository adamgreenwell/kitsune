<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Kitsune\Core\Exceptions\ReservedHandleException;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * @property int $id
 * @property int|null $org_id
 * @property string $handle
 * @property string $name
 * @property string $plural_name
 * @property bool $is_system
 * @property int|null $subject_field_id
 * @property array<string, mixed>|null $settings
 */
#[Unscoped]
class EntryType extends Model implements RefusesCascadingDeletes, RequiresModelSave
{
    /**
     * Handles that would collide with a route segment (ADR-012).
     *
     * A type literally named "create" would make /c/create/create ambiguous.
     * Rejected at creation time rather than escaped later, because the
     * collision is with the URL contract, not with SQL.
     */
    public const RESERVED_HANDLES = [
        'create', 'edit', 'delete', 'update', 'view', 'index',
        // Registered by EntryResource::getPages(). Adding a page without
        // adding its segment here reopens the collision — this entry exists
        // because exactly that happened with /{type}/{record}/related.
        //
        // Both the URL segment and the page key are reserved. They differ
        // here ('related' vs 'relations'), and reserving one word nobody
        // needs as an entry type costs nothing next to a route collision
        // that cannot be escaped away.
        'related', 'relations',
    ];

    /**
     * The entry types a site may use, in display order.
     *
     * Core rather than the admin panel: ADR-002 keeps core headless-capable,
     * and the API needs the same answer the sidebar does.
     *
     * @return Collection<int, static>
     */
    public static function visibleFor(?Site $site, ?int $orgId = null): Collection
    {
        $orgId ??= $site?->org_id;

        // ⚠️ This closure captures SCALARS ONLY, and that is load-bearing.
        //
        // once() hashes the closure's captured variables, and hashes an
        // object by spl_object_id — which PHP recycles the moment an object
        // is collected. A memo keyed on the Site object therefore cannot
        // reliably tell two sites apart inside one process, and under Octane
        // or a queue worker one site is served the other's list.
        //
        // A first attempt captured the object AND an unused $siteKey to fix
        // the key. Pint stripped the unused variable from the use clause and
        // the bug came straight back. So the values that make the key correct
        // have to be values the body genuinely uses — which is why
        // enabledMapFor() takes scope keys rather than a Site.
        $siteId = $site?->getKey();
        $siteGroupId = $site?->site_group_id;

        return once(function () use ($orgId, $siteId, $siteGroupId) {
            return static::query()
                ->where(function (Builder $query) use ($orgId): void {
                    $query->whereNull('org_id');

                    if ($orgId !== null) {
                        $query->orWhere('org_id', $orgId);
                    }
                })
                ->orderBy('ordering')
                ->orderBy('handle')
                ->get()
                // Collapse shadowed handles FIRST, applying the same
                // precedence IdentifyEntryType uses: an org's own type wins
                // over the global one it shadows. Filtering before collapsing
                // produced two items pointing at one URL when both were
                // enabled, and — worse — kept the global item alive when the
                // org row that actually resolves was disabled, offering a
                // link guaranteed to 404.
                ->sortBy(fn (self $type): int => $type->org_id === null ? 1 : 0)
                ->unique('handle')
                ->pipe(function ($types) use ($siteId, $siteGroupId, $orgId) {
                    // One query for every type, not one per type: ADR-012's
                    // 201-type case would otherwise render ~202 queries.
                    $enabled = EntryTypeAvailability::enabledMapFor(
                        $types->pluck('id')->all(), $siteId, $siteGroupId, $orgId,
                    );

                    return $types->filter(fn (self $type): bool => $enabled[$type->getKey()] ?? true);
                })
                ->sortBy('ordering')
                ->values();
        });
    }

    /**
     * An identifier identifies ONE subject, so it cannot hold many values.
     *
     * ⚠️ An inline field holding a JSON ARRAY — `multi_select`, or anything
     * with cardinality other than one — cannot be matched by the equality
     * predicate `whereSubjectIs()` uses, so it silently matched nothing:
     * every request about a person came back empty while the holes report
     * called the type answerable.
     *
     * Refused rather than papered over with a JSON-membership predicate.
     * "Everything you hold about this person" needs a field that names one
     * person; a list of values is not that, and a relation already covers the
     * case where the subject IS another record.
     */
    private function guardSubjectShape(Field $field): void
    {
        $storage = $field->fieldStorage;

        if ($storage === null) {
            return;
        }

        // ⚠️ Storage ownership, checked HERE because `Field::create()` skips
        // the Field guard entirely — the listener returns early on a model
        // that does not yet exist. So the ordinary create-then-nominate
        // sequence could back a nomination with a RIVAL ORG's storage, which
        // FieldStorage being #[Unscoped] does nothing to prevent.
        //
        // A global storage row (org_id NULL) is legitimate, matching how
        // global entry types work.
        // ⚠️ A GLOBAL type may only nominate GLOBAL storage. Exempting it
        // let one customer's storage definition decide every org's
        // subject-access behaviour, since a global type is available to all
        // of them — a wider blast radius than the cross-org case, not a
        // narrower one.
        if ($storage->org_id !== $this->org_id && $storage->org_id !== null) {
            throw new RuntimeException(sprintf(
                'Field [%s] is backed by %s and cannot identify a data subject on %s. '
                .'Subject-access requests would be answered against a definition this entry type '
                .'does not control (ADR-020, ADR-021).',
                $storage->handle,
                "another organisation's storage",
                $this->org_id === null ? 'a global entry type' : 'this entry type',
            ));
        }

        $config = new FieldConfig($storage, $field);

        // ⚠️ A relation is exempt from the array-shape rule ONLY at
        // cardinality one. A relation that can point to several people
        // returns all of their ids from `subjectValue()`, and
        // `whereSubjectIs()` returns the same record for each — so a
        // subject-access export would disclose a record shared with another
        // subject to both of them.
        if ($storage->strategy() === StorageStrategy::Relational) {
            if ($config->isMultiValue()) {
                throw new RuntimeException(
                    "Field [{$storage->handle}] can point to several entries and cannot identify a data "
                    .'subject: a request about one person would return records belonging to another '
                    .'(ADR-020). Nominate a relation limited to one target.'
                );
            }

            return;
        }

        if ($config->isMultiValue() || $this->publishesAnArray($storage, $config)) {
            throw new RuntimeException(
                "Field [{$storage->handle}] holds many values and cannot identify a data subject. "
                .'A subject identifier names one person; `whereSubjectIs()` would match nothing at '
                .'all, which looks exactly like a type with no subject nominated (ADR-020). Nominate '
                .'a single-valued field, or a relation if the subject is another entry.'
            );
        }

    }

    private function publishesAnArray(FieldStorage $storage, FieldConfig $config): bool
    {
        // The published API contract is the right source of truth here: if a
        // consumer is told to expect an array, it is an array.
        return (app(FieldTypeRegistry::class)->get($storage->type)->apiSchema($config)['type'] ?? null) === 'array';
    }

    /**
     * The field that identifies the data subject (ADR-020 primitive #2).
     *
     * "Give me everything you hold about this person" is unanswerable without
     * knowing which field identifies the person. A `customer` type nominates
     * its email; a `ticket` type nominates its `person` relation.
     *
     * @return BelongsTo<Field, $this>
     */
    public function subjectField(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'subject_field_id');
    }

    /**
     * The `values` key holding the subject identifier, or null if none is set.
     *
     * The handle comes from the field STORAGE, not from the per-type field
     * row: storage owns the handle, and the handle is the JSON key.
     */
    public function subjectHandle(): ?string
    {
        return $this->subjectStorage()?->handle;
    }

    /**
     * The storage row behind the subject field, or null if none is nominated.
     *
     * Callers need the storage rather than the handle, because whether the
     * value lives in `values` or in `entry_relations` is a property of the
     * storage strategy — and ADR-020 supports both shapes.
     */
    public function subjectStorage(): ?FieldStorage
    {
        return $this->subjectField?->fieldStorage;
    }

    /**
     * Types holding personal data that nobody can answer a request about.
     *
     * The enforcement that actually works. A fail-closed guard at save time
     * cannot: the subject IS one of the type's fields, so refusing to save
     * until one is nominated makes adding the first field impossible. What
     * can be enforced is visibility — this is the list of holes, and it is
     * the honest answer to "is a subject-access request answerable here?"
     *
     * @return Builder<static>
     */
    public static function withoutSubjectIdentifier(): Builder
    {
        // ⚠️ Org-scoped explicitly. EntryType is #[Unscoped] by declaration,
        // so an unqualified query returns every org's types — and a
        // compliance report is exactly the surface where leaking another
        // customer's schema metadata would matter most (ADR-021).
        return static::query()
            ->availableToCurrentOrg()
            ->whereNull('subject_field_id')
            ->whereHas('fields.fieldStorage', function (Builder $query): void {
                // ⚠️ The nested query is scoped too. Scoping only the outer
                // EntryType left FieldStorage — which is #[Unscoped] — free
                // to match another org's row, so a rival's classification
                // could decide whether this org's type counted as a hole.
                // Field now refuses that attachment on save; this keeps the
                // report right for any row that predates the guard.
                $orgId = app(Context::class)->orgId();

                $query->whereIn('pii_class', ['personal', 'sensitive'])
                    ->where(function (Builder $storage) use ($orgId): void {
                        $storage->whereNull('org_id');

                        if ($orgId !== null) {
                            $storage->orWhere('org_id', $orgId);
                        }
                    });
            });
    }

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Unscoped by declaration, constrained by query.
     *
     * org_id NULL means a global system type available to every org, so a
     * blanket OrgScope would hide exactly the rows every org needs. This
     * scope is therefore opt-in per query rather than global — and callers
     * that forget it see global types only, never another org's.
     *
     * @param  Builder<EntryType>  $query
     * @return Builder<EntryType>
     */
    public function scopeAvailableToCurrentOrg(Builder $query): Builder
    {
        $orgId = app(Context::class)->orgId();

        return $query->where(function (Builder $inner) use ($orgId): void {
            $inner->whereNull('org_id');

            if ($orgId !== null) {
                $inner->orWhere('org_id', $orgId);
            }
        });
    }

    /**
     * Moving a type across the org boundary drags its fields with it.
     *
     * Refused while any attached storage would become foreign, rather than
     * silently rewritten: the destination org would be able to traverse the
     * former org's schema metadata, while the scoped holes report excludes
     * that storage and therefore stops naming a type that still holds
     * personal data (ADR-021).
     *
     * Global storage travels freely, matching how global types work.
     */
    private function guardOrgMove(): void
    {
        if (! $this->exists || ! $this->isDirty('org_id')) {
            return;
        }

        $foreign = Field::query()
            ->where('entry_type_id', $this->getKey())
            ->whereHas('fieldStorage', function (Builder $query): void {
                $query->whereNotNull('org_id');

                if ($this->org_id !== null) {
                    $query->where('org_id', '!=', $this->org_id);
                }
            })
            ->with('fieldStorage')
            ->first();

        if ($foreign !== null) {
            throw new RuntimeException(sprintf(
                'Entry type [%s] cannot move to another organisation while [%s] is backed by '
                .'storage that would not move with it. Detach the field or make its storage '
                .'global first — the destination would otherwise read the former org\'s schema '
                .'(ADR-021).',
                $this->handle,
                $foreign->fieldStorage->handle,
            ));
        }
    }

    /**
     * ⚠️ Columns whose guards can only run per row.
     *
     * A nomination is checked against the field's TYPE, its org and its shape.
     * The foreign key proves only that the field exists, so a bulk update
     * could point subject-access queries at another type's or another org's
     * schema.
     *
     * @return array<string, string>
     */
    /**
     * ⚠️ Refuses while entries still reference it, because the database would
     * remove them itself.
     *
     * `entries.entry_type_id` is `cascadeOnDelete`, so deleting this removed
     * every referenced entry INSIDE the database: no per-row model event, so no
     * audit row, and a hard DELETE, so Entry's SoftDeletes never applied and
     * the rows were unrecoverable. Verified by probe.
     *
     * Reachable from the BUILDER as well as from `deleting`, through
     * `RefusesCascadingDeletes` — written only as a model event it covered one
     * path, and `query()->delete()`, `deleteQuietly()` and `withoutEvents()`
     * all dispatch straight past it.
     *
     * Deleting an ORG still takes everything: that cascade happens in the
     * database and fires nothing here, which keeps the documented exception
     * working.
     */
    public function guardCascade(): void
    {
        $entries = Entry::withoutScopeBecause(
            'counting entries before their type is deleted, to refuse rather than cascade',
            fn ($query) => $query->withTrashed()->where('entry_type_id', $this->getKey())->count(),
        );

        if ($entries === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Entry type [%s] still has %d entr%s, and the database would delete them by cascade — '
            .'permanently, with nothing in the audit trail saying they existed (ADR-020). Delete '
            .'the entries first, which is audited.',
            $this->handle,
            $entries,
            $entries === 1 ? 'y' : 'ies',
        ));
    }

    public static function columnsRequiringModelSave(): array
    {
        return [
            'subject_field_id' => 'the field must belong to this type and name exactly one person.',
            'org_id' => 'moving a type across orgs leaves its fields backed by storage that did not move.',
        ];
    }

    /**
     * ⚠️ #[Unscoped] and still needs a builder: global types must be visible
     * to every org, so this model constrains by query — which left it with no
     * builder at all, and its guards reachable only through model events.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return ScopedBuilder<$this>
     */
    public function newEloquentBuilder($query): ScopedBuilder
    {
        return new ScopedBuilder($query, $this);
    }

    public function isReservedHandle(): bool
    {
        return in_array(strtolower($this->handle), self::RESERVED_HANDLES, true);
    }

    protected static function booted(): void
    {
        // A nomination pointing at another type's field would answer a
        // subject-access request with somebody else's data, which is worse
        // than answering it with nothing.
        // ⚠️ An org MOVE is checked before the nomination guard, and it has
        // to be, because that guard returns early when nothing is nominated —
        // so a type with no subject field could be moved to another org while
        // its fields stayed backed by the FORMER org's storage. That is the
        // exact state `Field::saving()` refuses to create, reached by moving
        // the other side of the relationship instead.
        static::saving(fn (self $type) => $type->guardOrgMove());

        static::saving(function (self $type): void {
            if ($type->subject_field_id === null) {
                return;
            }

            // ⚠️ NOT `$type->exists`. On create the type has no key yet, so
            // an exists-guarded check skipped the comparison entirely and
            // accepted any field, from any type, in any org.
            //
            // A field cannot belong to a type that does not exist, so on
            // create the nomination is refused outright unless the caller
            // supplied the key itself.
            $field = Field::query()->find($type->subject_field_id);

            if ($field === null || $field->entry_type_id !== $type->getKey()) {
                throw new RuntimeException(
                    "Field [{$type->subject_field_id}] cannot identify the subject of [{$type->handle}]: "
                    .'it does not belong to this entry type. A nomination pointing elsewhere would answer '
                    .'a subject-access request with another person\'s data (ADR-020). Create the type and '
                    .'its fields first, then nominate one.'
                );
            }

            $type->guardSubjectShape($field);
        });

        // Rejected at creation time, not escaped later. The collision is with
        // the URL contract, not with SQL: a type named "create" would make
        // /c/create/create ambiguous, and no amount of escaping fixes that
        // (ADR-012). Knowing the handle is reserved was never the hard part —
        // enforcing it was, and until now nothing did.
        static::saving(function (self $type): void {
            if ($type->isDirty('handle') && $type->isReservedHandle()) {
                throw new ReservedHandleException($type->handle);
            }
        });
    }

    /** @return BelongsTo<Org, $this> */
    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasMany<Field, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(Field::class);
    }
}
