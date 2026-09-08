<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kitsune\Core\Relations\GuardedRelationBuilder;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use RuntimeException;

/**
 * A row in `entry_relations`, with the field's own rules enforced on write.
 *
 * ⚠️ This exists because `cardinality` and `targetTypes` were METADATA that
 * nothing checked when a row was written. `RelationType` enforces both in
 * validation, and `attach()` goes nowhere near validation — so two ordinary
 * `attach()` calls gave a single-valued relation two targets, and a pivot
 * could point at an entry of a type the field forbids.
 *
 * That mattered most for a nominated subject field: `subjectValue()` returned
 * both ids and `whereSubjectIs()` returned the shared record for either
 * person, which is the disclosure the nomination guard was written to prevent
 * — reachable through the ordinary API rather than by any misuse (ADR-020).
 *
 * A pivot model rather than a database constraint because the rule is
 * conditional: `UNIQUE (source_entry_id, field_storage_id)` would be right
 * for cardinality one and wrong for every multi-value relation, and no engine
 * expresses "unique when a column in another table says so".
 *
 * Unscoped because it is reached only through `Entry::related()`, which
 * constrains `org_id` through `withPivotValue()` — the same reasoning as
 * `EntryRevision`, which is reached only through its Entry.
 *
 * @property int $source_entry_id
 * @property int $target_entry_id
 * @property int|null $field_storage_id
 * @property int $org_id
 */
#[Unscoped]
class EntryRelation extends Pivot
{
    public $incrementing = true;

    /**
     * ⚠️ Declared, because `AsPivot` only sets it on the hydration path.
     *
     * `entry_relations` has no `created_at`/`updated_at` — it is a pivot, and
     * the pivot's own timestamps are not what anyone asks it about. `AsPivot`
     * infers this flag in `fromAttributes()` and `fromRawAttributes()`, which is
     * how rows arrive through `attach()`; a direct `EntryRelation::create()`
     * goes through neither, so it inherited Model's default of true and wrote
     * two columns the table does not have. Latent until something created a row
     * without the relationship — a revision restore was the first.
     */
    public $timestamps = false;

    protected $table = 'entry_relations';

    protected static function booted(): void
    {
        static::creating(fn (self $relation) => $relation->guardCreate());

        // Cleared once the write lands, so the next one has to earn it again.
        static::saved(function (self $relation): void {
            $relation->guardsRan = false;
        });

        // ⚠️ The lock arms HERE for a relation, not from `Entry::saved`.
        //
        // `$entry->related()->attach(...)` writes the pivot AFTER the entry
        // was saved and does not save it again, so the entry-side check never
        // ran for the ordinary path — `is_locked` stayed false while relation
        // data existed, leaving the type and cardinality free to change and
        // orphan the links. The relation-locking test masked it by calling
        // save() again afterwards, which no real caller does.
        static::created(fn (self $relation) => $relation->armLock());

        // ⚠️ And after a MOVE. `updateExistingPivot()` can point a row at a
        // different `field_storage_id`, and only the `created` listener armed
        // the lock — so the destination held relation data while staying
        // editable, free to change type or cardinality and orphan the links
        // it had just acquired.
        static::updated(function (self $relation): void {
            if ($relation->wasChanged('field_storage_id')) {
                $relation->armLock();
            }
        });

        // ⚠️ And on UPDATE. `updateExistingPivot()` can move an existing row
        // onto a different field, so a second target from an unlimited
        // relation could be repointed at a nominated cardinality-one field —
        // recreating the two-subject disclosure through the ordinary
        // relationship API, with no row ever being created.
        static::updating(function (self $relation): void {
            $relation->guardsRan = true;

            // ⚠️ source_entry_id too, not only the field. Cardinality is
            // counted per (source, field), so moving a row from source B onto
            // source A — which `updateExistingPivot()` accepts — lands a
            // second target on a nominated cardinality-one field with no
            // concurrency involved at all.
            if ($relation->isDirty('field_storage_id')) {
                $relation->guardStorageOwnership();
            }

            if ($relation->isDirty(['field_storage_id', 'source_entry_id'])) {
                $relation->guardCardinality();
            }

            // Either endpoint moving needs rechecking, not just the target.
            if ($relation->isDirty(['source_entry_id', 'target_entry_id'])) {
                $relation->guardEndpointsVisible();
            }

            if ($relation->isDirty(['field_storage_id', 'target_entry_id'])) {
                $relation->guardTargetType();
            }
        });
    }

    /**
     * True only while THIS row's guards have run for the write in flight.
     *
     * ⚠️ How the guarded builder tells a model save from a bulk one. Both
     * reach the builder's methods, so refusing every bulk-shaped write would
     * refuse `attach()` as well.
     */
    public bool $guardsRan = false;

    /**
     * Every check a new relation row must pass.
     *
     * ⚠️ Reachable from the BUILDER, not only from `creating`.
     * `EntryRelation::query()->insert(...)` compiles straight to SQL and
     * dispatches nothing, so the pivot's entire safety story — ownership,
     * endpoint visibility, cardinality, target type — was absent there, and
     * the lock was never armed either. One ordinary Eloquent statement put a
     * second target on a nominated cardinality-one field, of a type that field
     * forbids: exactly the two-subject disclosure ADR-020's cardinality check
     * exists to prevent.
     *
     * This is the third model in this project to need a builder for the same
     * reason. `AuditLog` and `Entry` each got one; `FieldStorage` and this
     * both went without.
     */
    public function guardCreate(): void
    {
        $this->guardStorageOwnership();
        $this->guardEndpointsVisible();
        $this->guardCardinality();
        $this->guardTargetType();

        $this->guardsRan = true;
    }

    /**
     * ⚠️ Every write goes through the guarded builder.
     *
     * @param  QueryBuilder  $query
     */
    public function newEloquentBuilder($query): GuardedRelationBuilder
    {
        return new GuardedRelationBuilder($query);
    }

    /** Arming the lock is public so the builder can do it after a bulk delete. */
    public function armLockNow(): void
    {
        $this->armLock();
    }

    /**
     * The target has to be an entry the writer can actually see.
     *
     * ⚠️ `attach()` takes an ID and never loads the model, so a pivot could
     * point anywhere — and every consequence of that has had to be patched
     * downstream one boundary at a time. Scoping the type-change veto to the
     * target's org closed the cross-ORG case and left the cross-SITE one
     * open, because two sites in one org share an org stamp: site A could
     * attach site B's entry and then veto B's type changes with its own
     * `targetTypes`.
     *
     * Fixed where it belongs. A target that is not visible is not a target,
     * and refusing on write means the downstream readers stop needing a
     * boundary check each.
     *
     * SiteScope permits org-shared entries (`site_id` NULL), so those stay
     * attachable, which is the case the relation exists for.
     */
    private function guardEndpointsVisible(): void
    {
        // ⚠️ BOTH ends. Checking the target alone said nothing about the
        // source, and `referencedBy()` inverts them: the visible parent is the
        // TARGET there, and the attached id becomes `source_entry_id`. So a
        // site-A caller could attach a guessed site-B source to its own
        // visible target — a row hidden from B's own relation reads that still
        // counted in `guardCardinality()`, letting A fill B's single-valued
        // relation and block its legitimate attach.
        foreach (['source_entry_id', 'target_entry_id'] as $end) {
            $id = $this->getAttribute($end);

            if ($id === null || Entry::query()->whereKey($id)->exists()) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Entry %d is not visible here, so it cannot be the %s of a relation. `attach()` '
                .'takes an id and never loads the model, which is how a pivot came to point across '
                .'a site or organisation boundary at all (ADR-021).',
                (int) $id,
                $end === 'source_entry_id' ? 'source' : 'target',
            ));
        }
    }

    /**
     * The storage has to be the source org's, or global.
     *
     * ⚠️ Nothing checked, and `field_storage` is #[Unscoped], so an org could
     * attach one of its OWN entries using a RIVAL'S storage id — a
     * well-formed pivot that then armed that rival's lock, freezing their
     * schema from a row they cannot see. Guessing an integer was the whole
     * attack.
     *
     * Runs before the lock is armed and before the target-type guard, since
     * both read settings off the row this validates.
     *
     * Ownership by ORG rather than attachment to the source's type. The
     * attack is cross-org and this closes it exactly; requiring a `Field` row
     * would additionally forbid attaching storage that belongs to the org but
     * is not a field of that particular type — defensible, but a behavioural
     * change with no security gain, so it is stated rather than smuggled in.
     */
    private function guardStorageOwnership(): void
    {
        if ($this->field_storage_id === null) {
            return;
        }

        // ⚠️ The SOURCE ENTRY'S org, not this pivot's `org_id`.
        //
        // `withPivotValue()` supplies that stamp, but `attach()` attributes
        // override it — `Entry::redactField()` exists partly because such rows
        // are reachable. So comparing storage against the pivot's own org_id
        // compared two values the same caller controls: pass org B's
        // `field_storage_id` AND org B's `org_id` and the equality held, right
        // before the `created` hook armed org B's lock. The whole cross-org
        // freeze came back through the door the fix had just closed.
        $sourceOrg = Entry::withoutScopeBecause(
            'resolving the source entry\'s org to validate the pivot, not to expose the row',
            fn ($query) => $query->whereKey($this->source_entry_id)->value('org_id'),
        );

        if ($sourceOrg !== null && (int) $this->org_id !== (int) $sourceOrg) {
            throw new RuntimeException(sprintf(
                'Relation stamped org %d but entry %d belongs to org %d. The stamp is not evidence '
                .'of ownership — `attach()` attributes can set it — so it has to agree with the '
                .'entry it hangs off (ADR-021).',
                (int) $this->org_id,
                (int) $this->source_entry_id,
                (int) $sourceOrg,
            ));
        }

        $storageOrg = FieldStorage::query()->whereKey($this->field_storage_id)->value('org_id');

        if ($storageOrg === null || ($sourceOrg !== null && (int) $storageOrg === (int) $sourceOrg)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Field storage %d belongs to another organisation, so it cannot hold a relation here. '
            .'Storage is #[Unscoped], and this is the only thing standing between a guessed id '
            .'and freezing a rival\'s schema (ADR-021).',
            (int) $this->field_storage_id,
        ));
    }

    /**
     * Record that this storage now holds relation data (ADR-006).
     *
     * In bulk, so it does not re-enter FieldStorage's own guards — which have
     * nothing to check here, `is_locked` not being a shape attribute.
     */
    private function armLock(): void
    {
        if ($this->field_storage_id === null) {
            return;
        }

        FieldStorage::query()
            ->whereKey($this->field_storage_id)
            ->where('is_locked', false)
            ->update(['is_locked' => true]);
    }

    /**
     * ⚠️ Public so it can be RE-RUN under the destination lock.
     *
     * The `updating` callback runs this before `save()` reaches the builder, and
     * the builder acquires the destination's row lock after that callback has
     * returned. So two loaded pivot rows moved concurrently onto the same
     * cardinality-one `(source, field)` both counted zero, then serialised on the
     * lock, and both wrote — a count taken before a lock is a count of the past.
     *
     * `GuardedRelationBuilder` calls it again inside the locked transaction. The
     * early call stays: it is what refuses an ordinary move without opening one.
     */
    public function guardCardinality(): void
    {
        $storage = $this->storage();

        if ($storage === null || $storage->cardinality < 1) {
            // -1 is the explicit unlimited.
            return;
        }

        $existing = static::query()
            ->where('source_entry_id', $this->source_entry_id)
            ->where('field_storage_id', $this->field_storage_id)
            // The row being MOVED does not count against its destination.
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->count();

        if ($existing >= $storage->cardinality) {
            throw new RuntimeException(sprintf(
                'Field [%s] holds %d target(s) and already has that many on this entry. '
                .'Detach one before attaching another — silently exceeding it is how a field '
                .'nominated as a subject identifier comes to name two people (ADR-020).',
                $storage->handle,
                $storage->cardinality,
            ));
        }
    }

    /**
     * Whether any existing relation would forbid this target's new type.
     *
     * ⚠️ The guards above run when a PIVOT changes. Nothing ran when the
     * TARGET changed: save an allowed target with a different
     * `entry_type_id` and `Entry::saving()` restamps its `type_handle`, while
     * every relation pointing at it survives with a now-forbidden type — and
     * both `subjectValue()` and the relational `whereSubjectIs()` branch keep
     * treating it as the subject, because neither rechecks `targetTypes`
     * (ADR-020).
     *
     * Refused at the type change rather than filtered at read time: a stored
     * relation that no configuration permits is a state to prevent, not one
     * to keep working around.
     */
    public static function forbidsTypeChange(
        int $targetId,
        string $newHandle,
        int $targetOrgId,
        bool $visibleOnly = true,
    ): ?string {
        // ⚠️ Scoped to the TARGET'S OWN ORG, and this is a denial-of-service
        // fix rather than a tidy-up. `attach()` does not validate target
        // visibility, so org A can create a pivot pointing at org B's entry.
        // An unscoped scan then let A's storage configuration VETO B's
        // updates — freezing a rival's record indefinitely, from a row B
        // cannot see and did not create.
        // ⚠️ Visibility NOW, not the org stamp. A pivot valid when written
        // can become cross-site later — move an org-shared or site-A target to
        // site B and site A's now-invisible relation could still veto B's type
        // change. Requiring the SOURCE to be visible from here means a
        // relation only constrains an entry while both ends can still see each
        // other.
        // ⚠️ `visibleOnly` is FALSE when an endpoint re-enters a site.
        //
        // Filtering to visible sources is right for a type change — otherwise
        // one site could freeze another's records — and it leaves the
        // move-away, change, move-back sequence, where the relation is
        // invisible exactly while the change happens. On the way back in, every
        // relation in the org counts, because they are all about to be visible
        // again.
        $relations = static::query()
            ->where('target_entry_id', $targetId)
            ->where('org_id', $targetOrgId)
            ->when($visibleOnly, fn ($query) => $query->whereIn(
                'source_entry_id',
                Entry::query()->select('id')->toBase(),
            ))
            ->get();

        foreach ($relations as $relation) {
            $storage = $relation->storage();

            if ($storage === null) {
                continue;
            }

            /** @var array<int, string> $targets */
            $targets = (array) (($storage->settings['targetTypes'] ?? []) ?: []);

            if ($targets !== [] && ! in_array($newHandle, $targets, true)) {
                return $storage->handle;
            }
        }

        return null;
    }

    private function guardTargetType(): void
    {
        $storage = $this->storage();

        if ($storage === null) {
            return;
        }

        /** @var array<int, string> $targets */
        $targets = (array) (($storage->settings['targetTypes'] ?? []) ?: []);

        if ($targets === []) {
            return;
        }

        // Unscoped on purpose: the SCOPE of the target is checked elsewhere,
        // and reading its type here must not depend on the caller's context.
        $handle = Entry::withoutScopeBecause(
            'reading the target type to enforce a constraint, not to expose the row',
            fn ($query) => $query->whereKey($this->target_entry_id)->value('type_handle'),
        );

        if ($handle !== null && ! in_array($handle, $targets, true)) {
            throw new RuntimeException(sprintf(
                'Field [%s] accepts only [%s], and entry %d is a [%s]. `attach()` bypasses '
                .'validation, so the constraint has to hold here too.',
                $storage->handle,
                implode(', ', $targets),
                (int) $this->target_entry_id,
                $handle,
            ));
        }
    }

    private function storage(): ?FieldStorage
    {
        return $this->field_storage_id === null
            ? null
            : FieldStorage::query()->find($this->field_storage_id);
    }
}
