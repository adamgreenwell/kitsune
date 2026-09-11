<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Tenancy\Attributes\Unscoped;
use Kitsune\Core\Tenancy\Contracts\RefusesCascadingDeletes;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * The write half of the scope, on the paths a model event cannot see.
 *
 * ⚠️ `EnforcesScope` enforces the scope keys from `creating` and `updating`.
 * A mass update instantiates no models and dispatches nothing:
 *
 *   Entry::query()->update(['org_id' => $rival, 'site_id' => $rivalSite]);
 *   Site::query()->update(['org_id' => $rival]);
 *
 * The global scope limits which rows are SELECTED and says nothing about the
 * values assigned, so this transferred the current scope's rows into another
 * org — where the scope then showed them to their new owner and hid them from
 * their author — without going near `withoutScopeBecause()`.
 *
 * The same shape has now been found on four models in this project. It is not
 * a property of any of them: a guard in a model event is a guard on one path.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ScopedBuilder extends Builder
{
    /**
     * Takes the model, so the type parameter is known at construction.
     *
     * ⚠️ Not for convenience. `new ScopedBuilder($query)` gives an analyser no
     * way to resolve TModel, so it falls back to `Model` — and every scoped
     * model's queries then lose their own type, turning `Site::create()` into
     * `Model` and making `Entry::ofType()` undefined. Passing the model
     * resolves it, and the trait passes `$this`.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  TModel  $model
     */
    public function __construct($query, Model $model)
    {
        parent::__construct($query);

        $this->setModel($model);
    }

    /** @param  array<string, mixed>  $values */
    public function update(array $values)
    {
        $this->guardScopeKeys($values);
        $this->refusePerRowColumns($values);

        return parent::update($values);
    }

    /**
     * ⚠️ THE INSERT FAMILY WAS UNGUARDED, so `columnsRequiringModelSave()` covered half the doors.
     * Measured on `Site` before this: `update(['base_url' => …])` refused, and
     * `insert([… 'base_url' => 'https://x.test' …])` created a row with `canonical_host = NULL` — a
     * site declaring a public URL and reachable at none. `RequiresModelSave`'s docblock claimed the
     * model event became "the only door rather than the first one", which was true of `update()`
     * alone (issue #60).
     *
     * ⚠️ REFUSED BY METHOD, NOT BY `$model->exists`, which is the discriminator the reverted first
     * attempt used and why it refused every ordinary create. `Model::performInsert()` writes through
     * this builder, and during an insert `exists` is false — so a guard keyed on it fires on the
     * legitimate path. `AuditedBuilder` already solved this for `Entry` and the answer is which
     * METHOD was called: `performInsert()` uses `insertGetId()` for an incrementing model, and a bulk
     * caller uses `insert()` or one of the `…Using` forms. Those are refused; `insertGetId()` is not.
     *
     * ⚠️ AND ONLY WHEN THE MODEL INCREMENTS, because that assumption is what makes the method a
     * discriminator at all: a non-incrementing model's `performInsert()` uses `insert()`, so refusing
     * it there would break creates exactly as the reverted attempt did. No `RequiresModelSave` model
     * is non-incrementing today and `PerRowInsertGuardTest` asserts that, so the day one appears the
     * test fails rather than the creates.
     *
     * ⚠️ `->toBase()` AND `DB::table()` REMAIN OUT OF SCOPE by construction. Guards live at the
     * Eloquent layer and nothing there can police a caller who has explicitly stepped below it. That
     * is a boundary rather than an oversight, and it is stated so it is not mistaken for one.
     *
     * @param  array<string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $this->refuseBulkCreate('insert');

        return parent::insert($values);
    }

    /** @param  array<string, mixed>  $values */
    public function insertOrIgnore(array $values): int
    {
        // ⚠️ Refused whatever the model's key strategy: `performInsert()` never uses this one, so
        // there is no legitimate per-row caller to protect.
        $this->refuseBulkCreate('insertOrIgnore', always: true);

        return parent::insertOrIgnore($values);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $this->refuseBulkCreate('insertUsing', always: true);

        return parent::insertUsing($columns, $query);
    }

    /**
     * @param  array<int, string>  $columns
     * @param  mixed  $query
     */
    public function insertOrIgnoreUsing(array $columns, $query): int
    {
        $this->refuseBulkCreate('insertOrIgnoreUsing', always: true);

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    /**
     * ⚠️ `insertGetId()` IS PUBLICLY CALLABLE, which the first version of this guard treated as if it
     * were `performInsert()`'s private door. `Site::query()->insertGetId([… 'base_url' => …])`
     * therefore still wrote a row with whatever `canonical_host` the caller chose, or none — the same
     * cross-org claim hole the change was meant to close, reached one method along. Found by review,
     * and the test that claimed to enumerate every creation path did not cover it.
     *
     * ⚠️ THE DISCRIMINATOR IS THE MODEL BEHIND THE BUILDER, not the method. `Model::performInsert()`
     * builds its query from `newModelQuery()`, so `getModel()` IS the instance being saved and every
     * guarded value in `$values` came off its own attributes — the `saving` hooks having already put
     * them there. `Site::query()` builds one from a fresh, empty instance, so a guarded column in
     * `$values` has nothing on the model to match. That is a general test rather than a per-model one,
     * which matters because the four guarded models guard different KINDS of column: `Site`'s are
     * derived, `EntryType`'s and `Field`'s are validated, and a create legitimately names those.
     *
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        /*
         * ⚠️ THE SCOPE KEYS TOO, WHICH THIS PATH ALONE WAS MISSING — review found it, and the hole is
         * a cross-org write rather than a malformed column. `org_id` is NOT in
         * `Site::columnsRequiringModelSave()` and does not need to be: it is stamped by
         * `EnforcesScope`'s `creating` listener, which `insertGetId()` never dispatches. So a caller in
         * org A could name org B's id, omit every guarded column, and leave `refuseDetachedInsert()`
         * with nothing to inspect. Measured: the row landed in org B.
         *
         * ⚠️ AND `update()` HAS ALWAYS DONE THIS, as have both of `AuditedBuilder`'s paths. Three call
         * sites guarded the keys and the fourth delegated straight to the parent — the shape of gap
         * this whole issue is about, one method along again.
         */
        $this->refuseDetachedScopeKeys($values);
        $this->refuseDetachedInsert('insertGetId', $values);

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  non-empty-array<non-empty-string>  $returning
     * @param  non-empty-string|non-empty-array<non-empty-string>|null  $uniqueBy
     * @return Collection<int, mixed>
     */
    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null): Collection
    {
        // `performInsert()` never uses this one, so there is no per-row caller to protect.
        $this->refuseBulkCreate('insertOrIgnoreReturning', always: true);

        /*
         * ⚠️ Forwarded through `toBase()` rather than `parent::`, because Eloquent's builder does not
         * declare this method — it reaches the QUERY builder through `__call`, which static analysis
         * cannot follow. Naming the real receiver is clearer than annotating around the magic.
         */
        return $this->toBase()->insertOrIgnoreReturning($values, $returning, $uniqueBy);
    }

    /**
     * Refuse a HAND-ROLLED insert that names another scope's key.
     *
     * ⚠️ THE SCOPE KEYS WERE UNGUARDED ON THIS PATH ALONE — review found it, and the hole is a
     * cross-org write rather than a malformed column. `org_id` is NOT in
     * `Site::columnsRequiringModelSave()` and does not need to be: it is stamped by `EnforcesScope`'s
     * `creating` listener, which `insertGetId()` never dispatches. So a caller in org A could name org
     * B's id, omit every guarded column, and leave `refuseDetachedInsert()` nothing to inspect.
     * Measured: the row landed in org B. `update()` has always guarded the keys, and so have both of
     * `AuditedBuilder`'s paths — three call sites did and the fourth delegated straight to the parent.
     *
     * ⚠️ ONLY A DETACHED INSERT, AND THAT LIMIT IS MEASURED RATHER THAN CHOSEN. Guarding every insert
     * refuses 37 tests across ten files, because naming another org's id on a MODEL create is a shape
     * this codebase uses deliberately — a fixture building a rival org's data, a console command
     * seeding one. That is a policy about model creates, settled where `EnforcesScope` runs, and this
     * method has no business relitigating it: `Entry` already guards its keys on insert through
     * `AuditedBuilder`, so the two would otherwise disagree in the other direction.
     *
     * ⚠️ THE DISCRIMINATOR IS WHETHER THIS KEY CAME OFF THE MODEL, per key, and it took two attempts.
     * "The model has any attributes at all" was the first, and it was presence again and wrong again:
     * `Site` declares a default `url_strategy`, so a FRESH instance has an attribute and
     * `Model::query()` looked like a save. Caught by the test for the very hole this closes.
     *
     * Equality on the key itself is the question actually being asked. `Model::performInsert()` builds
     * `$values` FROM the instance's attributes, so the org id there is the org id on the model; a
     * hand-rolled insert names one the model has never held. This is safe where equality was not safe
     * for `refuseDetachedInsert()` — there `AuditedBuilder` deliberately TRANSFORMS an `Entry`'s
     * `values` on the way down, and nothing transforms a scope key.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseDetachedScopeKeys(array $values): void
    {
        /*
         * ⚠️ THE ESCAPE HATCH STANDS THIS DOWN TOO, and review found it did not — while the refusal
         * below names that hatch as the remedy. `guardScopeKeys()`'s own check says "the reviewable
         * escape hatch stands BOTH enforcers down, not one", and this was a third enforcer running in
         * front of it: `Site::withoutScopeBecause('provisioning', fn ($q) => $q->insertGetId([...]))`
         * threw from the no-context branch before the suspension was ever consulted.
         *
         * A guard whose message recommends a remedy that the guard itself defeats is worse than no
         * message — provisioning is the documented reason the hatch exists.
         */
        if (ScopeWrites::suspended()) {
            return;
        }

        $model = $this->getModel();

        /*
         * ⚠️ THE MODEL BEHIND THE BUILDER IS EVIDENCE A CALLER CAN MANUFACTURE, which review found and
         * which invalidates the whole comparison below as an authorisation. `getModel()` and
         * `setModel()` are public, so:
         *
         *     $query = Site::query();
         *     $query->getModel()->org_id = $victim->id;
         *     $query->insertGetId(['org_id' => $victim->id, … ]);   // and no URL columns
         *
         * makes every key match, empties `$detached`, and returns before anything else looks. Measured
         * from an org's own context: the row was written and the victim org owned it.
         *
         * ⚠️ SO THE CONTEXT IS ASKED FIRST, BECAUSE THE CONTEXT IS NOT FORGEABLE — it is application
         * state reached through the container, and the audited way to stand it down is the suspension
         * checked above. `guardScopeKeys()` compares the written keys against it and refuses a write
         * that names another scope, whatever the model behind the query says.
         *
         * ⚠️ AND WHETHER TO ASK IS READ FROM THE CLASS, NOT FROM THE INSTANCE. `ScopeResolver::for()`
         * returns the scope a model DECLARES with an attribute, which cannot change at runtime, so this
         * decision has no mutable input at all. A declared scope means its keys are enforced — and
         * `EnforcesScope`'s own `creating` listener already applies exactly this rule, so nothing
         * legitimate changes: what changes is that a QUIET or hand-rolled write can no longer skip it.
         *
         * `#[Unscoped]` is left alone deliberately. `EntryType::create(['org_id' => $theirs->id])` is a
         * settled shape with a test of its own — a global type must be creatable for another org — and
         * the comparison below is what keeps a hand-rolled insert on those models honest.
         */
        if (ScopeResolver::for($model::class) !== Unscoped::class) {
            /*
             * ⚠️ AND NO CONTEXT IS NOT PERMISSION HERE EITHER, which review found the declared-scope
             * path missing. `guardScopeKeys()` accepts every value when the context has none to compare
             * against — right for an UPDATE, where the row already belongs to somebody — and a quiet
             * create suppresses `EnforcesScope`, so `SiteGroup::createQuietly(['org_id' => $victim, …])`
             * from a job with no `Context` was accepted by both halves and planted the row. Measured:
             * ALLOWED, one row for an org nothing had vouched for.
             *
             * A model that DECLARES a scope says its keys mean something, so a non-null one with nothing
             * to check it against fails closed. The audited way to say it is deliberate is the
             * suspension checked above, which is what provisioning uses.
             */
            $this->refuseKeysWithNoContext($values);
            $this->guardScopeKeys($values);
        }

        $detached = [];

        foreach ($values as $column => $value) {
            $bare = $this->bareColumn((string) $column);

            if ($bare !== 'org_id' && $bare !== 'site_id') {
                continue;
            }

            $onModel = $model->getAttribute($bare);

            // Loose on purpose: an id is an int on the model and may arrive as a numeric string.
            if ($onModel === null || (string) $onModel !== (string) $value) {
                $detached[$bare] = $value;
            }
        }

        if ($detached === []) {
            return;
        }

        /*
         * ⚠️ NO CONTEXT IS NOT PERMISSION, which review found. `guardScopeKeys()` accepts every value
         * when the context has none to compare against — right for an update, where the row already
         * belongs to somebody and the caller is not choosing — and wrong here: a console command or a
         * queue job with no `Context` could hand-roll an insert naming ANY org, and nothing could vouch
         * for it either way. Measured, that is the same cross-org planting as the earlier case with the
         * comparison removed instead of satisfied.
         *
         * `AuditedBuilder` already treats a keyed write with no context this way for `Entry` — "refuses
         * a create with no org context, and leaves no entry behind" — so this makes the two agree rather
         * than inventing a policy.
         */
        $this->refuseKeysWithNoContext($detached);

        $this->guardScopeKeys($detached);
    }

    /**
     * Refuse a scope key written with no context to vouch for it.
     *
     * ⚠️ NO CONTEXT IS NOT PERMISSION, and it took two rounds to apply that to both callers.
     * `guardScopeKeys()` accepts every value when the context has none to compare against, which is
     * right for an UPDATE — the row already belongs to somebody and the caller is not choosing — and
     * wrong for an INSERT, where the caller is choosing and nothing can vouch for the choice either way.
     *
     * Both paths that write a scope key on an insert ask this now: a hand-rolled one, whose keys did
     * not come off the model, and a DECLARED-SCOPE one whatever the model says — because a quiet create
     * suppresses `EnforcesScope` and review measured `SiteGroup::createQuietly(['org_id' => $victim])`
     * planting a row from a job with no context.
     *
     * `AuditedBuilder` already refuses a keyed `Entry` create with no org context, so this makes the
     * builders agree rather than inventing a policy.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseKeysWithNoContext(array $values): void
    {
        $context = app(Context::class);

        foreach ($values as $column => $value) {
            $bare = $this->bareColumn((string) $column);

            if ($bare !== 'org_id' && $bare !== 'site_id') {
                continue;
            }

            $current = $bare === 'org_id' ? $context->orgId() : $context->siteId();

            if ($value !== null && $current === null) {
                throw new RuntimeException(sprintf(
                    'Refusing to insert %s with [%s] = %s from no scope at all: with no context '
                    .'established there is nothing that can vouch for the value either way, and a quiet '
                    .'or hand-rolled write dispatches no `EnforcesScope` event to derive it (ADR-021). '
                    .'Save the model with a context established, or use withoutScopeBecause() if this is '
                    .'deliberate.',
                    $this->getModel()::class,
                    $bare,
                    is_scalar($value) ? (string) $value : gettype($value),
                ));
            }
        }
    }

    /**
     * Refuse an insert that names a guarded column the model behind it never set.
     *
     * ⚠️ THE COMPARISON IS AGAINST THE BUILDER'S OWN MODEL, and that is what separates a save from a
     * hand-rolled insert without needing to know what any column means. On a save the values came
     * off that instance, so they match; on `Model::query()->insertGetId([...])` the instance is empty
     * and they cannot.
     *
     * ⚠️ ABSENT IS NOT A MISMATCH. A row that names no guarded column has nothing this can check and
     * nothing it needs to: the columns' correctness is the thing being protected, and a row that does
     * not touch them cannot get them wrong.
     *
     * @param  array<string, mixed>  $values
     */
    private function refuseDetachedInsert(string $method, array $values): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        foreach ($model::columnsRequiringModelSave() as $column => $reason) {
            if (! array_key_exists($column, $values)) {
                continue;
            }

            /*
             * ⚠️ PRESENCE PROVED NOTHING, WHICH IS WHAT REVIEW FOUND. This asked whether the guarded
             * column existed on the model behind the builder, reasoning that a saving model has it and
             * the empty instance `Model::query()` makes does not. `createQuietly()`, `saveQuietly()`
             * and anything inside `withoutEvents()` populate attributes while suppressing the `saving`
             * callback that derives them — so `Site::createQuietly(['base_url' => …])` wrote
             * `canonical_host = NULL` for a site declaring a public URL, and a quiet create naming the
             * derived columns itself STOLE AN OVERLAPPING CROSS-ORG CLAIM. Measured: `steal.test/` held
             * by one org, `steal.test/news` written under another, which is the ADR-021 theft this
             * guard exists to prevent.
             *
             * An attribute can be supplied by any caller. The flag is set by the code that derives, so
             * a path that skipped the deriving cannot present it — and equality, the version before
             * presence, was never available: `AuditedBuilder::insertGetId()` transforms an `Entry`'s
             * `values` before delegating here, so it no longer equals the attribute it came from and
             * comparing them refused every audited create.
             */
            /*
             * ⚠️ AND THROUGH THIS BUILDER, which review found this branch not asking. The proof says the
             * guards ran; it does not say WHICH write they ran for. A `creating` or `updating` observer
             * can call `$site->newQuery()->insertGetId([…])` after the arming listener, and that new
             * builder wraps the same model — so the proof was true for a nested hand-written insert.
             *
             * `refuseBulkCreate()` was taught this one round ago and this branch was not, which is the
             * asymmetry rather than the mechanism: both questions are "is this write the save", and both
             * ask the model for the builder it is being saved through.
             */
            if ($model->isPerformingModelSave($this) && $model->guardedColumnsAreDerived()) {
                continue;
            }

            throw new RuntimeException(sprintf(
                '[%s] cannot be written by %s() on %s: %s The model behind this query never set that '
                .'value, so the checks that derive and validate it did not run. Save the model '
                .'instead.',
                $column,
                $method,
                $model::class,
                $reason,
            ));
        }
    }

    /**
     * Refuse a bulk creation path on a model whose columns need a per-row guard.
     *
     * ⚠️ THE MESSAGE NAMES THE COLUMNS AND THE REASON, because a refusal an importer cannot act on
     * is a wall rather than a guard. Every column in `columnsRequiringModelSave()` is listed with
     * the sentence the model gave for it.
     */
    private function refuseBulkCreate(string $method, bool $always = false): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        // See the note on `insert()`: the method is a discriminator only where `performInsert()`
        // does not use it.
        /*
         * ⚠️ NOT `getIncrementing()`, WHICH A CALLER CAN SET. Review measured it:
         * `Site::query()->getModel()->setIncrementing(false)` then `insert()` was classified as a
         * non-incrementing model save although no model event ran — and with caller-authored
         * `canonical_host` and an overlapping `path_prefix` it landed the cross-org claim
         * `refuseOverlappingClaim()` exists to prevent. Two rows on one host, measured.
         *
         * The question was never "does this model increment" but "is this a model save", and
         * `isPerformingModelSave()` answers that one directly: true inside a non-incrementing model's
         * `insert()`, false for a hand-rolled one. The key strategy is no longer part of the decision,
         * so `RequiresModelSave`'s assumption that every implementor increments is gone with it.
         */
        if (! $always && $model->isPerformingModelSave($this)) {
            return;
        }

        $guarded = $model::columnsRequiringModelSave();

        if ($guarded === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s cannot be created in bulk with %s(): %s A bulk insert dispatches no model events, so '
            .'the checks that derive and validate those columns never run — and the row is written '
            .'with them empty, which for a URL claim means a site declaring an address it can never '
            .'be reached at. Save the model instead.',
            $model::class,
            $method,
            implode(' ', array_map(
                static fn (string $column, string $reason): string => "[{$column}] {$reason}",
                array_keys($guarded),
                $guarded,
            )),
        ));
    }

    /**
     * ⚠️ REFUSED OUTRIGHT, because nothing this class does runs on it.
     *
     * `updateOrInsert()` is forwarded WHOLE to the query builder — the repository already says so in
     * `AuditedBuilder::updateOrInsert()`, and review found the lesson had not travelled here. Neither
     * the `insert()` override nor the `update()` one sees it, and it has THREE consequences rather than
     * the two that are obvious. Measured, all three:
     *
     *   unmatched predicate   a `sites` row with `base_url = https://planted.test` and
     *                         `canonical_host = NULL` — a site declaring a public address and
     *                         reachable at none, which is the sentence `RequiresModelSave` exists for
     *   matched predicate     `entry_types.handle` moved to `admin`, a handle ADR-012 RESERVES because
     *                         it collides with a registered route
     *   either way            THE GLOBAL SCOPE IS NEVER APPLIED. On the same row in the same org
     *                         context, `update()` reported 0 rows affected and `updateOrInsert()`
     *                         renamed another org's site. Scopes are applied by the Eloquent builder;
     *                         a call forwarded past it is unscoped.
     *
     * ⚠️ AND NOT CONDITIONAL ON `ScopeWrites::suspended()` or on `RequiresModelSave`, unlike every
     * other guard here. Those relax a check that RAN; there is no check to relax, because the method is
     * not wired to this builder at all — and the third consequence has nothing to do with derived
     * columns, so a model with none is no safer. `firstOrNew()` then `save()` is the same operation
     * through the door that has the guards, and `DB::table()` remains the stated boundary for a caller
     * who means to step below Eloquent.
     *
     * ⚠️ FOUR OTHER BUILDERS ALREADY REFUSE IT — `AuditedBuilder`, `AppendOnlyBuilder`,
     * `GuardedRelationBuilder`, `GuardedStorageBuilder`. This was the fifth and the only one missing,
     * which is why the fix came with a sweep of every write method on the query builder rather than
     * this one method.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $values
     * @return bool
     */
    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        throw new RuntimeException(sprintf(
            'updateOrInsert() cannot be used on %s: Laravel forwards it whole to the query builder, so '
            .'neither the insert guards nor the update guards on this builder run — and the global '
            .'scope is not applied either, so it can write another org\'s row. Measured, it planted a '
            .'site with a public URL and no canonical host, moved an entry type onto a reserved '
            .'handle, and renamed a rival org\'s site that a scoped update could not see. Use '
            .'firstOrNew() and save().',
            $this->getModel()::class,
        ));
    }

    /**
     * ⚠️ AND `truncate()`, which the same sweep found — the worse of the two.
     *
     * A global scope constrains a WHERE clause and `TRUNCATE` has none, so there is nothing for it to
     * narrow. Measured: two sites in two orgs, one org's context, `Site::query()->truncate()` left ZERO
     * rows. It also bypasses the cascade refusal that `delete()` and `forceDelete()` route through, so
     * every referenced entry goes with it.
     *
     * ⚠️ THE RULE THE SWEEP PRODUCED, rather than a list of methods to copy: `truncate()` belongs
     * wherever `delete()` is guarded. The three builders that override it all guard deletion;
     * `GuardedStorageBuilder` guards CREATION only and correctly has no override, because truncating
     * creates nothing. This builder guards deletion, so the absence was a gap rather than a decision.
     */
    public function truncate(): void
    {
        throw new RuntimeException(sprintf(
            'truncate() cannot be used on %s: it has no WHERE clause for the org scope to narrow, so '
            .'it removes every row in every org — measured, two sites in two orgs left zero — and it '
            .'bypasses the cascade refusal that delete() routes through. Delete through the model.',
            $this->getModel()::class,
        ));
    }

    /**
     * ⚠️ Deletion is guarded HERE as well as in the model event.
     *
     * `Site::query()->delete()`, `deleteQuietly()` and anything inside
     * `withoutEvents()` dispatch no `deleting` callback, so a refusal written
     * as a model event covered one path — and the `ON DELETE CASCADE` on
     * `entries.site_id` and `entries.entry_type_id` then hard-deleted every
     * referenced entry with no audit row and no soft delete.
     */
    public function delete()
    {
        return $this->guardingCascade(fn () => parent::delete());
    }

    /**
     * ⚠️ `forceDelete()` too. Eloquent sends it straight to the query builder
     * rather than through `delete()`, so the cascade refusal did not see it —
     * and on a soft-deleting model it is the one that actually removes rows.
     */
    public function forceDelete()
    {
        return $this->guardingCascade(fn () => parent::forceDelete());
    }

    /**
     * Run a deletion with the cascade refusal, atomically.
     *
     * ⚠️ In a TRANSACTION, with the referencing rows locked. The check counted
     * references and the DELETE ran as separate statements, so a child inserted
     * between them was cascaded away permanently despite the refusal — the
     * refusal was advisory under concurrent load, which is the state it exists
     * to prevent.
     */
    private function guardingCascade(callable $delete): mixed
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RefusesCascadingDeletes) {
            return $delete();
        }

        return DB::transaction(function () use ($model, $delete) {
            // ⚠️ The ROWS, not their keys — and keys was a silent hole.
            //
            // A guard was handed `newInstance([], true)` carrying nothing but the
            // primary key, which worked for `EntryType::guardCascade()` only
            // because it counts entries BY that key. `Field::guardCascade()` has
            // to read `field_storage_id` and `entry_type_id` to know what data to
            // look for, found both null on a key-only instance, and returned
            // early — so the bulk and quiet delete paths passed a guard that
            // never ran. A guard cannot judge a row it has not been given.
            // ⚠️ The COMPLETE row, because `get()` inherits the caller's
            // projection. `Field::query()->select('id')->delete()` handed the
            // guard a model with no `field_storage_id` again — and the DELETE
            // ignores a SELECT list, so the row went and its data stranded. The
            // projection is reset rather than trusted.
            foreach ((clone $this)->select($model->getTable().'.*')->lockForUpdate()->get() as $row) {
                // Narrowed per row: this builder is generic over its model, so
                // the contract check above constrains the prototype rather than
                // what the query returns.
                if ($row instanceof RefusesCascadingDeletes) {
                    $row->guardCascade();
                }
            }

            return $delete();
        });
    }

    /**
     * ⚠️ Arithmetic on a scope column is refused outright, never compared.
     *
     * The scope-key guard reads a value; an increment supplies an AMOUNT. So
     * `increment('org_id', 1)` with the current org 1 compared 1 against 1 and
     * PASSED — then added 1, moving the row to org 2. The guard was reading the
     * delta as though it were the destination, and no amount added to a scope
     * key lands somewhere this context can vouch for.
     *
     * Protected, because AuditedBuilder extends this and needs it: Entry has
     * one builder and both sets of guards.
     *
     * @param  array<string, mixed>  $values
     */
    protected function refuseScopeArithmetic(array $values): void
    {
        if (ScopeWrites::suspended()) {
            return;
        }

        foreach (array_keys($values) as $column) {
            $bare = $this->bareColumn((string) $column);

            if ($bare === 'org_id' || $bare === 'site_id') {
                throw new RuntimeException(sprintf(
                    'Refusing to increment or decrement [%s]: it is a scope key, and no amount added '
                    .'to one lands somewhere this context can vouch for (ADR-021). Set the value '
                    .'through a save if the move is deliberate.',
                    $bare,
                ));
            }
        }
    }

    /**
     * Refuse a bulk write to a column whose guard can only run per row.
     *
     * ⚠️ Six guards in this project have now been found bypassed by a bulk
     * write. A model names the columns whose correctness depends on the row and
     * this refuses them, so the model event becomes the only door rather than
     * the first one.
     *
     * @param  array<string, mixed>  $values
     */
    protected function refusePerRowColumns(array $values): void
    {
        $model = $this->getModel();

        if (ScopeWrites::suspended() || ! $model instanceof RequiresModelSave) {
            return;
        }

        // ⚠️ An INSTANCE save reaches this method too, because
        // `Model::performUpdate()` writes through the builder — so refusing
        // every bulk-shaped write would refuse `$model->update(...)` as well.
        //
        // ⚠️ AND `exists` ALONE WAS THE WRONG TEST, for the reason the insert
        // guard records at length: this stood aside because "the guards it
        // stands aside for have already run in `saving`", and a quiet save
        // suppresses `saving` while still being an instance save. Measured:
        // `$site->saveQuietly()` moved `base_url` with `canonical_host` left on
        // the old address. Both halves are needed — one says it is an instance
        // write rather than a bulk one, and the flag says the guards for that
        // write actually ran.
        /*
         * ⚠️ AND `exists` WAS ALSO ARRANGEABLE, which review found next. `Builder::setModel()` is
         * public, so `$query = Entry::query(); $query->setModel($loadedEntry); $query->update([…])`
         * presented a model that exists AND — through `AuditedBuilder::update()` calling
         * `convertFieldValuesForWrite()` on that one entry — a freshly armed proof. The update then ran
         * across every matching row, converting all of them against one entry's schema and skipping
         * their own per-row validation. Measured: two rows, and the second one's values replaced.
         *
         * `isPerformingModelSave()` cannot be arranged: it is private, has no setter, and is true only
         * inside the instance's own `performUpdate()`. A model handed to `setModel()` is not in one.
         */
        if ($model->isPerformingModelSave($this) && $model->guardedColumnsAreDerived()) {
            return;
        }

        $guarded = $model::columnsRequiringModelSave();

        foreach (array_keys($values) as $column) {
            $bare = $this->bareColumn((string) $column);

            if (isset($guarded[$bare])) {
                throw new RuntimeException(sprintf(
                    '[%s] cannot be written in bulk on %s: %s A bulk update dispatches no model '
                    .'events, so the check that would refuse this never runs. Save the model '
                    .'instead.',
                    $bare,
                    $model::class,
                    $guarded[$bare],
                ));
            }
        }
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @param  string|Expression  $column
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuseScopeArithmetic([(string) $column => $amount, ...$extra]);

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = [])
    {
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return parent::incrementEach($columns, $extra);
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = [])
    {
        $this->refuseScopeArithmetic([...$columns, ...$extra]);

        return parent::decrementEach($columns, $extra);
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     * @return int
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        // ⚠️ REFUSED, not guarded. The conflict target is not constrained by
        // the global scope, so validating the proposed values is not enough:
        // from org A, upserting a row carrying org A's `org_id` and org B's
        // primary key passes every check and then UPDATES org B's row. The row
        // being overwritten is never named in the values, so there is nothing
        // here that validation could inspect to make it safe.
        if (! ScopeWrites::suspended()) {
            throw new RuntimeException(sprintf(
                'Refusing to upsert %s: the conflict target is not constrained by the scope, so a '
                .'row belonging to another org or site could be overwritten by an insert that looks '
                .'entirely valid (ADR-021). Save the model, or use withoutScopeBecause() if this is '
                .'deliberate.',
                $this->getModel()::class,
            ));
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * ⚠️ Refused rather than guarded. It writes through a join, so the values
     * assigned are not visible here at all.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function updateFrom(array $values)
    {
        throw new RuntimeException(
            'updateFrom() assigns through a join, so the scope keys it writes cannot be checked '
            .'(ADR-021). Update through a predicate on the table instead.'
        );
    }

    /**
     * A row this scope writes has to belong to this scope.
     *
     * Silent with no context, matching EnforcesScope: console commands,
     * migrations and the installer legitimately run without one. NULL
     * `site_id` is org-shared and legitimate.
     *
     * @param  array<string, mixed>  $values
     */
    /** Strip table qualification and quoting, so `entries`.`org_id` is `org_id`. */
    protected function bareColumn(string $column): string
    {
        $bare = str_contains($column, '.')
            ? substr($column, (int) strrpos($column, '.') + 1)
            : $column;

        // ⚠️ And the JSON PATH is rooted at its column, which this did not do.
        //
        // Laravel accepts `update(['values->body' => ...])`. That returned
        // `values->body`, which never matched the guarded key `values` — so a bulk
        // JSON-path write skipped the per-row refusal entirely, and with it the
        // value-conversion pipeline that sanitises rich text (issue #42).
        //
        // `AuditedBuilder` had exactly this defect for exactly this reason and was
        // fixed; the same wrong assumption was sitting in the guard beside it. Two
        // places that must agree about what a column is, and only one of them had
        // been told.
        $bare = explode('->', $bare)[0];

        return trim($bare, '`"[]');
    }

    /**
     * A row this scope writes has to belong to this scope.
     *
     * Silent with no context, matching EnforcesScope: console commands,
     * migrations and the installer legitimately run without one. NULL
     * `site_id` is org-shared and legitimate.
     *
     * @param  array<string, mixed>  $values
     */
    protected function guardScopeKeys(array $values): void
    {
        // The reviewable escape hatch stands BOTH enforcers down, not one.
        if (ScopeWrites::suspended()) {
            return;
        }

        $context = app(Context::class);
        $normalised = [];

        foreach ($values as $column => $value) {
            $normalised[$this->bareColumn((string) $column)] = $value;
        }

        foreach (['org_id' => $context->orgId(), 'site_id' => $context->siteId()] as $column => $current) {
            if (! array_key_exists($column, $normalised)) {
                continue;
            }

            $value = $normalised[$column];

            if ($value === null || $current === null || (int) $value === $current) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Refusing to write %s with [%s] = %s from a context scoped to %s. A scope that only '
                .'filters SELECTs still lets a caller move a row to somebody else, and a mass '
                .'update dispatches no model events at all (ADR-021). Use withoutScopeBecause() if '
                .'this is deliberate.',
                $this->getModel()::class,
                $column,
                (string) $value,
                (string) $current,
            ));
        }
    }
}
