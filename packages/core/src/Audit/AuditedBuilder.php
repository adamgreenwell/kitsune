<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Audit;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kitsune\Core\Media\MediaDisposal;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Schema\RevisionWrites;
use Kitsune\Core\Tenancy\Concerns\ResolvesWrittenColumns;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopedBuilder;
use RuntimeException;

/**
 * The single place every write is audited from.
 *
 * ⚠️ Model events are NOT enough. `Model::query()->update()`, `->delete()`
 * and `->forceDelete()` write straight through the query builder and dispatch
 * nothing per row — so a model audited by `updated` / `deleted` listeners is
 * audited for the row-at-a-time path and not for the one-liner that rewrites
 * the whole table. Entries could change or disappear leaving no trace, while
 * the claim was that the API and the console are covered by the same code
 * path as the admin (ADR-020).
 *
 * ⚠️ And model events are not merely insufficient, they are wrong ALONGSIDE
 * this: `$model->save()` and `$model->delete()` both route through here, so
 * listening in both places recorded every single-row write twice. The builder
 * can tell the cases apart on its own anyway — a soft delete IS an update
 * that sets `deleted_at`, and a restore IS one that clears it — so the model
 * keeps only `created`, which an insert never brings through these methods.
 *
 * ⚠️ WHERE THIS STOPS. `Entry::query()->toBase()` hands back the underlying
 * query builder, and a write through it is not audited. That is not a hole
 * this class can close: `toBase()` is the same door as `DB::table('entries')`
 * and `DB::statement(...)`, and no model-layer guard can stand in front of
 * raw SQL. Overriding it is not an option either — Laravel's own `update()`,
 * `count()` and `pluck()` all go through it, this class included.
 *
 * So the guarantee is about the ELOQUENT layer: no Eloquent path creates,
 * changes or removes an entry without an audit row or a refusal. Reaching
 * past Eloquent is explicit, visible in review as `toBase()` or `DB::`, and
 * would need database triggers to prevent — which is a decision with its own
 * costs and would want its own ADR. ADR-020 says exactly this rather than
 * claiming more.
 *
 * Bound to Entry rather than made generic, following AppendOnlyBuilder. One
 * model needs this today, and a concrete binding is what lets the analyser
 * see that `deleted_at` exists here at all — a `@template` bounded by Model
 * cannot, so it would have to be suppressed. Generalise when there is a
 * second case to check the design against.
 *
 * ⚠️ Extends ScopedBuilder, not Builder. Entry needs BOTH sets of guards, and
 * a model has one builder — so when this was a sibling of ScopedBuilder rather
 * than a subclass, whichever one Entry returned silently disabled the other's
 * checks. Auditing is what this adds; the tenancy write guards, the per-row
 * column refusals and the cascade refusal are inherited.
 *
 * @extends ScopedBuilder<Entry>
 */
class AuditedBuilder extends ScopedBuilder
{
    // Its own private copy of the refusals, as `GuardedRoleBuilder` takes one: `ScopedBuilder` keeps its copy private.
    use ResolvesWrittenColumns {
        refuseAmbiguousColumns as private;
        refuseMisnamedGuardedColumn as private;
    }

    /**
     * The columns `Entry`'s own checks read by that name alone, so the only name a write may give them.
     *
     * ⚠️ `entry_type_id` DECIDES WHICH TYPE EVERY CHECK ASKS ABOUT, AND EACH ASKED BY NAME. The creation guard read
     * `$values['entry_type_id']`, `Entry::refuseUnpermittedPublication()` reads the instance's `entry_type_id`, and
     * the `saving` restamp, the relation veto and `convertFieldValuesForWrite()` look for `entry_type_id` by name.
     * SQLite, MySQL and MariaDB write `ENTRY_TYPE_ID` into the same column, so under that name each of them saw no type
     * at all. Measured: somebody holding `create` and not `publish` created an article already published; somebody who
     * may publish articles and not products retyped a draft article to a product and published it in one save, with
     * `type_handle` left naming `article`; and a bulk retype under another name — a qualified one included — drifted
     * `type_handle` the same way. `type_handle`, `values` and `site_id` are `columnsRequiringModelSave()`, which
     * `ScopedBuilder` already holds to their own names; this is the one column those checks read that is not.
     */
    private const READ_BY_NAME = ['entry_type_id'];

    private const NO_BULK_CREATE =
        'Entries cannot be written in bulk, because these paths return a row count rather than '
        .'the keys they wrote — there would be nothing to record as the target, and an entry '
        .'would appear with no audit trail (ADR-020). Use create(), which is audited.';

    /**
     * ⚠️ Creation is audited HERE, not from the `created` model event.
     *
     * `Entry::createQuietly()` and any creation inside
     * `Model::withoutEvents()` suppress that listener while still inserting
     * the row — through this very method, which `Model::performInsert()`
     * uses for an incrementing key. So the entry persisted with no audit row,
     * and the quiet variants are ordinary Eloquent that application code
     * reaches for without thinking about the trail.
     *
     * This is the one insert path that CAN be audited: it returns the id it
     * wrote, so there is a target to name. Every other bulk insert path is
     * refused below for exactly the reason this one works.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->refuseMisnamedEntryColumns($values);
        $this->refuseNoncanonicalEntryType($values);

        $model = $this->getModel();

        // ⚠️ Conversion happens HERE, not in a `saving` listener, because this is the
        // path a quiet save cannot skip. See `Entry::convertFieldValuesForWrite()`.
        $values = $model->convertFieldValuesForWrite($values);

        $this->guardScopeKeys($values);
        $this->refuseNoncanonicalStatus($values);
        $this->refuseUnpermittedCreationAsPublished($values);

        return DB::transaction(function () use ($values, $sequence, $model) {
            $id = parent::insertGetId($values, $sequence);

            $target = $model->newInstance([], true);
            $target->forceFill([$model->getKeyName() => $id]);

            app(Auditor::class)->recordOrFail(Str::snake(class_basename($model)).'.created', $target);

            $this->recordInitialRevision($id);

            return $id;
        });
    }

    /**
     * File the initial revision when no `created` listener will.
     *
     * ⚠️ The creation half of the quiet-save gap. `createQuietly()` and anything
     * inside `withoutEvents()` suppress the `created` listener, so an entry
     * arrived with NO initial revision at all — while a quiet UPDATE is now
     * treated as a version, which made the two halves disagree about what a
     * quiet write means.
     *
     * Same discriminator as the update path, and for the same reason:
     * `withoutEvents()` swaps in a `NullDispatcher` rather than unsetting one, so
     * a real dispatcher means the listener will record this and a null one means
     * nobody will. Recording in both would file two revisions for one insert.
     *
     * The row is re-read rather than recorded from `$this->model`: at this point
     * `Model::performInsert()` has not yet set the key on the instance, and
     * setting it here to suit the recorder would be reaching into the caller's
     * object to make our own bookkeeping work.
     */
    private function recordInitialRevision(mixed $id): void
    {
        // ⚠️ EVERY creation, not only the quiet kind — the `NullDispatcher` test
        // that used to stand here deferred an ordinary create to the `created`
        // listener, and that listener fires after this transaction has committed.
        // A concurrent updater can commit and record version B in that window,
        // leaving the initial version A newest while the live entry is B. Same
        // race as the update path, and the same answer: record under the lock
        // that made the write atomic.
        if (RevisionWrites::suspended()) {
            return;
        }

        $entry = $this->getModel()->newQueryWithoutScopes()->find($id);

        // ⚠️ The reload is a DIFFERENT object from the one that converted the values,
        // and the pre-sanitization originals live on that one. Reloading is right —
        // the snapshot should be the persisted state — but it means the originals have
        // to be handed across explicitly or the revision records none.
        $this->carryRetainedOriginals($entry);

        // An empty before-state, because the row did not exist a moment ago.
        $entry?->recordRevisionForEventlessWrite([], $this->rawVersionedRows([$id])[$id] ?? []);
    }

    /**
     * ⚠️ The row being written must belong to the org being audited.
     *
     * `createQuietly()` and `withoutEvents()` suppress EnforcesScope's
     * `creating` listener, which is what normally STAMPS these columns — so a
     * caller can supply another org's `org_id` and `site_id` and have them
     * inserted verbatim. The audit row is then written under the CURRENT
     * context, so the other org gains an entry with no audit record while
     * this one gains a trail pointing at a row it does not own. Both halves
     * are wrong, and the trail is wrong in the direction that reads as
     * evidence.
     *
     * The same applies to an UPDATE. `EnforcesScope` stamps these columns on
     * create only, so `Entry::query()->update(['org_id' => $rival])` moved an
     * entry out of the current scope while the audit row was written under
     * the OLD context — leaving the destination org holding an entry whose
     * only trail belongs to somebody else. The existing scope restricts which
     * rows are SELECTED and says nothing about the values written.
     *
     * Refused rather than restamped: a caller who passed an explicit org_id
     * meant something by it, and silently rewriting it would be its own kind
     * of lie.
     *
     * @param  array<string, mixed>  $values
     */

    /**
     * ⚠️ Creation has a bulk path too, and it is the same hole in reverse.
     *
     * `Entry::query()->insert()` writes rows that dispatch no `created`
     * event, so an entry could APPEAR with no audit row — as untraceable as
     * the bulk update that could change one. Auditing it is not possible
     * here: these methods return a row count, not the keys they wrote, so
     * there is nothing to name as the target.
     *
     * Refused rather than left silently unaudited, and the message names the
     * way through. That the guarantee is "there is no unaudited way to create
     * an entry" is worth more at this stage than a convenient bulk import,
     * which can come back with an ADR and an audited path of its own.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /** @param  array<string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @param  array<int, string>  $columns
     */
    public function insertUsing(array $columns, $query): int
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * @param  \Closure|\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder<*>|string  $query
     * @param  array<int, string>  $columns
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * ⚠️ The framework returns a `Collection` here, which this docblock said was an `array` until
     * `ScopedBuilder` declared the type and the mismatch surfaced. Both say `Collection` now.
     *
     * @param  array<string, mixed>  $values
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * ⚠️ PostgreSQL-only, and refused rather than audited.
     *
     * It updates through a FROM clause, so the rows it touches are defined by
     * a join this builder cannot reproduce — and auditing here works by
     * running the write against the keys it captured, which would silently
     * change what the statement did. Refusing is the honest option, and it is
     * the same rule the insert paths follow: what cannot be audited is not
     * allowed (ADR-020).
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(
            'updateFrom() updates through a join this builder cannot reproduce, so the write '
            .'could not be audited against the rows it actually touched (ADR-020). Update '
            .'through a predicate on entries instead.'
        );
    }

    // ⚠️ `touch()` is routed through `update()` — Eloquent writes it through `toBase()`, and every matching entry had
    // its `updated_at` moved with no audit row. The override that did it here is `TouchesThroughUpdate` now, on
    // `ScopedBuilder`, because every guarded builder had the same hole and only this one had closed it.

    /**
     * ⚠️ Forwarded WHOLE to the query builder, so neither these overrides nor
     * the `created` event sees it. Depending on whether the predicate matches
     * it either creates or modifies an entry, and did so untraced either way.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new RuntimeException(self::NO_BULK_CREATE);
    }

    /**
     * ⚠️ Not an insert, but the same gap: it removes every row at once and
     * dispatches nothing, so the whole table could vanish untraced.
     */
    public function truncate(): void
    {
        throw new RuntimeException(
            'Truncating entries would remove every row with no audit trail, and there would be '
            .'nothing left to say what had been there (ADR-020). Delete through the model.'
        );
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        $this->refuseMisnamedEntryColumns($values);
        $this->refuseNoncanonicalEntryType($values);

        // Same conversion as the insert path, at the same place: the write.
        $values = $this->getModel()->convertFieldValuesForWrite($values);

        $this->guardScopeKeys($values);
        $this->refuseNoncanonicalStatus($values);

        /*
         * ⚠️ AN INSTANCE MAY NOT WRITE OVER A ROW THAT MOVED UNDER IT — see
         * `Entry::refuseIfTheRowMovedUnderneath()`. The authorization that permitted this write was decided
         * before it began, in another transaction; a row retyped or moved into another site since then is not
         * the row that was authorised. Asked inside `auditing()`'s transaction, so the locked read holds until
         * the write commits.
         *
         * ⚠️ `exists` AND A KEY, NOT `isPerformingModelSave()`, and the difference is a soft delete. Eloquent's
         * `runSoftDelete()` builds its own query and calls `update()` directly — it is not inside
         * `performUpdate()` — so keying on the save identity skipped the one case that DESTROYS something.
         * Measured: the soft delete went through while the update and the force-delete were refused.
         *
         * A genuine bulk update arrives with a prototype that does not exist, so it is excluded by `exists`
         * and stays narrowed by the scope, which is what a bulk write has instead of a row to compare.
         */
        return $this->auditing($this->actionFor($values), function () use ($values) {
            $this->refuseIfTheRowMoved('update');
            $this->refuseRetypeAcrossMediaBoundary($values);
            $this->refuseUnpermittedPublication($values);

            return parent::update($values);
        }, $values);
    }

    // delete() is deliberately NOT overridden. Entry soft-deletes, so both
    // SoftDeletingScope's onDelete callback and runSoftDelete() route a
    // deletion back through update() — where actionFor() reads `deleted_at`
    // and names it. Auditing it here as well would record it twice.

    public function forceDelete()
    {
        /*
         * ⚠️ READ BEFORE THE DELETE, REMOVED AFTER IT — ADR-041's byte disposal, asked of the builder rather
         * than of a model event.
         *
         * A `deleting` hook on `Entry` would miss `Entry::query()->forceDelete()`, which dispatches nothing,
         * and a hook on `MediaFile` never fires at all: `media_files.entry_id` cascades, so the row goes
         * inside the database where no PHP runs. This is the one place both the instance path and the bulk
         * path arrive, because `SoftDeletes::forceDelete()` routes an instance through the builder too.
         *
         * Rows first, then bytes — the mirror of how `MediaLibrary` writes them, for the same reason: if the
         * files went first and the delete then failed, a surviving entry would point at nothing.
         */
        $files = MediaDisposal::filesFor(
            (clone $this)->select($this->getModel()->getTable().'.id')->pluck('id')->map(
                static fn (mixed $id): int => (int) $id,
            )->all(),
        );

        $result = $this->auditing('force_deleted', function () {
            // ⚠️ The destructive half, and the reason that guard exists at all: an update is a field somebody
            // may not have been allowed to touch, and this is a row that is gone.
            $this->refuseIfTheRowMoved('force-delete');

            return parent::forceDelete();
        });

        /* Reports rather than throws: a disk that refuses must not keep a force-delete from completing. */
        MediaDisposal::remove($files);

        return $result;
    }

    /**
     * Refuse a `status` the vocabulary does not contain.
     *
     * ⚠️ THE DATABASE'S EQUALITY IS NOT PHP'S, and that is what makes this a guard rather than validation.
     * MySQL and MariaDB compare case- AND accent-insensitively by default, so `scopePublished()` matches a
     * stored `publíshed` while any comparison written here treats it as a different string — the publication
     * guard stands aside and the row is public. No PHP predicate can enumerate what a given server considers
     * equal, because it depends on the column's collation; a closed set of storable values removes the
     * question instead of answering it.
     *
     * ⚠️ AT THE BUILDER, so it covers the bulk write this project supports and the quiet paths a listener
     * would miss. `Entry::STATUSES` is the vocabulary, shared with the form's option list.
     *
     * ⚠️ AND AT EVERY DOOR THAT WRITES A VALUE, which review found it was not: `update()` asked, while
     * `insertGetId()` and the four arithmetic methods went straight past. `Entry::create(['status' =>
     * 'publíshed'])` stored a value this guard exists to refuse, and so did `$entry->increment('id', 0,
     * ['status' => 'publíshed'])` — `$extra` is a map of ordinary assignments. The arithmetic doors pass the
     * incremented column too, so `increment('status')` is refused for storing a number that is not a status.
     * The third guard in this file to reach one door first; see `refuseIfTheRowMoved()` for the other two.
     *
     * Asked before any transaction opens, beside `guardScopeKeys()`: it reads the values being written, not a
     * row, so there is nothing for a lock to hold.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseNoncanonicalStatus(array $values): void
    {
        /*
         * ⚠️ EVERY NAME THAT REACHES THE COLUMN, which was `status` and `entries.status` exactly. SQLite, MySQL and
         * MariaDB match column names without regard to case, so `update(['STATUS' => 'publíshed'])` stored what
         * this refuses — measured. Each spelling is judged, since which of two the database keeps depends on the
         * engine; `ScopedBuilder` refuses the write that names the column twice as well.
         */
        foreach ($this->writtenStatuses($values) as $status) {
            if (is_string($status) && in_array($status, Entry::STATUSES, true)) {
                continue;
            }

            /*
             * ⚠️ A RAW EXPRESSION IS SQL, AND THIS GUARD READS VALUES. A joined update assigning `status`
             * from the joined table — `DB::raw('CASE WHEN … END')` — is a supported write this suite asserts
             * on MySQL, and there is nothing here to inspect: the value does not exist until the database
             * evaluates it. Refusing every expression would break that capability to close a hole only a
             * caller writing raw SQL can reach, which is the same trade `updateFrom()` documents from the
             * other side.
             *
             * The limitation is stated rather than hidden: a raw expression may write any string the column
             * accepts, and `scopePublished()` will read it the way the collation does.
             */
            if ($status instanceof Expression) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Refusing to write [%s] as an entry status: the column holds one of [%s] and nothing else. '
                .'A value outside that set is not merely unknown — MySQL and MariaDB compare it '
                .'case-insensitively and accent-insensitively, so the database can treat it as published '
                .'while every guard here reads it as something different (ADR-033).',
                is_string($status) ? $status : get_debug_type($status),
                implode(', ', Entry::STATUSES),
            ));
        }
    }

    /**
     * `Entry::refuseNoncanonicalTypeKey()`, for every spelling of the column a write names — asked before
     * conversion, which looks the type up by exactly this value, and at every door that writes one.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseNoncanonicalEntryType(array $values): void
    {
        foreach ($values as $written => $value) {
            if ($this->bareColumn((string) $written) === 'entry_type_id') {
                Entry::refuseNoncanonicalTypeKey($value);
            }
        }
    }

    /**
     * ⚠️ NO AMOUNT ADDED TO A TYPE ID NAMES A TYPE ANYBODY CHOSE — `refuseScopeArithmetic()`'s reasoning, for the
     * column that decides what an entry is. Outside `withoutScopeBecause()` the per-row column list refuses it as
     * well; inside, that list stands down, and the media boundary cannot be asked about a destination that differs
     * row by row.
     *
     * @param  array<string, mixed>  $columns  the columns moved by an amount — not the `$extra` assignments
     */
    private function refuseTypeArithmetic(array $columns): void
    {
        foreach (array_keys($columns) as $column) {
            if ($this->bareColumn((string) $column) === 'entry_type_id') {
                throw new RuntimeException(
                    'Refusing to increment or decrement [entry_type_id]: no amount added to a type\'s id names a '
                    .'type anybody chose, and the entries it lands on may cross the media boundary (ADR-042). '
                    .'Retype an entry by saving it.'
                );
            }
        }
    }

    /**
     * Refuse a write that would move any entry it touches across the media boundary — ADR-042 decision 1.
     *
     * ⚠️ THE ENTRY'S HALF OF THE LOCK ON `is_media`. The type cannot change the flag, but an entry can change its
     * type, and a retype reaches both states the lock exists to prevent: an uploaded file moved onto an `article`
     * with its `media_files` row still attached, or an ordinary entry moved onto `image` with no bytes behind it.
     * A retype WITHIN the boundary — an image moved to the org's own image type — is untouched.
     *
     * ⚠️ HERE, ON THE LOCKED ROWS, AND ASKED OF EVERY ROW THE STATEMENT WILL WRITE. It was first a method on
     * `Entry` that judged the instance being saved, and review found the gap that shape leaves: a bulk write
     * arrives on a prototype that does not exist, and the per-row column list that refuses it outside
     * `withoutScopeBecause()` stands down inside — so `withoutScopeBecause(fn ($q) => $q->where('entry_type_id',
     * $image)->update(['entry_type_id' => $article]))` moved every uploaded file onto `article`. The escape hatch
     * decides which path may write a column, not what the column may hold (ADR-022's amendment says the same of
     * `settings`). `auditing()` has already locked and narrowed this builder to the keys it will write, so this
     * asks about exactly those rows, and the flag is locked on every type, so neither side can move before the
     * write commits.
     *
     * ⚠️ ONE QUERY ON A WRITE THAT NAMES `entry_type_id`, AND NONE ON ANY OTHER. A retype is rare; the query is a
     * key-bounded read of the rows already locked.
     *
     * @param  array<string, mixed>  $values  the assignments the statement makes
     */
    private function refuseRetypeAcrossMediaBoundary(array $values): void
    {
        $destination = null;

        foreach ($values as $written => $value) {
            if ($this->bareColumn((string) $written) === 'entry_type_id') {
                $destination = $value;
            }
        }

        if ($destination === null) {
            return;
        }

        $model = $this->getModel();
        $toMedia = (bool) EntryType::query()->whereKey($destination)->value('is_media');

        $crossing = $this->clone()
            ->whereIn($model->qualifyColumn('entry_type_id'), EntryType::query()->where('is_media', ! $toMedia)->select('id'))
            ->toBase()
            ->first([$model->getQualifiedKeyName(), $model->qualifyColumn('entry_type_id')]);

        if ($crossing === null) {
            return;
        }

        $from = (string) EntryType::query()->whereKey($crossing->entry_type_id)->value('handle');
        $to = (string) EntryType::query()->whereKey($destination)->value('handle');

        throw new RuntimeException(sprintf(
            'Entry %d cannot become a [%s]: [%s] is %s media type and [%s] is %s. %s (ADR-042).',
            $crossing->{$model->getKeyName()},
            $to,
            $from,
            $toMedia ? 'not a' : 'a',
            $to,
            $toMedia ? 'one' : 'not',
            $toMedia
                ? 'It would become a media entry with no file behind it'
                : 'Its stored file would stay attached to an entry the admin shows no file for',
        ));
    }

    /**
     * Refuse an INSTANCE write that would move an entry into the published state without the permission.
     *
     * ⚠️ INSTANCE WRITES ONLY, WHICH IS WHAT MAKES THIS COMPATIBLE WITH THE BULK PUBLISH THIS PROJECT
     * SUPPORTS. `Entry::query()->update(['status' => 'published'])` is audited and versioned on purpose and
     * carries no acting identity; it arrives on a prototype that does not exist, so the same `exists` and key
     * test that scopes the stale-row guard keeps it out of this one. See
     * `Entry::refuseUnpermittedPublication()` for the reversal this represents and why review was right.
     *
     * ⚠️ A BULK UPDATE THAT NAMES `status` QUALIFIED gets the same treatment as an unqualified one, because a
     * joined update qualifies its columns — the same reason `actionFor()` checks both spellings.
     *
     * ⚠️ AND THE ARITHMETIC DOORS ASK IT TOO, which review found missing one round after the same omission
     * was fixed for the stale-row guard. Laravel's `$extra` map is a set of ordinary assignments, so
     * `$entry->increment('id', 0, ['status' => 'published'])` is a publication wearing another method's name.
     * Four doors, one guard — again.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseUnpermittedPublication(array $values): void
    {
        $model = $this->getModel();

        /*
         * ⚠️ CASE-INSENSITIVELY — see `Entry::isPublished()`. MySQL and MariaDB's default collations match a
         * stored `PUBLISHED` against `scopePublished()`'s `status = 'published'`, so a strict comparison here
         * let that spelling through a guard whose whole job is to catch it.
         *
         * ⚠️ AND THE COLUMN'S NAME THE SAME WAY, for the same reason: `$entry->update(['STATUS' => 'published'])`
         * found no `status` here and published the article for somebody who may not — measured.
         */
        $publishing = array_filter(
            $this->writtenStatuses($values),
            static fn (mixed $status): bool => is_string($status) && mb_strtolower($status) === 'published',
        );

        if ($publishing === [] || ! $model->exists || $model->getKeyForAuthorization() === null) {
            return;
        }

        $model->refuseUnpermittedPublication();
    }

    /**
     * Refuse a creation that would publish an entry without the permission — `Entry::refuseUnpermittedCreationAsPublished()`.
     *
     * ⚠️ A STRICT COMPARISON IS CORRECT HERE, where it was not in `refuseUnpermittedPublication()`, because
     * `refuseNoncanonicalStatus()` runs first: by the time this reads the value, `published` is the only
     * spelling of the published state that can still be on its way to the database.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseUnpermittedCreationAsPublished(array $values): void
    {
        $model = $this->getModel();

        if (! in_array('published', $this->writtenStatuses($values), true)) {
            return;
        }

        // By this name alone, which is safe only because `refuseMisnamedEntryColumns()` has refused every other one.
        $model->refuseUnpermittedCreationAsPublished($values['entry_type_id'] ?? null);
    }

    /**
     * Refuse an INSTANCE write whose row has moved or been retyped since it was loaded.
     *
     * ⚠️ ONE METHOD BECAUSE IT WAS TWO AND SHOULD HAVE BEEN SIX. The check went into `update()` and
     * `forceDelete()`, and review found the four arithmetic methods going straight past it: Eloquent sends
     * `$entry->increment()` to `setKeysForSaveQuery($this->newQueryWithoutScopes())->increment()`, which is
     * this builder without the scope and without that guard — an instance write by the original key, which is
     * exactly what the guard exists for.
     *
     * ⚠️ AND IT ASKS FOR THE KEY THE WRITE WILL USE, not the attribute. Review found `getKey()` deciding
     * whether to check: setting a loaded entry's `id` to null in memory made the condition false while
     * Eloquent still wrote by `$this->original['id']`, so the guard was skipped on precisely the instance
     * that had been tampered with. `getKeyForAuthorization()` is the original key — the same one
     * `EntryPolicy` asks about, and the same lesson `Role` learned about an edited primary key.
     *
     * A genuine BULK write arrives on a prototype that does not exist, so it is excluded here and stays
     * narrowed by the scope, which is what it has instead of a row to compare.
     */
    private function refuseIfTheRowMoved(string $operation): void
    {
        $model = $this->getModel();

        if (! $model->exists || $model->getKeyForAuthorization() === null) {
            return;
        }

        $model->refuseIfTheRowMovedUnderneath($operation);
    }

    /**
     * ⚠️ Increments are UPDATES that skip update(). Both forward to the query
     * builder, so a counter could be moved on any number of entries with no
     * trail. Audited rather than refused — unlike the insert paths, the rows
     * already exist and have keys to name.
     *
     * ⚠️ And the written columns are passed to `auditing()`, which they were not.
     * Laravel's arithmetic methods take an `$extra` map of ordinary assignments,
     * so `increment('ordering', 1, ['status' => 'published'])` moves a VERSIONED
     * column — it was audited and filed no revision, leaving the newest revision
     * stale and a later restore silently reverting the publication. The
     * incremented column is included too: it is a written column like any other,
     * and a versioned counter would need a version.
     *
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseMisnamedEntryColumns([(string) $column => $amount, ...$extra]);
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);
        $this->refusePerRowExtras($extra);
        $this->refuseNoncanonicalStatus([(string) $column => $amount, ...$extra]);
        $this->refuseTypeArithmetic([(string) $column => $amount]);
        $this->refuseNoncanonicalEntryType($extra);

        return $this->auditing(
            'updated',
            function () use ($column, $amount, $extra) {
                $this->refuseIfTheRowMoved('increment');
                $this->refuseRetypeAcrossMediaBoundary($extra);
                $this->refuseUnpermittedPublication($extra);

                return parent::increment($column, $amount, $extra);
            },
            [(string) $column => $amount, ...$extra],
        );
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseMisnamedEntryColumns([(string) $column => $amount, ...$extra]);
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);
        $this->refusePerRowExtras($extra);
        $this->refuseNoncanonicalStatus([(string) $column => $amount, ...$extra]);
        $this->refuseTypeArithmetic([(string) $column => $amount]);
        $this->refuseNoncanonicalEntryType($extra);

        return $this->auditing(
            'updated',
            function () use ($column, $amount, $extra) {
                $this->refuseIfTheRowMoved('decrement');
                $this->refuseRetypeAcrossMediaBoundary($extra);
                $this->refuseUnpermittedPublication($extra);

                return parent::decrement($column, $amount, $extra);
            },
            [(string) $column => $amount, ...$extra],
        );
    }

    /**
     * ⚠️ The PLURAL forms too. `incrementEach()` and `decrementEach()` are
     * separate methods on the query builder, so overriding the singular ones
     * left a multi-column increment forwarding straight past every guard
     * here — the same omission, one API call along.
     *
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseMisnamedEntryColumns([...$columns, ...$extra]);
        $this->refuseScopeArithmetic([...$columns, ...$extra]);
        $this->refusePerRowExtras($extra);
        $this->refuseNoncanonicalStatus([...$columns, ...$extra]);
        $this->refuseTypeArithmetic($columns);
        $this->refuseNoncanonicalEntryType($extra);

        return $this->auditing(
            'updated',
            function () use ($columns, $extra) {
                $this->refuseIfTheRowMoved('increment');
                $this->refuseRetypeAcrossMediaBoundary($extra);
                $this->refuseUnpermittedPublication([...$columns, ...$extra]);

                return parent::incrementEach($columns, $extra);
            },
            [...$columns, ...$extra],
        );
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseMisnamedEntryColumns([...$columns, ...$extra]);
        $this->refuseScopeArithmetic([...$columns, ...$extra]);
        $this->refusePerRowExtras($extra);
        $this->refuseNoncanonicalStatus([...$columns, ...$extra]);
        $this->refuseTypeArithmetic($columns);
        $this->refuseNoncanonicalEntryType($extra);

        return $this->auditing(
            'updated',
            function () use ($columns, $extra) {
                $this->refuseIfTheRowMoved('decrement');
                $this->refuseRetypeAcrossMediaBoundary($extra);
                $this->refuseUnpermittedPublication([...$columns, ...$extra]);

                return parent::decrementEach($columns, $extra);
            },
            [...$columns, ...$extra],
        );
    }

    /**
     * Name the action from the values being written, not from the caller.
     *
     * Eloquent expresses a soft delete and a restore as updates, so reading
     * `deleted_at` is what separates "edited", "deleted" and "restored" —
     * and it separates them identically for one row and for ten thousand.
     *
     * @param  array<string, mixed>  $values
     */
    private function actionFor(array $values): string
    {
        $model = $this->getModel();
        $column = $model->getDeletedAtColumn();

        // Bulk updates qualify their columns; instance saves do not. ⚠️ And the database matches the name
        // without regard to case, so `update(['DELETED_AT' => now()])` soft-deleted entries that this recorded
        // as `entry.updated` — measured — until it read the name through `bareColumn()`.
        foreach ($values as $written => $value) {
            if ($this->bareColumn((string) $written) === strtolower($column)) {
                return $value === null ? 'restored' : 'deleted';
            }
        }

        return 'updated';
    }

    /**
     * Every value this write assigns to `status`, under whichever names reach it.
     *
     * @param  array<array-key, mixed>  $values
     * @return list<mixed>
     */
    private function writtenStatuses(array $values): array
    {
        $statuses = [];

        foreach ($values as $written => $value) {
            if ($this->bareColumn((string) $written) === 'status') {
                $statuses[] = $value;
            }
        }

        return $statuses;
    }

    /**
     * Capture the affected keys, then write against THOSE KEYS, in one
     * transaction.
     *
     * ⚠️ Auditing the predicate and writing the predicate are two different
     * statements over a set that can move between them. On PostgreSQL a row
     * inserted after the `pluck()` and before the write is modified by the
     * write and absent from the trail; one that stops matching in the same
     * interval gets an audit record for a change it never received. The trail
     * would be quietly wrong in both directions under ordinary load.
     *
     * So the write does not re-run the predicate: this query is CONSTRAINED
     * to exactly the keys that were audited, and then performed.
     *
     * ⚠️ Constrained, not replaced. An earlier version built a fresh
     * key-only builder, which silently dropped any join — so a joined update
     * assigning from the joined table compiled against an alias that was no
     * longer there and failed on an unknown column. Adding a predicate keeps
     * the statement the caller wrote.
     *
     * The keys are read BEFORE the write for the original reason too: after
     * it a deleted row has no id to look up.
     *
     * @param  callable(): mixed  $write
     * @param  array<string, mixed>  $written
     */
    private function auditing(string $action, callable $write, array $written = []): mixed
    {
        $model = $this->getModel();

        return DB::transaction(function () use ($action, $write, $model, $written): mixed {
            // ⚠️ DEDUPLICATED. A bulk write over a join — say `entries`
            // joined to `entry_relations`, where several rows point at one
            // entry — yields that entry's key once per matching row. The
            // write touches it once, so recording one row per duplicate would
            // claim a single change happened several times. An audit trail
            // that overstates is not evidence either.
            $keys = $this->toBase()->lockForUpdate()
                ->pluck($model->getQualifiedKeyName())
                ->unique()
                ->values()
                ->all();

            // whereKey qualifies the column, so this is unambiguous even
            // when the caller joined another table.
            $this->whereKey($keys);

            // ⚠️ And the PAGINATION goes, because it has already been spent.
            //
            // `orderBy('id')->offset(1)->limit(1)->update(...)` captured the
            // second row and then reapplied offset 1 to that singleton — so
            // the write touched nothing while the loop below still recorded
            // the action. The audited set and the written set have to be the
            // same set, and a limit that already selected the keys must not
            // select among them again.
            $base = $this->getQuery();
            $base->offset = null;
            $base->limit = null;

            // Raw pre-write state, for the revision comparison below. Read
            // inside the same transaction and after the same lock, so it
            // describes exactly the rows the write is about to change.
            $before = $this->versionedStateOf($keys, $written);

            $result = $write();

            $this->recordRevisions($before);

            if ($keys === []) {
                return $result;
            }

            // `entry.updated`, not `entries.updated` — an action names the
            // thing acted on, and the rest of the trail is written in those
            // terms.
            $auditor = app(Auditor::class);
            $action = Str::snake(class_basename($model)).'.'.$action;

            foreach ($keys as $key) {
                $target = $model->newInstance([], true);
                $target->forceFill([$model->getKeyName() => $key]);

                $auditor->recordOrFail($action, $target);
            }

            return $result;
        });
    }

    /**
     * Raw versioned columns for the rows a write is about to change.
     *
     * Empty — and therefore free — unless the write actually assigns something
     * versioned, so an ordinary `touch()` or soft delete costs nothing.
     *
     * ⚠️ Skipped entirely for an INSTANCE save. `Model::performUpdate()` writes
     * through this builder, so recording here as well as in the `updated` event
     * would file two revisions for one save. That is not hypothetical: adding
     * the audit builder alongside the audit listeners double-recorded every
     * entry write, and no test caught it because they asserted a row EXISTS and
     * two satisfy that. A loaded model is the discriminator Laravel itself uses
     * for `setKeysForSaveQuery()`, and the same one `refusePerRowColumns()` uses.
     *
     * @param  array<int, mixed>  $keys
     * @param  array<string, mixed>  $written
     * @return array<int|string, array<string, mixed>>
     */
    private function versionedStateOf(array $keys, array $written): array
    {
        $model = $this->getModel();

        if ($keys === [] || RevisionWrites::suspended()) {
            return [];
        }

        // ⚠️ EVERY update records here, instance saves included — and an earlier
        // version deferred those to the `updated` listener to avoid doing the
        // extra reads on the hot path.
        //
        // That was wrong for a reason cost cannot answer. The listener fires
        // AFTER this builder's transaction has committed and released its row
        // lock, so two writers interleave: T1 commits A, T2 commits and records
        // B, then T1 records A — leaving revision A newest while the live entry
        // is B. A relation write in the same window can contaminate A's
        // `relation_state` too. A version has to be recorded under the lock that
        // made the write atomic, which means recording where the write is.
        //
        // So there is one recorder for updates and the listener no longer files
        // them. Quiet saves fall out for free: they suppressed the listener while
        // still writing through here, which was a second bug with the same cause.

        $touched = false;

        foreach (array_keys($written) as $column) {
            // ⚠️ The JSON PATH's root counts. Laravel supports
            // `update(['values->body' => '...'])`, and `bareColumn()` returns
            // `values->body`, which never matched the versioned column `values` —
            // so an inline field could be rewritten with no version recorded, and
            // a later restore would silently undo it.
            $bare = explode('->', $this->bareColumn((string) $column))[0];

            if (in_array($bare, Entry::VERSIONED_COLUMNS, true)) {
                $touched = true;

                break;
            }
        }

        if (! $touched) {
            return [];
        }

        return $this->rawVersionedRows($keys);
    }

    /**
     * Read the versioned columns for these keys, by key alone.
     *
     * Unscoped deliberately: the keys came from this builder's own scoped
     * predicate a moment ago, so re-applying the scope adds nothing, and a soft
     * delete or restore in the same statement would otherwise change which rows
     * are visible between the two reads.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int|string, array<string, mixed>>
     */
    private function rawVersionedRows(array $keys): array
    {
        $model = $this->getModel();
        $key = $model->getKeyName();

        $rows = $model->newQueryWithoutScopes()->toBase()
            ->whereIn($model->getQualifiedKeyName(), $keys)
            ->get([$key, ...Entry::VERSIONED_COLUMNS]);

        $state = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $state[$row[$key]] = $row;
        }

        return $state;
    }

    /**
     * File a revision for every row the write actually changed.
     *
     * ⚠️ CHANGED, not merely matched. `update(['status' => 'published'])` over a
     * set already published matches every row and alters none, and a revision
     * per matched row would fill the history with versions identical to their
     * predecessor. Raw-to-raw comparison, so a `values` array is never compared
     * against its own JSON encoding — which differs on every write.
     *
     * @param  array<int|string, array<string, mixed>>  $before
     */
    private function recordRevisions(array $before): void
    {
        if ($before === []) {
            return;
        }

        $after = $this->rawVersionedRows(array_keys($before));

        // One query for the models, then the model's own snapshot logic — so
        // the bulk path cannot drift from what an ordinary save records, and
        // pruning still bounds the history.
        $changed = [];

        foreach ($before as $key => $row) {
            if (isset($after[$key]) && $after[$key] !== $row) {
                $changed[] = $key;
            }
        }

        if ($changed === []) {
            return;
        }

        foreach ($this->getModel()->newQueryWithoutScopes()->whereKey($changed)->get() as $entry) {
            // Same hand-off as the insert path, for the same reason.
            $this->carryRetainedOriginals($entry);

            $entry->recordRevisionForEventlessWrite(
                $before[$entry->getKey()],
                $after[$entry->getKey()],
            );
        }
    }

    /**
     * Refuse a column `Entry`'s checks read by name, written under any other name — qualified, cased or quoted.
     *
     * On every write that carries values, because every one reaches a check that reads the type by name: the
     * creation guard, the publication guard (whose instance the arithmetic doors fill from `$extra`), and the restamp.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function refuseMisnamedEntryColumns(array $values): void
    {
        foreach (array_keys($values) as $written) {
            $column = $this->bareColumn((string) $written);

            if (in_array($column, self::READ_BY_NAME, true)) {
                $this->refuseMisnamedGuardedColumn((string) $written, $column);
            }
        }
    }

    /**
     * Refuse a per-row column smuggled in as an arithmetic assignment.
     *
     * ⚠️ Laravel's arithmetic methods take an `$extra` map of ORDINARY assignments,
     * and they forward straight to the query builder — so they reach neither
     * `update()` nor `ScopedBuilder::refusePerRowColumns()`.
     *
     * `increment('ordering', 0, ['values' => '{"body":"<script>…"}'])` therefore put
     * raw bytes into `entries.values` with no conversion, and the revision snapshotted
     * them unsanitized. The same route already had to be closed once for auditing and
     * once for versioning; this is the third thing it was skipping.
     *
     * REFUSED rather than converted, for the reason a bulk update is: an arithmetic
     * statement can match any number of rows of any number of types, so there is no
     * single correct conversion for the values it carries.
     *
     * @param  array<string, mixed>  $extra
     */
    private function refusePerRowExtras(array $extra): void
    {
        if ($extra !== []) {
            $this->refusePerRowColumns($extra);
        }
    }

    /**
     * Move the saving instance's pending originals onto the instance that records.
     *
     * ⚠️ Only when they are the same ROW. A bulk update reloads many entries and the
     * builder's model is one of them at most — attaching one row's originals to
     * another would be a false record of what its author wrote.
     */
    private function carryRetainedOriginals(?Entry $target): void
    {
        if ($target === null) {
            return;
        }

        $source = $this->getModel();

        // ⚠️ Only when they are the same ROW — except on insert, where the model has
        // no key yet and the row being inserted is by definition the builder's own.
        // `performInsert()` assigns the key after `insertGetId()` returns, so a null
        // key here IS the insert path rather than a case needing its own flag.
        if ($source->getKey() !== null && ! $source->is($target)) {
            return;
        }

        $target->carryRetainedOriginalsFrom($source);
    }
}
