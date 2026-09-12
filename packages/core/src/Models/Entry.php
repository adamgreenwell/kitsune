<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Audit\AuditedBuilder;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Fields\FieldType;
use Kitsune\Core\Fields\FieldTypeRegistry;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\ValueDirection;
use Kitsune\Core\Relations\GuardedBelongsToMany;
use Kitsune\Core\Schema\RevisionWrites;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Contracts\RequiresModelSave;
use RuntimeException;

/**
 * One model for every user-defined entity type (ADR-010).
 *
 * Not a preference — forced. Filament caches a one-model-to-one-Resource map
 * and keys route-model binding and policies off getModel(), which must be
 * constant. Discovering that in month ten would have meant a rewrite.
 *
 * Consequences carried here: type is a discriminator column, values live in
 * JSON, per-type indexes need generated columns, and relations need a real
 * table. title/slug/status/published_at are promoted to real columns because
 * every list view, URL resolution and status filter touches them (ADR-015).
 *
 * @property int $id
 * @property int|null $site_id
 * @property int $org_id
 * @property int $entry_type_id
 * @property string $type_handle
 * @property int|null $author_id
 * @property string|null $slug
 * @property string|null $title
 * @property string $status
 * @property array<string, mixed>|null $values
 * @property array<string, int>|null $promoted_by
 */
#[SiteScoped]
class Entry extends Model implements RequiresModelSave
{
    use EnforcesScope;
    use SoftDeletes;

    /**
     * Mirrors the column default, so the in-memory model is not lying.
     *
     * Without it a freshly created entry has no `status` until it is read
     * back, which made the revision snapshot record NULL for a column the
     * database declares NOT NULL.
     */
    protected $attributes = ['status' => 'draft'];

    /**
     * The columns whose change constitutes a new version.
     *
     * ⚠️ One definition, because there are now TWO recorders — the model
     * events and the builder — and a versioned surface defined twice drifts.
     * `EntryRevision::SNAPSHOT_ATTRIBUTES` says what a revision stores; this
     * says what makes one, and `values` is in the second list only.
     *
     * @var list<string>
     */
    public const VERSIONED_COLUMNS = [...EntryRevision::SNAPSHOT_ATTRIBUTES, 'values'];

    protected $guarded = [];

    protected $casts = [
        'values' => 'array',
        'promoted_by' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // ⚠️ Recorded BEFORE any guard reads a promoted column, and recorded rather
        // than derived. See `recordPromotedProvenance()`.
        static::saving(fn (self $entry) => $entry->recordPromotedProvenance());

        // type_handle is denormalised for routing lookups, so it must never
        // disagree with the type it points at.
        // ⚠️ A SITE change revalidates the relations pointing at this entry.
        //
        // The type veto ignores relations whose source is not visible from
        // here, which is right — otherwise one site could freeze another's
        // records. But that leaves a sequence: move a valid target to site B,
        // change it there to a type site A's field forbids (A's pivot is
        // invisible, so nothing objects), then move it back to A without
        // touching `type_handle`. No pivot guard runs on the final move, and
        // the relation resurfaces with a forbidden target — treated again as
        // the nominated subject.
        //
        // Checked on the way back in, scoped to the entry's own org so this
        // cannot become a way for one org to pin another's rows.
        static::saving(function (self $entry): void {
            if (! $entry->exists || ! $entry->isDirty('site_id')) {
                return;
            }

            $field = EntryRelation::forbidsTypeChange(
                (int) $entry->getKey(),
                (string) $entry->type_handle,
                (int) $entry->org_id,
                visibleOnly: false,
            );

            if ($field !== null) {
                throw new RuntimeException(
                    "Entry {$entry->getKey()} cannot move here: field [{$field}] relates to it and "
                    ."does not accept a [{$entry->type_handle}]. Its relation is invisible from "
                    .'where it is now, so moving it back would resurface a target that field '
                    .'refuses (ADR-020). Detach the relation first.'
                );
            }
        });

        static::saving(function (self $entry): void {
            // ⚠️ `type_handle` too, not only `entry_type_id`.
            //
            // The handle is DENORMALISED and fully mass assignable, and every
            // relational read and write resolves a target's type through it —
            // `guardTargetType()`, `forbidsTypeChange()`,
            // `RelationType::elementValidationRules()` and `scopeOfType()` all
            // read the handle, never the id. So `$entry->update(['type_handle'
            // => 'article'])` reached exactly the state this guard exists to
            // prevent, without the guard running: no restamp, no relation
            // check, and a field configured to accept `patient` went on naming
            // a target that now claims to be an `article`.
            //
            // A forged handle is now overwritten rather than merely refused,
            // because the column is derived: whatever the caller wrote, the
            // type it points at is the truth.
            if (! $entry->isDirty(['entry_type_id', 'type_handle'])) {
                return;
            }

            $entry->type_handle = EntryType::query()
                ->whereKey($entry->entry_type_id)
                ->value('handle') ?? $entry->type_handle;

            // ⚠️ Changing an entry's type can invalidate relations that point
            // AT it, and nothing was checking: the pivot guards only run when
            // a pivot changes. A field configured to accept `person` would go
            // on naming a target that had since become an `article`.
            if ($entry->exists
                && ($field = EntryRelation::forbidsTypeChange(
                    (int) $entry->getKey(),
                    (string) $entry->type_handle,
                    (int) $entry->org_id,
                )) !== null) {
                throw new RuntimeException(
                    "Entry {$entry->getKey()} cannot become a [{$entry->type_handle}]: field "
                    ."[{$field}] relates to it and does not accept that type. Detach the relation "
                    .'first — leaving it would point a configured field at something it refuses, '
                    .'and a subject identifier at the wrong kind of record (ADR-020).'
                );
            }
        });

        // ⚠️ The lock has to be SET by something, and nothing was setting it.
        //
        // ADR-006 says storage locks the moment data exists, and FieldStorage
        // has carried the guard since the first commit — but `is_locked`
        // defaulted to false and only tests ever wrote it. So every field in
        // every install was unlocked while holding content, and the guard
        // that refuses a decimal-to-integer change or a narrowed text field
        // was reachable only by a caller who had remembered to set the flag
        // by hand. A lock nobody arms is a comment.
        static::saved(fn (self $entry) => $entry->lockStorageHoldingData());

        // A revision per saved version, recorded AFTER the write.
        //
        // After, not before: a revision describing a save that then failed is
        // a history of things that never happened. And the entry row is the
        // current version rather than a pointer into history, so revision N
        // is a snapshot of what the entry became — the shape that answers
        // "what did this say last Tuesday" directly.
        // ⚠️ created and updated separately, NOT saved + wasRecentlyCreated.
        // That flag stays true for the lifetime of the instance, so every
        // later save on a freshly created entry recorded another revision —
        // including saves that changed nothing versioned at all.
        // ⚠️ There is deliberately NO `created` revision listener either, for the
        // reason the `updated` one went: it fires after `insertGetId()` has
        // returned and its transaction committed, so a concurrent updater can
        // commit and record version B before the initial version A is written —
        // leaving A newest while the live entry is B. Creation is recorded inside
        // the insert transaction, in `AuditedBuilder::insertGetId()`.

        // ⚠️ There is deliberately NO `updated` revision listener.
        //
        // It fired after `AuditedBuilder` had committed and released its row
        // lock, so two writers interleaved: T1 commits A, T2 commits and records
        // B, then T1 records A — leaving revision A newest while the live entry
        // is B, and a relation write in the same window could contaminate A's
        // snapshot. A version has to be recorded under the lock that made the
        // write atomic.
        //
        // Every update — instance, quiet, or bulk — is therefore recorded inside
        // the builder's transaction. `created` stays here because an insert has
        // no prior state to race with, and the builder covers the quiet case.
    }

    /**
     * Lock every storage row this entry now holds data for.
     *
     * Cheap once it has done its work: the query returns nothing as soon as a
     * type's fields are locked, which is the steady state. Written in bulk so
     * it does not re-enter FieldStorage's own guards, which have nothing to
     * check here — `is_locked` is not a shape attribute.
     *
     * ⚠️ All THREE strategies, not just inline. A promoted field's data is a
     * real column and a relational field's is rows in `entry_relations`, so
     * checking `values` alone would have left a `slug` or a `person` field
     * unlocked forever while holding content — the same emptiness the lock
     * had before anything set it, narrowed rather than fixed.
     */
    private function lockStorageHoldingData(): void
    {
        $candidates = FieldStorage::query()
            ->where('is_locked', false)
            ->whereHas('fields', fn (Builder $query) => $query->where('entry_type_id', $this->entry_type_id))
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $values = $this->values ?? [];

        $holding = $candidates->filter(function (FieldStorage $storage) use ($values): bool {
            return match ($storage->strategy()) {
                StorageStrategy::Promoted => $this->hasValueFor((string) $storage->promotedColumn()),
                StorageStrategy::Relational => $this->related()
                    ->wherePivot('field_storage_id', $storage->getKey())
                    ->exists(),
                StorageStrategy::Inline => $this->hasValueFor(null, $values[$storage->handle] ?? null),
            };
        });

        if ($holding->isEmpty()) {
            return;
        }

        // In bulk, so this does not re-enter FieldStorage's own guards —
        // which have nothing to check here, `is_locked` not being a shape
        // attribute.
        FieldStorage::query()->whereKey($holding->modelKeys())->update(['is_locked' => true]);
    }

    /** An empty string or an empty array is not data any more than null is. */
    private function hasValueFor(?string $column, mixed $value = null): bool
    {
        $value = $column !== null ? $this->getAttribute($column) : $value;

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * ⚠️ Columns whose guards can only run per row.
     *
     * `type_handle` is DERIVED from `entry_type_id`, and every relational read
     * resolves a target's type through it. A bulk write bypassed the restamp
     * and the relation veto both, so a forged handle reached
     * `guardTargetType()`, `forbidsTypeChange()` and the subject queries.
     *
     * @return array<string, string>
     */
    public static function columnsRequiringModelSave(): array
    {
        return [
            'type_handle' => 'it is derived from entry_type_id, and a bulk write skips the restamp that keeps them agreeing.',
            // ⚠️ `values` because the value-conversion pipeline runs in `saving`.
            //
            // `FieldType::toStorage()` is what sanitises rich text, and a bulk write
            // dispatches nothing — so it would store exactly the bytes it was given,
            // `<script>` included (issue #42, field-types.md §6). The model is fully
            // mass assignable, so the only mechanism that holds is refusing the shape
            // that skips the conversion.
            //
            // This does not touch the writes that legitimately set `values` directly:
            // erasure and restore go through an instance save, which runs the
            // pipeline, and `ScopeWrites::suspended()` covers the internal paths.
            'values' => 'every value is converted through its field type on save, and rich text is '
                .'sanitized there — a bulk write dispatches nothing, so it would store what it was '
                .'handed.',
        ];
    }

    /**
     * ⚠️ Every write goes through the audited builder, which is itself a
     * ScopedBuilder — Entry needs the tenancy guards and the audit trail, and
     * a model has only one builder (ADR-020).
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): AuditedBuilder
    {
        return new AuditedBuilder($query, $this);
    }

    /**
     * This entry's outgoing relations, as a revision stores them.
     *
     * ⚠️ ADR-006 has THREE storage strategies and this project has already been
     * burned twice by code that handled two: a subject identifier that answered
     * with null for a relational field, and an erasure that reported success
     * while every link survived. A revision omitting relations was the same
     * omission a third time.
     *
     * Shaped `{field_storage_id: [target ids, in order]}`. Ordered explicitly,
     * because `ordering` is what the author arranged and restoring a set without
     * it would silently reshuffle a list of authors.
     *
     * Rows whose `field_storage_id` is NULL are skipped. That column is
     * `nullOnDelete`, so a null means the field definition is gone — there is no
     * field left to restore them into, and inventing one would be worse than
     * recording the loss.
     *
     * @return array<string, list<int>>
     */
    public function relationState(): array
    {
        if (! $this->exists) {
            return [];
        }

        $state = [];

        $rows = EntryRelation::query()
            ->where('source_entry_id', $this->getKey())
            ->whereNotNull('field_storage_id')
            ->orderBy('field_storage_id')
            ->orderBy('ordering')
            ->orderBy('target_entry_id')
            ->get(['field_storage_id', 'target_entry_id']);

        foreach ($rows as $row) {
            $state[(string) $row->field_storage_id][] = (int) $row->target_entry_id;
        }

        return $state;
    }

    /**
     * Record a revision when a PIVOT write changed this entry's relations.
     *
     * ⚠️ A pivot write fires no `Entry` event, so a relation change filed no
     * version at all — and the newest revision then no longer described the
     * entry, which makes "restore the latest version" silently revert it. Same
     * shape as the bulk-update gap, one storage strategy along.
     *
     * @param  array<string, list<int>>  $before
     */
    public function recordRevisionForRelationChange(array $before): bool
    {
        if (RevisionWrites::suspended() || $this->relationState() === $before) {
            return false;
        }

        $this->recordRevision();

        return true;
    }

    /**
     * The values a revision would record, as they currently stand.
     *
     * ⚠️ RAW originals rather than accessor values. `published_at` casts to a
     * Carbon instance, and comparing two of those with `!==` compares object
     * identity — every restore would look like a change. The raw values are
     * scalars and strings, so a plain array comparison means what it says.
     *
     * ⚠️ Except for a JSON column, where the raw string is NOT the value.
     *
     * MySQL stores and returns `values` in its own key order, so a no-op restore
     * read `{"b":2,"a":1}` before and wrote `{"a":1,"b":2}` after — the same data,
     * a different serialisation, and a duplicate revision filed for a restore that
     * changed nothing. Found by the engine matrix: SQLite, PostgreSQL and MariaDB
     * all preserved the order and agreed, so a single-engine run would have shipped
     * it. And a duplicate is not merely untidy — history is bounded, so it costs a
     * genuine older version off the end.
     *
     * Nothing in Kitsune may depend on JSON key order (field order comes from
     * `fields.ordering`), which is exactly why the comparison must not either.
     *
     * @return array<string, mixed>
     */
    private function versionedState(): array
    {
        $state = [];

        foreach (self::VERSIONED_COLUMNS as $column) {
            $raw = $this->getRawOriginal($column);

            // Only the columns actually cast to a structure are decoded — a title
            // that happens to look like JSON is a title.
            $state[$column] = ($this->getCasts()[$column] ?? null) === 'array' && is_string($raw)
                ? self::keySorted((array) json_decode($raw, true))
                : $raw;
        }

        return $state;
    }

    /**
     * The same data with every level's keys in one order.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function keySorted(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::keySorted($nested);
            }
        }

        return $value;
    }

    /**
     * Record a revision for a write that dispatched no model events.
     *
     * ⚠️ Bulk builder writes reach neither `created` nor `updated`, and
     * `Entry::query()->update(['status' => 'published'])` is a write this
     * project deliberately ALLOWS and audits (see `AuditLogTest`). So a bulk
     * publish moved a versioned column with no version recorded: history had a
     * gap, and the newest revision no longer described the entry — which makes
     * "restore the latest version" silently revert the bulk change.
     *
     * Recorded rather than refused, to match how the same seam is treated for
     * auditing: the rows already exist and can be named, so there is nothing to
     * fail closed about. `RevisionWrites::suspend()` is the reviewable opt-out.
     *
     * `$before` is the raw pre-write row, so the comparison is raw-to-raw and
     * cannot go wrong on a cast — a `values` array compared against its own
     * JSON encoding would differ on every write.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordRevisionForEventlessWrite(array $before, array $after): bool
    {
        if (RevisionWrites::suspended()) {
            return false;
        }

        foreach (self::VERSIONED_COLUMNS as $column) {
            if (($before[$column] ?? null) !== ($after[$column] ?? null)) {
                $this->recordRevision();

                return true;
            }
        }

        return false;
    }

    /*
     * ═══════════════════════════════════════════════════════════════════════════════════════════════
     * PER-BLOCK TEXT DIRECTION (ADR-029)
     *
     * ⚠️ SIX HUNDRED LINES OF DOM WALKING ON A MODEL, AND THE REASON IS THE ONLY THING THAT JUSTIFIES
     * IT. This is the fifth home for this pass and every earlier one was reachable by a plugin, which
     * review established four times over:
     *
     *   private on `RichTextType`              a module's own Control::RichText type got nothing
     *   `BaseFieldType::toStorage()`           overridable — MultiSelectType and RelationType already
     *                                          override it — and a protected hook there is extension
     *                                          surface that breaks a subclass at load time
     *   `final class BlockDirection`           autoloadable, public statics: new public API before v1.2
     *   `trait StampsBlockDirection`           every method private, and still an autoloadable symbol a
     *                                          plugin can `use` and wrap
     *
     * PHP has no package-private. Private methods on a model are what this codebase already settled on,
     * in `conversionLostSomething()`'s docblock, as *"the first version a plugin cannot reach at all"* —
     * and ADR-029's whole argument is that a cross-cutting rule must be unforgettable rather than
     * documented, which a reachable seam is not.
     *
     * The cost is stated rather than hidden: this model is long, and the pass is not about entries. What
     * buys it is that nothing outside this class can call, override, extend or bind to any of it.
     * ═══════════════════════════════════════════════════════════════════════════════════════════════
     */

    /**
     * The only values that establish a direction.
     *
     * ⚠️ PRIVATE, LIKE THE TWO TAG LISTS BELOW. Review pointed out that a public constant is three
     * more symbols on the pre-v1.2 extension surface — a plugin can depend on a list this
     * implementation has to stay free to change, and `CONTRIBUTING.md` lists new public API before
     * v1.2 among the things that will not merge. They are used only by private methods in this class.
     *
     * ⚠️ `dir` IS ALLOWED AND ITS VALUE WAS NEVER CHECKED, which review found: `<p dir="">` and
     * `<p dir="banana">` survive sanitising, and an attribute that establishes nothing was still
     * enough to block the stamp. A malformed value is not a choice to respect.
     */
    private const DIRECTIONS = ['ltr', 'rtl', 'auto'];

    /**
     * The tags that hold a run of text, and therefore have a direction of their own.
     *
     * ⚠️ `ul`, `ol` and `figure` are absent on purpose: they contain blocks rather than text, so a
     * direction on them would be inherited by children that should each resolve their own. `li` and
     * `figcaption` are the text-bearing halves of those pairs and are here.
     *
     * ⚠️ `br` and the inline tags are absent for the opposite reason — `dir` on a fragment of a
     * sentence resolves from a fragment, which is how you get one clause of a paragraph pointing the
     * wrong way.
     */
    private const BLOCK_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figcaption',
    ];

    /**
     * Tags that are blocks at the top level, so a run beside one is a separate run.
     *
     * ⚠️ `ul`, `ol` and `figure` are here although they are NOT in `BLOCK_TAGS`: they carry no
     * direction of their own — their children each resolve one — but they are still blocks, so text
     * before and after a list is two runs rather than one.
     */
    private const CONTAINER_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figure', 'figcaption', 'ul', 'ol',
    ];

    /**
     * Allowed tags that hold FLOW content, so a loose run inside one can be wrapped in a `<p>`.
     *
     * ⚠️ THE SET IS DECIDED BY WHAT A `<p>` MAY LEGALLY SIT INSIDE, not by which tags hold text —
     * which is why it is not `CONTAINER_TAGS` and not `BLOCK_TAGS`. `ul` and `ol` take only `li`,
     * `p` and the headings take phrasing content, and `pre` takes phrasing content AND is
     * whitespace-significant. Wrapping in any of those would fix a direction by producing markup no
     * browser should be handed.
     *
     * ⚠️ Named separately from the two lists above BECAUSE it is nearly one of them, and a reader
     * who assumed it was would reintroduce exactly the over-reach this avoids.
     */
    private const FLOW_CONTAINER_TAGS = ['figure', 'blockquote', 'li', 'figcaption'];

    /**
     * Allowed tags whose only valid child is `li`, so loose text inside one cannot be wrapped.
     *
     * ⚠️ NAMED AS A THIRD SET because it is the complement of `FLOW_CONTAINER_TAGS` within
     * `CONTAINER_TAGS` for a reason that is easy to lose: these two hold blocks, so they are boundaries
     * between runs, and they take no `<p>`, so the run pass must skip them. That left malformed input
     * with loose text here handled by nothing at all, which is what review found. They are the only
     * allowed tags in that position, so the set is closed by the allowlist rather than by judgement.
     */
    private const LIST_TAGS = ['ul', 'ol'];

    /**
     * The tags that may join a loose run, because a `<p>` may legally contain them.
     *
     * ⚠️ AN ALLOWLIST, AND REVIEW FOUND WHY IT HAD TO BE. The run pass treated anything that is not a
     * known CONTAINER as inline, which is safe for `RichTextType`'s output — every element there is in
     * `ALLOWED_TAGS` — and wrong for a module type returning `Control::RichText`, whose output this pass
     * now also handles. Measured on a module path:
     *
     *   <div>مرحبا</div>      ->  <p dir="auto"><div>مرحبا</div></p>
     *   <h1>مرحبا</h1>        ->  <p dir="auto"><h1>مرحبا</h1></p>
     *
     * A browser reparsing that ejects the block from the paragraph, which leaves the element that
     * actually bears the text with no direction at all — so the invalid markup and the missed guarantee
     * are the same bug.
     *
     * ⚠️ "NOT A KNOWN BLOCK" IS NOT A DEFINITION OF INLINE, which is the lesson: an allowlist answers
     * for markup nobody enumerated, and a denylist cannot. It is the same argument `sanitize()`'s own
     * allowlist rests on, applied to content model rather than to safety.
     *
     * These are exactly the phrasing members of `RichTextType::ALLOWED_TAGS`, so for core's output the
     * behaviour is unchanged by construction rather than by testing.
     */
    private const PHRASING_TAGS = ['a', 'br', 'code', 'em', 's', 'strong', 'u', 'img'];

    /**
     * Every tag this implementation can stamp a direction ON, and therefore every tag it must be able
     * to take one back OFF.
     *
     * ⚠️ DERIVED FROM THE TWO STAMPING SETS rather than written out, because review found the fifth
     * face of this defect in the gap between them: the previous round taught the pass to stamp
     * `LIST_TAGS` and left the yielding pass reading `BLOCK_TAGS` alone, so a generated list direction
     * survived an author's later `dir="rtl"` on an ancestor and overrode it. The `<p>` beside it yielded
     * correctly, which is the tell — the rule was present and its coverage was not.
     *
     * A union cannot drift from its parts. Adding a third stamping set adds it here by construction.
     */
    private const STAMPED_TAGS = [...self::BLOCK_TAGS, ...self::LIST_TAGS];

    /**
     * Give every text-bearing block its own `dir="auto"`, so each resolves from its own content.
     *
     * ⚠️ A SINGLE `dir` ON THE FIELD IS WORSE THAN NONE, which is why this is per block. `dir="auto"`
     * on the editor resolves from the FIRST strong directional character in the whole document, so a
     * body that opens in English and continues in Arabic renders every Arabic paragraph
     * left-to-right — and looks handled, which is the failure mode issue #39 exists to stop.
     *
     * ⚠️ ON THE WAY TO STORAGE, not at render time. A value arriving from the API, a seeder or an
     * import gets the same treatment as one typed into the panel, and the stored bytes and the
     * displayed bytes cannot drift apart — which is the kind of difference that survives for years
     * because both halves look right on their own.
     *
     * ⚠️ ONLY WHEN A VALID DIRECTION IS ABSENT. `dir` is in ALLOWED_ATTRIBUTES, so an author who
     * wrote `dir="rtl"` has said something more specific than `auto` — a paragraph of Arabic opening
     * with a Latin brand name, where `auto` would resolve from the brand name and be wrong. This
     * fills a gap rather than overruling a decision.
     *
     * ⚠️ BUT `hasAttribute()` ALONE WAS THE WRONG TEST, which review found. `<p dir="">` and
     * `<p dir="banana">` survive sanitising — `dir` is allowed and its VALUE was never checked — and
     * an attribute that establishes no direction blocked the stamp while doing nothing itself, so an
     * Arabic block still inherited the chrome. Only `ltr`, `rtl` and `auto` are directions; anything
     * else is replaced rather than respected, because it expresses no choice to respect.
     *
     * ⚠️ AND A VALUE WITH NO BLOCK IN IT GOT NOTHING AT ALL, which review also found. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes the sanitiser deliberately preserves — it refuses to wrap
     * loose text, because reshaping content that arrived as a bare string is data loss of its own —
     * so this loop found no block and added no direction. A top-level run is a paragraph in
     * everything but markup, and it is wrapped in one so that it has somewhere to carry a direction.
     * That is a reshape, and it is confined to the case where the alternative is no direction at all.
     */
    private static function stampedInto(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The same wrapper-and-meta parsing aid `sanitize()` documents at length: the meta declares
        // UTF-8, and the wrapper stops libxml wrapping loose top-level text in an implied `<p>`.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        /*
         * ⚠️ A GENERATED `auto` MUST NOT BLOCK A LATER ANCESTOR CHOICE, and this pass runs FIRST for
         * that reason — review found the fourth face of the same defect. The first save stamps
         * `dir="auto"` on a block with nothing in force; the author then declares `dir="rtl"` on the
         * figure and saves the stored HTML again, and the caption's `auto` — which this implementation
         * wrote, not them — reads as a choice to respect and resolves from `ACME` for ever.
         *
         * `auto` is the default here and a fixed direction is a decision. That is the same sentence as
         * the two rules below: `auto` does not inherit to a child, and `auto` does not suppress a
         * wrapper's own. This is it applied to a block that already carries one.
         *
         * ⚠️ IT CANNOT BE DISTINGUISHED FROM AN AUTHOR'S `auto`, and the cost is stated rather than
         * hidden: somebody who deliberately writes `dir="auto"` on one block inside a `dir="rtl"`
         * container loses it. Marking generated attributes would need an attribute outside
         * ALLOWED_ATTRIBUTES, which is §6's published contract — a bigger change than the defect.
         *
         * ⚠️ AND IT RUNS BEFORE THE STAMPING PASS, so that pass's "stop at the nearest direction" is
         * true when it looks: after this, no generated `auto` stands between a block and a fixed
         * ancestor. The walk here skips `auto` ancestors for the same reason it is removing one — each
         * of them may be generated too, so none of them may block.
         */
        foreach (self::STAMPED_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                if (self::ownDirection($element) === 'auto' && self::fixedAncestorDirection($element) !== null) {
                    $element->removeAttribute('dir');
                }
            }
        }

        foreach (self::BLOCK_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                // No `instanceof` guard: `getElementsByTagName()` yields elements by definition,
                // and static analysis correctly calls the check dead.
                if (self::ownDirection($element) !== null) {
                    continue;
                }

                /*
                 * ⚠️ `auto` FILLS A GAP AND MUST NOT CLOSE A CHOICE ONE LEVEL UP, which review found
                 * — the third time this exact mistake has appeared on this branch, each time one step
                 * further from where it was fixed. `<figure dir="rtl"><figcaption>ACME مرحبا</…>` had
                 * `auto` stamped on the caption, and `auto` resolves from `ACME`: an explicit `rtl`
                 * was overridden by a default. Inheritance was giving the right answer.
                 *
                 * ⚠️ A FIXED ancestor is inherited; an `auto` one is not, and that asymmetry is the
                 * whole rule. `ltr` and `rtl` are decisions and reach every descendant that states
                 * none. `auto` is not a direction — it is an instruction to resolve from content —
                 * so a child under it must resolve from ITS OWN content, which is `auto` again and is
                 * exactly what issue #39 is about.
                 */
                /*
                 * ⚠️ AND THE FIX FOR THAT WROTE THE ANCESTOR'S DIRECTION ONTO THE CHILD, which review
                 * then found is a one-way door. A materialised `dir="rtl"` is indistinguishable from an
                 * author's, so editing the FIGURE to `ltr` and saving again left the caption `rtl`
                 * forever — measured, and no later save can undo it, because the value now looks like a
                 * choice to respect.
                 *
                 * So a child under a FIXED ancestor gets nothing at all. Inheritance was already giving
                 * the right answer, exactly as it was for the wrapper one rule along, and the answer is
                 * again to write nothing rather than to write the right thing.
                 */
                if (self::nearestDirection($element) !== null) {
                    continue;
                }

                $element->setAttribute('dir', 'auto');
            }
        }

        /*
         * ⚠️ AND A LIST HOLDING LOOSE TEXT, which no pass above can reach and no wrapper may fix.
         * Review found it: `<ul>مرحبا<li>English</li></ul>` is malformed and `sanitize()` preserves it
         * deliberately — §6 parses rather than pattern-matches, and an importer or the API can submit
         * it. `ul` and `ol` are absent from `BLOCK_TAGS` because they contain blocks rather than text,
         * and absent from `FLOW_CONTAINER_TAGS` because a `<p>` inside a list is markup no browser
         * should be handed. So the Arabic run was stored with no direction at all while the `li` beside
         * it got one, and it inherited the page.
         *
         * ⚠️ THE CONTAINER TAKES THE DIRECTION AND ITS CHILDREN KEEP THEIRS, which is what makes this
         * correct rather than a trade. `dir="auto"` resolves from the element's text EXCLUDING any
         * descendant that carries a `dir` of its own, and every `li` carries one by the time this runs —
         * so the list's `auto` reads the loose text only, and each item still resolves its own.
         *
         * ⚠️ AND WITH MORE THAN ONE LOOSE RUN THE FIRST DECIDES FOR ALL OF THEM, which is the limit of
         * what a container-level direction can do and is stated rather than hidden. One attribute
         * resolves once. Giving each run its own would need a wrapper, and the only element valid here
         * is `li` — which would turn a stray sentence into a list item and add a bullet to it. So this
         * is never worse than inheriting the page and often better, and it reshapes nothing.
         *
         * ⚠️ AND ONLY WHERE NOTHING IS IN FORCE, the same rule as both passes above: a list inside
         * `<blockquote dir="rtl">` already gives its loose text the author's direction, and stamping
         * `auto` over that would replace a decision with a default.
         */
        foreach (self::LIST_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $list) {
                if (self::ownDirection($list) !== null || self::nearestDirection($list) !== null) {
                    continue;
                }

                if (! self::carriesText(self::looseChildren($list))) {
                    continue;
                }

                $list->setAttribute('dir', 'auto');
            }
        }

        /*
         * ⚠️ THE WRAPPER'S CHILDREN, NOT THE DOCUMENT'S, and the first version of this returned an
         * empty string by getting that wrong. Under `LIBXML_HTML_NOIMPLIED` the charset `<meta>`
         * becomes the document ELEMENT and the wrapper `<div>` is not among `$document->childNodes`
         * at all — measured: `childNodes` holds one node, the meta, while
         * `getElementsByTagName('p')` finds both paragraphs.
         *
         * `sanitize()` never notices because `clean()` UNWRAPS the div — `div` is not an allowed tag
         * — which lifts its children to document level on the way past. This method does not clean,
         * so it has to address the wrapper itself.
         *
         * ⚠️ THE FIRST `div` IS RELIABLY THE WRAPPER, and that is an argument rather than a guess:
         * this runs on output that has already been through `sanitize()`, and `div` is not in
         * ALLOWED_TAGS, so no `div` can have survived from the input.
         */
        $wrapper = $document->getElementsByTagName('div')->item(0);

        if ($wrapper === null) {
            return $html;
        }

        /*
         * ⚠️ ANYTHING THE INPUT PUSHED OUTSIDE THE WRAPPER IS PUT BACK, and review found this pass
         * losing it silently. A stray `</div>` — ordinary in pasted markup — closes the parsing wrapper
         * early, so every node after it is a sibling of the wrapper rather than a child. This method
         * serialises the wrapper's CHILDREN, so `hello</div>world` was stored as `<p dir="auto">hello</p>`
         * and `world` was gone. Measured, along with `<p>one</p></div><p>two</p>` losing its second
         * paragraph.
         *
         * ⚠️ IT IS THE SAME BUG `sanitize()` RECORDS HAVING HAD, and it came back because this pass
         * stopped running only on that method's output. `RichTextType` canonicalises first — `div` is
         * not in ALLOWED_TAGS, so no `div` can survive — and the comment above still says so, which was
         * true of the only caller there used to be. A module type whose control is `Control::RichText`
         * may emit anything, and the one in the test returns its input unchanged.
         *
         * ⚠️ RESTORING THE INVARIANT RATHER THAN TEACHING THE SERIALISER, because every pass below
         * assumes the wrapper holds the document: `wrapLooseRuns()` is given it as the container to
         * insert into, and a loose run outside it would be wrapped by nothing. Moving the escapees back
         * in — in document order, so nothing is reordered — makes that assumption true again instead of
         * making each pass defend itself.
         *
         * ⚠️ THE WRAPPER'S PARENT, NOT THE DOCUMENT'S CHILDREN, and my first version used the latter and
         * changed nothing. Under `LIBXML_HTML_NOIMPLIED` the charset `<meta>` becomes the document
         * ELEMENT, so the wrapper and everything that escaped it are both children of the meta — which
         * this file's serialisation docblock already says, one method along.
         */
        $outside = $wrapper->parentNode;

        if ($outside !== null) {
            foreach (iterator_to_array($outside->childNodes) as $node) {
                if ($node !== $wrapper) {
                    $wrapper->appendChild($node);
                }
            }
        }

        /*
         * ⚠️ AND AN ELEMENT THIS VOCABULARY DOES NOT KNOW STILL GETS A DIRECTION, which is the other
         * half of the same finding. Leaving a module's `<div>` or `<h1>` unwrapped stops the invalid
         * markup; it does not give the text inside it a direction, and ADR-029's guarantee is about the
         * direction rather than about the markup.
         *
         * ⚠️ STAMPED RATHER THAN WRAPPED, because `dir` is a GLOBAL attribute — valid on any element —
         * while a `<p>` is valid only in specific places. So the one thing that is safe to do to markup
         * this pass cannot parse the content model of is exactly the thing needed.
         *
         * ⚠️ IT BEARS TEXT DIRECTLY OR IT IS SKIPPED, so this is per block rather than per element:
         * `<section><div>x</div></section>` stamps the `div`, which holds the text, and leaves the
         * `section` alone. A wrapper carrying no text of its own resolves from nothing.
         *
         * ⚠️ AND FOR CORE'S OUTPUT THIS BRANCH NEVER FIRES, by construction rather than by testing:
         * every element `RichTextType` emits is in `ALLOWED_TAGS`, and every one of those is in one of
         * the three lists above. Only a module type's output reaches here, which is the blast radius the
         * finding is about.
         */
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            $tag = strtolower($element->nodeName);

            if ($element === $wrapper || $tag === 'meta' || self::isKnownTag($tag)) {
                continue;
            }

            if (self::ownDirection($element) !== null || self::nearestDirection($element) !== null) {
                continue;
            }

            if (self::isInnermostTextBlock($element)) {
                $element->setAttribute('dir', 'auto');
            }
        }

        self::wrapLooseRuns($document, $wrapper);

        /*
         * ⚠️ AND INSIDE EVERY FLOW-CONTENT CONTAINER, which the outer pass cannot reach. Review found
         * it twice, and the second time because the first fix was too narrow.
         *
         * `<figure><strong>مرحبا</strong><figcaption>English</figcaption></figure>` is valid rich
         * text, and the Arabic run got no direction at all while the caption did — so it inherited
         * the page. Restricting the pass to `figure` then left the identical defect in the other
         * three: `<blockquote>English<p>عربي</p>עברית</blockquote>` gave the trailing Hebrew run no
         * wrapper, so it resolved from the blockquote's own `auto` — which reads `English` first.
         *
         * ⚠️ THESE FOUR AND NO MORE, because the set is decided by what a `<p>` may legally sit
         * inside rather than by which tags happen to hold text. A wrapper is only correct where flow
         * content is:
         *
         *   figure, blockquote, li, figcaption   flow content — a `<p>` is valid
         *   ul, ol                               only `li`; a `<p>` here is markup no browser
         *                                        should be handed, and the sanitiser produces no
         *                                        loose text there from valid input
         *   p, h2, h3, h4                        phrasing content only, and each already carries
         *                                        its own direction from the block pass above
         *   pre                                  phrasing only, AND whitespace-significant, so
         *                                        inserting an element would change the content
         */
        foreach (self::FLOW_CONTAINER_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $container) {
                self::wrapLooseRuns($document, $container);
            }
        }

        return self::serialize($wrapper);
    }

    /**
     * Wrap top-level text and inline runs in a paragraph, so they can carry a direction.
     *
     * ⚠️ A VALUE NEED NOT CONTAIN A BLOCK, which review found and I had assumed away. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes `sanitize()` deliberately preserves, and neither has any
     * element that a direction could sit on — so the per-block guarantee did not reach them and they
     * inherited the chrome, which for API and import input is the ordinary case rather than an edge
     * one.
     *
     * ⚠️ THIS RESHAPES, AND THAT IS THE TRADE. `sanitize()` refuses to wrap loose text because
     * reshaping content that arrived as a bare string is data loss of its own — the reason its
     * parsing wrapper exists at all. The alternative here is no direction at all for those values,
     * and a top-level run is a paragraph in everything except markup. So the reshape is confined to
     * exactly the nodes that have nowhere else to carry one, and adjacent runs are collected into ONE
     * paragraph rather than one each, because `a <strong>b</strong> c` is one sentence.
     */
    private static function wrapLooseRuns(DOMDocument $document, DOMNode $wrapper): void
    {
        /*
         * ⚠️ WHITESPACE CONTINUES A RUN BUT CANNOT START ONE, and getting only the second half right
         * corrupted content. The first version skipped every whitespace-only text node, so the space
         * in `<strong>hello</strong> <em>world</em>` was left OUTSIDE the paragraph while both
         * elements moved into it — stored as `<p><strong>hello</strong><em>world</em></p> ` and
         * rendered as `helloworld`. A sanitiser that silently joins two words is worse than one that
         * misses an attribute. Found by review.
         *
         * The distinction is position, not content: whitespace BETWEEN blocks is formatting, and
         * whitespace INSIDE a run is a word boundary. So a run in progress takes it, a run not yet
         * started does not, and a run closing at a block drops whatever trailing whitespace only
         * separated it from that block.
         *
         * ⚠️ No closure holds `$current` by reference, deliberately: the first version used one and
         * static analysis could not follow the type through it, which is a signal about the shape
         * rather than about the analyser.
         *
         */
        $runs = [];
        $current = [];

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            /*
             * ⚠️ NOT A KNOWN PHRASING TAG MAKES IT A BOUNDARY, and an unknown element used to join the
             * run instead. See `PHRASING_TAGS`: a module's `<div>` or `<h1>` was wrapped in a `<p>`,
             * producing markup a browser takes apart.
             */
            $isBlock = $child instanceof DOMElement
                && ! in_array(strtolower($child->nodeName), self::PHRASING_TAGS, true);

            if ($isBlock) {
                $runs[] = $current;
                $current = [];

                continue;
            }

            // Whitespace before any content is formatting between blocks, not a word boundary.
            if ($current === [] && self::isWhitespaceNode($child)) {
                continue;
            }

            $current[] = $child;
        }

        $runs[] = $current;

        $wrappable = [];

        foreach ($runs as $run) {
            // Trailing whitespace only separated this run from the block that closed it.
            while ($run !== [] && self::isWhitespaceNode($run[count($run) - 1])) {
                array_pop($run);
            }

            /*
             * ⚠️ A RUN WITH NO TEXT HAS NO DIRECTION, so wrapping it would add markup for nothing.
             * The case that matters is `<figure><img><figcaption>…` — the commonest figure there is
             * — where recursing into the figure first wrapped the image in a paragraph. `dir="auto"`
             * on an image resolves from no characters at all.
             */
            if ($run !== [] && self::carriesText($run)) {
                $wrappable[] = $run;
            }
        }

        /*
         * ⚠️ TWO QUESTIONS, NOT ONE, and collapsing them put the original defect straight back. They
         * look like the same thing and are not:
         *
         *   `$carries`  does this container hold ANY direction, so it can serve ONE run itself?
         *   `$fixed`    is a FIXED direction in force, so a wrapper needs no `auto` of its own?
         *
         * A container whose own direction is `auto` answers YES to the first and NO to the second —
         * `auto` resolves from the container's whole content, which is right for one run and wrong for
         * three. Using one value for both made `<blockquote>English<p>عربي</p>עברית</blockquote>` give
         * its wrappers nothing, so all three inherited the blockquote's `auto` and resolved from
         * `English`: the round-two finding, reintroduced by the round-four fix.
         *
         * ⚠️ `$fixed` LOOKS THROUGH TO AN ANCESTOR because a container no longer carries a materialised
         * copy of one. `<figure dir="rtl"><figcaption>A<p>x</p>B</figcaption>` leaves the caption
         * undirected so an ancestor edit can still reach it, and a wrapper inside must inherit that
         * `rtl` rather than stamp `auto` over it.
         */
        $carries = self::ownDirection($wrapper);
        $fixed = self::fixedDirectionForChildren($wrapper);

        /*
         * ⚠️ A WRAPPER IS ONLY CORRECT WHERE NOTHING ELSE CAN CARRY THE DIRECTION, and wrapping
         * unconditionally broke documents that were already right. Review's second finding widened
         * this pass from `figure` to every flow-content container — and `blockquote`, `li` and
         * `figcaption` are BLOCKS, so each already carries a direction of its own. Wrapping their
         * single run put a `<p>` inside every `<li>` in every existing document: correct direction,
         * gratuitous markup, and a visible change to how every list renders, since a paragraph in a
         * list item brings block margins with it.
         *
         * The container carries ONE direction, so it can serve exactly one run. One run and a
         * direction to hold it needs nothing:
         *
         *   <li dir="auto">عربي</li>                      the li's own auto reads عربي — correct
         *   <li dir="auto">English<p>عربي</p>עברית</li>   the li's auto reads English, so the
         *                                                 HEBREW run inherits English's direction
         *   <figure>عربي<figcaption>…                     figure is not a block and carries none,
         *                                                 so even one run has nowhere to resolve
         *
         * ⚠️ AND `figure dir="rtl"` IS NOW LEFT ALONE ENTIRELY, which is a better answer to review's
         * other finding than propagating was. The run inherits `rtl` because inheritance was already
         * right; the defect was inserting a wrapper that broke it. Propagation still earns its place
         * for the multi-run case, where wrappers are unavoidable and must each carry the author's
         * choice rather than re-deriving one.
         */
        /*
         * ⚠️ ANY DIRECTION IN FORCE, OWN OR INHERITED. `$carries` alone was not enough once a container
         * stopped materialising its ancestor's: `<figure dir="rtl"><figcaption>ACME مرحبا</figcaption>`
         * leaves the caption undirected, so a skip keyed on the caption's OWN direction read null and
         * wrapped a single run in a paragraph for nothing — the round-two finding, reintroduced from the
         * other side. One run and a direction reaching it needs no wrapper, wherever that direction
         * comes from.
         */
        if (count($wrappable) < 2 && ($carries ?? $fixed) !== null) {
            return;
        }

        foreach ($wrappable as $run) {
            $paragraph = $document->createElement('p');

            /*
             * ⚠️ ONLY WHERE NOTHING ELSE CAN CARRY IT, which is the same rule as the block pass, and
             * making the two identical is what removed the last inconsistency. A wrapper under a
             * container that establishes a direction inherits it, exactly as the author's own `<p>`
             * beside it does — so stamping the value here would materialise it on half the children and
             * not the other half, and re-close the ancestor edit for that half.
             */
            if ($fixed === null) {
                $paragraph->setAttribute('dir', 'auto');
            }

            $wrapper->insertBefore($paragraph, $run[0]);

            foreach ($run as $node) {
                $paragraph->appendChild($node);
            }
        }
    }

    /**
     * The nearest FIXED direction above this element, looking through any `auto` on the way.
     *
     * ⚠️ DIFFERENT FROM `nearestDirection()` ON PURPOSE, and the difference is the point rather than an
     * inconsistency. That one stops at the nearest direction of any kind, because for STAMPING a block
     * the effective direction is what matters and an `auto` above means "resolve from content". This one
     * is asked whether a generated `auto` should yield, and every `auto` between may be generated too —
     * so none of them may block, and the walk passes through them.
     *
     * `<figure dir="rtl"><figcaption dir="auto"><p dir="auto">` needs that: both `auto`s were written by
     * this implementation, and stopping at the first would leave the inner one standing whichever order
     * the elements happened to be visited in.
     */
    private static function fixedAncestorDirection(DOMNode $element): ?string
    {
        for ($ancestor = $element->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            $direction = self::ownDirection($ancestor);

            if ($direction !== null && $direction !== 'auto') {
                return $direction;
            }
        }

        return null;
    }

    /**
     * The FIXED direction in force on this container's children, or null when none is.
     *
     * ⚠️ THE CONTAINER'S OWN `auto` BLOCKS ITS ANCESTOR'S, which review found the call site getting
     * wrong — and it is `nearestDirection()`'s rule applied one level lower rather than a new one. That
     * method stops at the nearest direction of any kind and reports `auto` as nothing, because `auto`
     * means "resolve from content" and nothing above it reaches the child. A container's own `auto` is
     * the nearest direction to its children, so it answers the same way.
     *
     * Measured before the fix: `<blockquote dir="rtl"><figure dir="auto">English<p>عربي</p>עברית</figure>`
     * read past the figure's `auto` to the blockquote's `rtl`, so both generated wrappers were left
     * undirected — and they then inherited the figure's `auto`, which resolves from the figure's whole
     * content and reads `English` first. The Hebrew run rendered left-to-right.
     *
     * ⚠️ DISTINCT FROM `$carries` AT THE CALL SITE, which is the distinction the caller's own docblock
     * insists on: `auto` on a container is enough to serve ONE run and not enough to serve three.
     */
    private static function fixedDirectionForChildren(DOMNode $container): ?string
    {
        $own = self::ownDirection($container);

        if ($own !== null) {
            return $own === 'auto' ? null : $own;
        }

        return self::nearestDirection($container);
    }

    /**
     * The direction this element inherits from its nearest directed ancestor, or null when it has none.
     *
     * ⚠️ THE NEAREST ONE WINS AND THE WALK STOPS THERE, because that is what inheritance does.
     * Continuing past a directed ancestor to find a fixed grandparent would give a child a direction
     * the browser would never have given it.
     *
     * ⚠️ `auto` NEEDS NO SPECIAL CASE, AND THE FIRST VERSION OF THIS HAD ONE. It mapped an `auto`
     * ancestor to null so the caller would default to `auto` — reasoning that `auto` is an instruction
     * to resolve from content rather than a direction to inherit. That reasoning is right and the
     * branch was still dead: both readings stamp `auto`, so it was a conditional asserting a
     * distinction the code could not act on. Found by reverting it and watching every test still pass.
     *
     * ⚠️ The parsing wrapper `div` carries no `dir`, so a top-level block walks to the top and gets
     * `auto` from the caller — the behaviour that existed before inheritance was considered at all.
     */
    private static function nearestDirection(DOMNode $element): ?string
    {
        for ($ancestor = $element->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            $direction = self::ownDirection($ancestor);

            if ($direction !== null) {
                // ⚠️ `auto` STOPS THE WALK AND REPORTS NOTHING. It is the nearest direction, so nothing
                // above it reaches the child — and it is not a direction to inherit, because it means
                // "resolve from content" and the child's content is its own. Both halves are needed:
                // walking past it would give a child a direction the browser never would, and returning
                // it would make the caller read `auto` as a decision.
                return $direction === 'auto' ? null : $direction;
            }
        }

        return null;
    }

    /**
     * The direction `$container` establishes for its own children, or null when it establishes none.
     *
     * ⚠️ NULL AND `auto` ARE DIFFERENT ANSWERS, and collapsing them is what made the first version of
     * this wrong. "This container resolves its children's direction" and "fall back to auto" look
     * alike at the point of use and mean opposite things at the point of decision: a container that
     * establishes a direction can serve a run without a wrapper, and one that does not cannot.
     *
     * ⚠️ THE SAME THREE VALUES AS THE BLOCK PASS, and the same reason `hasAttribute()` was not
     * enough there: `dir=""` and `dir="banana"` survive sanitising, because `dir` is allowed and its
     * value was never checked. An attribute that establishes no direction must read as null here, or
     * it suppresses a wrapper while doing nothing itself.
     */
    private static function ownDirection(DOMNode $container): ?string
    {
        if (! $container instanceof DOMElement) {
            return null;
        }

        $direction = strtolower($container->getAttribute('dir'));

        return in_array($direction, self::DIRECTIONS, true) ? $direction : null;
    }

    /** Whether this tag is one the rich text vocabulary names, and therefore one the passes above cover. */
    private static function isKnownTag(string $tag): bool
    {
        return in_array($tag, self::CONTAINER_TAGS, true)
            || in_array($tag, self::LIST_TAGS, true)
            || in_array($tag, self::PHRASING_TAGS, true);
    }

    /**
     * Whether this element is the innermost block holding text, and so the one a direction belongs on.
     *
     * ⚠️ "HOLDS TEXT DIRECTLY" WAS TOO NARROW, which review found: `<div><strong>مرحبا</strong></div>`
     * keeps its words inside a phrasing element, so the `div` had no direct text, the `strong` was
     * skipped as phrasing, and the value went to storage undirected. Measured — the value came back
     * byte-for-byte, inheriting the page.
     *
     * ⚠️ AND `textContent` ALONE IS TOO WIDE, which is why the second half exists: it is true for every
     * ancestor up to the document, so a wrapper with no words of its own would resolve a direction from
     * its descendants' — the single-`dir`-on-the-field failure this whole pass exists to avoid.
     *
     * Between them: text somewhere inside, and no descendant that is itself a block. So
     * `<section><div><strong>x</strong></div></section>` stamps the `div` and leaves the `section`, and
     * `<div><p>x</p></div>` stamps the `p` — which the block pass already did — and leaves the `div`.
     */
    private static function isInnermostTextBlock(DOMElement $element): bool
    {
        if (trim($element->textContent) === '') {
            return false;
        }

        foreach ($element->getElementsByTagName('*') as $descendant) {
            if (! in_array(strtolower($descendant->nodeName), self::PHRASING_TAGS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The children of a list that are not list items, which is where malformed input puts loose text.
     *
     * ⚠️ NOT ONLY TEXT NODES: `<ul><strong>مرحبا</strong><li>…` puts an ELEMENT in that position, and
     * its content is exactly what a direction has to resolve from. The question is the node's place in
     * the list rather than its type, so the filter is "not an `li`".
     *
     * @return list<DOMNode>
     */
    private static function looseChildren(DOMNode $list): array
    {
        $loose = [];

        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                continue;
            }

            $loose[] = $child;
        }

        return $loose;
    }

    /**
     * Whether a run contains any text for a direction to resolve from.
     *
     * ⚠️ `textContent` RATHER THAN THE NODE TYPE, because the text may be nested: `<strong>مرحبا</strong>`
     * is an element whose content is what `dir="auto"` would read. An `<img>` has none, and neither
     * does a run of `<br>`.
     *
     * @param  list<DOMNode>  $run
     */
    private static function carriesText(array $run): bool
    {
        foreach ($run as $node) {
            if (trim($node->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Whether this node is text that carries no content of its own. */
    private static function isWhitespaceNode(DOMNode $node): bool
    {
        return $node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '';
    }

    /**
     * The document back as HTML, without the parsing aids this class adds.
     *
     * ⚠️ EXTRACTED RATHER THAN COPIED. `withBlockDirection()` needs exactly the same skip — the
     * charset `<meta>` is something these methods PREPEND, not content — and a second copy of that
     * rule is a second place for it to be wrong. The `div` wrapper needs no skip here: `sanitize()`
     * unwraps it because `div` is not an allowed tag, and `withBlockDirection()` runs on output that
     * has already been through that.
     */
    private static function serialize(DOMNode $parent): string
    {
        $document = $parent instanceof DOMDocument ? $parent : $parent->ownerDocument;

        if ($document === null) {
            return '';
        }

        $out = '';

        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'meta') {
                continue;
            }

            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * Stamp the direction the CONTROL requires inside the value, whatever type produced it.
     *
     * ⚠️ THIS IS ADR-029'S GUARANTEE, AND ITS THIRD HOME — each earlier one found by review, and the
     * sequence is the same shrinking mistake `conversionLostSomething()` recorded four steps of
     * before this change removed it — see the loss check below, which no longer needs one.
     *
     * It began as a private method on `RichTextType`, so a module registering its own type returning
     * `Control::RichText` got no direction at all — the exact thing that ADR says is inexpressible.
     * Moving it to `BaseFieldType::toStorage()` made it control-driven and left it OVERRIDABLE:
     * `MultiSelectType` and `RelationType` already override that method, a module may too, and a module
     * implementing `FieldType` directly never reaches the base class. A protected hook on the
     * plugin-facing base class was also new extension surface — a subclass with a same-named method
     * fails to load — which CONTRIBUTING forbids before v1.2.
     *
     * Here is the first seam a field type cannot reach: `convertFieldValuesForWrite()` is where every
     * value that lands in the database is converted, this method is private on a model, and the
     * decision is read from the control rather than from the type.
     *
     * ⚠️ KEYED ON `ValueDirection`, NOT ON THE TYPE, which is what makes it the ADR's guarantee rather
     * than a special case for one class. `Control::direction()` is the closed mapping and `PerBlock`'s
     * own docblock already says direction is needed INSIDE the value; this makes that happen.
     *
     * ⚠️ AND IT MAPS OVER A MULTI-VALUE FIELD. `toStorage()` returns a list for `cardinality` above
     * one, and stamping a list as though it were a document would produce nothing at all.
     */
    private function withPerBlockDirection(FieldType $type, mixed $stored): mixed
    {
        if ($type->control()->direction() !== ValueDirection::PerBlock) {
            return $stored;
        }

        if (is_array($stored)) {
            return array_map(
                static fn (mixed $one): mixed => is_string($one) ? self::stampedInto($one) : $one,
                $stored,
            );
        }

        return is_string($stored) ? self::stampedInto($stored) : $stored;
    }

    private function recordRevision(): void
    {
        // getAttribute, not only(): `only()` returns nothing for an attribute
        // the instance never set, so an entry created without an explicit
        // status snapshotted NULL and hit the revision's NOT NULL constraint.
        $snapshot = [];

        foreach (EntryRevision::SNAPSHOT_ATTRIBUTES as $attribute) {
            $snapshot[$attribute] = $this->getAttribute($attribute);
        }

        $this->revisions()->create([
            ...$snapshot,
            'values' => $this->values,
            // The third storage strategy. Kept OUT of the snapshot list because
            // `entries` has no such column and `restoreRevision()` fills the
            // entry from that list — a stray key there would try to write a
            // column that does not exist.
            'relation_state' => $this->relationState(),
            // ⚠️ The pre-sanitization originals, also OUT of the snapshot list and for
            // a sharper reason than `relation_state`: `restoreRevision()` fills the
            // entry from that list, so an original reachable through it would put
            // unsanitized HTML back into `entries.values` — the one thing
            // field-types.md §6 forbids. Its own column is a place a restore does not
            // read, and that is why it is one.
            'unsanitized_values' => $this->retainedOriginals === [] ? null : $this->retainedOriginals,
            'author_id' => $this->author_id,
        ]);

        // Cleared HERE rather than left to expire. Carrying them forward would attach
        // this save's originals to a later revision — a false record of what an author
        // wrote, which is worse than no record.
        $this->retainedOriginals = [];

        $this->pruneRevisions();
    }

    /**
     * Keep the most recent KEEP_REVISIONS, drop the rest.
     *
     * Deleted rather than archived: an entry edited a thousand times would
     * otherwise carry a thousand full JSON snapshots, and ADR-027's floor is
     * one vCPU and a SQLite file. The bound is applied on write so the cost
     * cannot accumulate quietly between maintenance windows.
     */
    private function pruneRevisions(): void
    {
        $keep = $this->revisions()
            ->orderByDesc('id')
            ->limit(self::KEEP_REVISIONS)
            ->pluck('id');

        $this->revisions()->whereKeyNot($keep)->delete();
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopeOfType(Builder $query, string $handle): Builder
    {
        return $query->where('type_handle', $handle);
    }

    /**
     * @param  Builder<Entry>  $query
     * @return Builder<Entry>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /** Org-shared entries are not publicly addressable, so they carry no slug. */
    public function isShared(): bool
    {
        return $this->site_id === null;
    }

    /** @return BelongsTo<EntryType, $this> */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The value identifying this entry's data subject, if its type names one.
     *
     * Null means unanswerable rather than "no subject": the type nominated
     * nothing, and `EntryType::withoutSubjectIdentifier()` is the list of
     * types in that state (ADR-020).
     */
    public function subjectValue(): mixed
    {
        $storage = $this->entryType?->subjectStorage();

        if ($storage === null) {
            return null;
        }

        // ⚠️ All THREE storage strategies, not two.
        //
        // A relational subject lives in `entry_relations` and a promoted one
        // in its own column on `entries` — ADR-020 names the relation case
        // explicitly ("its email or its `person` relation"), and `slug` is
        // promoted. Reading only the JSON returned null for both, which is
        // indistinguishable from "no subject nominated".
        return match ($storage->strategy()) {
            StorageStrategy::Relational => $this->relatedIdsFor($storage)->all(),
            StorageStrategy::Promoted => $this->getAttribute((string) $storage->promotedColumn()),
            StorageStrategy::Inline => $this->values[$storage->handle] ?? null,
        };
    }

    /**
     * Entries of one type whose subject identifier matches.
     *
     * Scoped to a single type on purpose. `values` is JSON and the key
     * differs per type, so a cross-type query would be a union whose shape
     * depends on the data — subject-access EXPORT is v1.1 tooling, and this
     * is the v1.0 primitive it will be built from.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereSubjectIs(Builder $query, EntryType $type, mixed $identifier): Builder
    {
        $storage = $type->subjectStorage();

        if ($storage === null) {
            // Fail closed. Returning everything of this type would answer a
            // subject request with every person in it.
            return $query->whereRaw('1 = 0');
        }

        // ⚠️ A NULL identifier matches nothing, and has to be refused before
        // a strategy is chosen. Both the promoted and inline branches compile
        // `= null` to `IS NULL`, so a malformed subject-access request
        // returned every entry whose nominated field is null or absent — a
        // batch of records belonging to no identified subject, from the one
        // query that must never over-answer. `subjectValue()` already defines
        // null as unanswerable; this makes the query agree.
        if ($identifier === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('entry_type_id', $type->getKey());

        // Where the value lives decides where the predicate goes: a pivot
        // row, a real column, or a JSON path.
        return match ($storage->strategy()) {
            StorageStrategy::Relational => $query->whereExists(function ($pivot) use ($storage, $identifier): void {
                $pivot->selectRaw('1')
                    ->from('entry_relations')
                    // ⚠️ The target's VISIBILITY, in this same statement.
                    //
                    // `related()` hides an out-of-scope target through
                    // Entry's own SiteScope, but a raw EXISTS does not — and
                    // `attach($id)` never validates the related model, so the
                    // ordinary path can create a pivot row pointing anywhere.
                    //
                    // This was a separate `exists()` check before the builder
                    // ran, which is a gap rather than a guard: move the target
                    // to another site between the two and the EXISTS still
                    // matched on the pivot alone, returning a source record
                    // whose subject `related()` would no longer show. As a
                    // subquery there is no window, and no self-alias either —
                    // nothing inside it references the outer `entries`.
                    ->whereIn(
                        'entry_relations.target_entry_id',
                        static::query()->select('id')->whereKey($identifier)->toBase(),
                    )
                    ->whereColumn('entry_relations.source_entry_id', 'entries.id')
                    // ⚠️ org_id, matching what `related()` enforces through
                    // withPivotValue(). Without it a hostile pivot row
                    // carrying another org's org_id counts as this entry's
                    // subject — the exact row EntrySchemaTest already writes
                    // to prove the relationship refuses it (ADR-021).
                    ->whereColumn('entry_relations.org_id', 'entries.org_id')
                    ->where('entry_relations.field_storage_id', $storage->getKey())
                    ->where('entry_relations.target_entry_id', $identifier);
            }),
            StorageStrategy::Promoted => $query->where((string) $storage->promotedColumn(), $identifier),
            StorageStrategy::Inline => $query->where('values->'.$storage->handle, $identifier),
        };
    }

    /**
     * Erase one field everywhere it survives, revision history included.
     *
     * ADR-020: erasure has to reach revisions, because article revision 4
     * still holds the name just erased. Immutable revisions were rejected for
     * exactly this reason — they make the right to erasure unimplementable.
     *
     * Replaces in place rather than deleting the revision, so the history of
     * WHAT CHANGED WHEN survives an erasure of WHAT IT SAID. Returns how many
     * rows were rewritten, because an erasure that silently reached nothing
     * is indistinguishable from one that worked.
     */
    public function redactField(string $handle, mixed $replacement = null): int
    {
        // ⚠️ ONE transaction, with this entry LOCKED, and everything the erasure
        // depends on resolved INSIDE it.
        //
        // Two separate races lived here. The live change committed before the
        // revisions were loaded and rewritten, so a restore starting in that
        // interval could read an as-yet-unredacted revision and write the erased
        // value back onto the already-redacted entry. And the storages and this
        // model's own attributes were resolved BEFORE the lock, so a stale
        // instance could skip a live value another writer had just committed,
        // redact only the new revision, and return a positive count while the live
        // entry still held the data.
        //
        // The lock is the same row `restoreRevision()` takes first, so the two
        // serialise: whichever starts second sees the other's completed work
        // rather than half of it. An erasure that reports success has to mean it
        // (ADR-020).
        return (int) DB::transaction(function () use ($handle, $replacement): int {
            self::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->get();

            // Under the lock, so the attributes being swept are the committed
            // ones rather than whatever this instance was loaded with.
            $this->refresh();

            // ⚠️ Resolved through THIS ENTRY'S TYPE, not by handle alone.
            //
            // `field_storage` is UNIQUE (org_id, handle) and the model is
            // #[Unscoped], so a bare handle lookup can return ANOTHER ORG's row —
            // and then a relational erasure detaches on the wrong
            // field_storage_id, reports 0, and leaves every link intact. Two
            // orgs defining `email` is the ordinary case, not a contrived one.
            //
            // ⚠️ And EVERY match, not the first one. UNIQUE (org_id, handle)
            // lets a GLOBAL row and the org's own row share a handle, and
            // `Field::guardStorageOwnership()` permits both to attach to the same
            // type — so a bare `first()` picked one arbitrarily. If it took the
            // relational row it detached the pivots and returned, leaving
            // `values['contact']` and every revision copy of it intact while
            // reporting success; if it took the inline row the pivot survived
            // instead. Either way personal data remained and the caller's success
            // check passed, and WHICH depended on row order — so the same request
            // erased different data on different engines.
            //
            // Elsewhere this shadowing is resolved with explicit precedence
            // (`IdentifyEntryType`, `EntryType::visibleFor()`). Precedence is
            // wrong here: erasure has to reach the data, and the shadowed row
            // holds data too. So every match is erased and the counts are summed.
            // ⚠️ NOT filtered by entry type at all, and the two narrower versions of
            // this lookup were both wrong.
            //
            // `entries.entry_type_id` is mutable, and erasure has to reach history
            // (ADR-020). Filtering on the CURRENT type found no A-era field storage
            // after a move from A to B, so `redactStorage()` fell through to the
            // inline path, returned 0, and left the live promoted column or relation
            // AND every historical snapshot untouched while reporting success.
            //
            // ⚠️ Filtering on the types the REVISIONS record was the second attempt
            // and it fails for a subtler reason: history is bounded. Once an entry
            // accumulates `KEEP_REVISIONS` B-era versions, `pruneRevisions()` drops
            // the last A-era revision — and the type set silently forgets A while
            // A's promoted column or relation is still live on the row. A prunable
            // store cannot be the durable record of every former type.
            //
            // So the question is asked of the storage rows themselves, which ARE
            // durable: any row with this handle that this org could have used. That
            // over-approximates, and over-approximating is the safe direction here —
            // a storage row this entry never used has nothing of this entry's to
            // erase, so it costs a query and erases nothing. Under-approximating
            // leaves personal data behind and reports success.
            //
            // ⚠️ The over-approximation is safe for TWO of the three strategies,
            // and I claimed it was safe for all three. It is not.
            //
            // Inline data lives at `values->{handle}`, so every row with this handle
            // names the same JSON key — the handle alone decides the location.
            // Relational data lives in pivots carrying that storage's id and this
            // entry's, so a storage the entry never used has no such rows.
            //
            // A PROMOTED column is shared across handles. `SlugType::promotedColumn()`
            // returns `slug` for the type, not for the handle, so two differently
            // handled slug fields both project to `entries.slug`. Erasing a storage
            // this entry never used would then clear the slug that belongs to the
            // field it DOES use — real data loss, dressed as a privacy operation.
            // Hence `ownsPromotedColumn()`.
            //
            // The `org_id` filter is hygiene on top of that — consulting another
            // org's definitions is not this entry's business (ADR-021), and a global
            // row (`org_id` null) genuinely is shared by every org. It is stated
            // that way rather than as "the boundary", because removing it does not
            // let an erasure reach another org's data and claiming otherwise would
            // misdirect whoever audits this next.
            $storages = FieldStorage::query()
                ->where('handle', $handle)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('org_id')
                    ->orWhere('org_id', $this->org_id))
                ->get()
                // ⚠️ And PROMOTED storage is filtered again, because for that one
                // strategy over-approximating is NOT safe. See below.
                ->filter(fn (FieldStorage $candidate): bool => $candidate->strategy() !== StorageStrategy::Promoted
                    || $this->ownsPromotedColumn($candidate))
                ->values();

            if ($storages->count() > 1) {
                return (int) $storages->sum(
                    fn (FieldStorage $storage): int => $this->redactStorage($handle, $storage, $replacement),
                );
            }

            return $this->redactStorage($handle, $storages->first(), $replacement);
        });
    }

    /**
     * Note which field storage wrote each promoted column on this entry.
     *
     * ⚠️ RECORDED, not derived, and three attempts at deriving it each failed in a
     * different way. A promoted column is named for its TYPE — every slug-typed
     * field projects to `entries.slug` — so the column itself cannot say whose value
     * it holds, and erasure has to know: clearing `slug` for a handle that never
     * wrote it destroys another field's data while reporting success (ADR-020).
     *
     *   the entry's CURRENT type      misses a field the entry has moved off
     *   the types its REVISIONS name  vanish when the bounded history is pruned
     *   any storage with the handle   grants a shared column to a field that
     *                                 never touched it
     *
     * None of those is a bug in the rule. The information was simply never written
     * down anywhere durable, and it is known at exactly one moment: when a field on
     * the entry's own type writes the column.
     *
     * Only a DIRTY column is attributed, so an unrelated save does not reassign
     * provenance, and nothing is recorded when no field on the current type projects
     * to the column — a value written with no owner has no owner to record, and
     * claiming one would be worse than admitting none.
     */
    /**
     * The columns some registered field type projects into.
     *
     * ⚠️ Derived from the REGISTRY, which answers without touching the database
     * because `promotedColumn()` is named for the type rather than stored per field.
     *
     * @return list<string>
     */
    private static function promotableColumns(FieldTypeRegistry $registry): array
    {
        $columns = [];

        foreach ($registry->all() as $candidate) {
            if (($column = $candidate->promotedColumn()) !== null) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** Whether this write touches any column a field type could have promoted into. */
    private function touchesPromotedColumn(FieldTypeRegistry $registry): bool
    {
        foreach (self::promotableColumns($registry) as $column) {
            if ($this->isDirty($column)) {
                return true;
            }
        }

        return false;
    }

    private function recordPromotedProvenance(): void
    {
        $registry = app(FieldTypeRegistry::class);

        // ⚠️ THE DIRTY CHECK BELONGS HERE, NOT IN THE LOOP, and putting it in the loop
        // meant it was not a guard at all.
        //
        // `isDirty($column)` runs per field, so reaching it had already cost the type
        // lookup and a fields query — on every save of every entry, including one that
        // moved only `title`. Two queries against the 1 vCPU / SQLite floor in ADR-027,
        // for a column no field storage can write.
        //
        // `title`, `status` and `published_at` are PLATFORM columns (field-types.md §2):
        // they are not user-definable, so nothing projects into them and there is no
        // provenance to record. Only a column some registered type promotes can have any,
        // and the registry knows that list without a query.
        if (! $this->touchesPromotedColumn($registry)) {
            return;
        }

        $type = EntryType::query()->whereKey($this->entry_type_id)->first();

        if ($type === null) {
            return;
        }

        $provenance = $this->promoted_by ?? [];
        $before = $provenance;

        foreach ($type->fields()->with('fieldStorage')->get() as $field) {
            $storage = $field->fieldStorage;

            if ($storage === null || ! $registry->has((string) $storage->type)) {
                continue;
            }

            $column = $storage->promotedColumn();

            if ($column === null || ! $this->isDirty($column)) {
                continue;
            }

            $provenance[$column] = $storage->getKey();
        }

        if ($provenance !== $before) {
            $this->promoted_by = $provenance;
        }
    }

    /**
     * Whether a promoted storage's shared column holds THIS entry's data for it.
     *
     * ⚠️ Needed because a promoted column is named for its TYPE, not its handle:
     * every slug-typed storage projects to `entries.slug`, whatever it is called. So
     * "which storage does this entry's slug belong to?" is not answerable from the
     * column, and erasing the wrong one destroys a live value.
     *
     * The rule is ownership by the entry's CURRENT type, with one deliberate
     * exception: if nothing on the current type projects to that column, the value
     * is ORPHANED — left behind by a type the entry has since moved off — and the
     * storage being erased is the only thing that could have written it. Erasing it
     * then is correct, and refusing to would recreate the unreachable-data failure
     * that ADR-020 forbids.
     *
     * Both cases matter, and they pull in opposite directions:
     *
     *   moved to a type WITH its own slug field    the new field owns the column,
     *                                             so the old handle must not touch it
     *   moved to a type WITHOUT one                nobody owns it, so the old handle
     *                                             is exactly what should clear it
     *
     * ⚠️ No new schema, and no reliance on revisions. The entry's current type and
     * the field storage rows are both durable, which is what the previous two
     * attempts at this lookup each got wrong in turn.
     */
    private function ownsPromotedColumn(FieldStorage $storage): bool
    {
        $column = $storage->promotedColumn();

        if ($column === null) {
            return true;
        }

        $wroteIt = ($this->promoted_by ?? [])[$column] ?? null;

        // ⚠️ FAIL CLOSED when nothing recorded it. An unattributed value is one this
        // erasure cannot prove is its to clear, and guessing is what the three
        // derived rules did. Every value written through a field on the entry's type
        // is attributed, so this is the case where somebody set the column directly.
        return $wroteIt !== null && (int) $wroteIt === (int) $storage->getKey();
    }

    /**
     * Erase one storage definition's data wherever its strategy puts it.
     *
     * The handle is passed rather than read off the storage row, because the
     * JSON path below still has to run when NO storage row matched — an
     * unclassified or already-deleted definition must not make erasure a no-op.
     */
    private function redactStorage(string $handle, ?FieldStorage $storage, mixed $replacement): int
    {

        // Erasure has to reach wherever the value actually lives.
        //
        // A relational field's data is rows in `entry_relations`, with
        // nothing to replace in place — so erasure IS the detach. A promoted
        // field is a real column on `entries`. Both fell through to the JSON
        // path, found no key, and reported 0 while the data survived.
        if ($storage?->strategy() === StorageStrategy::Relational) {
            // ⚠️ NOT through `related()`. That relation carries
            // `withPivotValue('org_id', ...)`, which is right for reading and
            // wrong for erasing: a pivot row written with a different org_id
            // — reachable by overriding it in `attach()`'s pivot attributes —
            // does not match the predicate, so the detach skipped it and
            // reported 0 while the subject link survived.
            //
            // Erasure has to reach the row wherever it is, so it goes
            // straight at the pivot by the two keys that define it. Scoping
            // is the reader's protection; it must not become the attacker's.
            // ⚠️ withoutRevisions, for the reason the promoted and inline paths
            // use it: an erasure is not an authored version, and filing one would
            // add a row to the very history it is clearing. The relational path
            // did not need this until the relation builder started recording —
            // adding a recorder added a place erasure has to stand it down.
            $detached = (int) RevisionWrites::suspend(fn (): int => EntryRelation::query()
                ->where('source_entry_id', $this->getKey())
                ->where('field_storage_id', $storage->getKey())
                ->delete());

            // ⚠️ And the REVISIONS, which is the half a new column just broke.
            //
            // `relation_state` records the target ids at each version, so
            // deleting the live pivots and returning left every erased id sitting
            // in history — and a restore would recreate the relation, undoing the
            // erasure. ADR-020 requires erasure to reach revisions, and adding a
            // place where relations are stored added a place erasure has to sweep.
            return $detached + $this->redactRelationHistory($storage);
        }

        if ($storage?->strategy() === StorageStrategy::Promoted) {
            // ⚠️ The declared column, NOT the handle. `field_storage`
            // accepts any valid handle for a `slug` field, so one called
            // `public_slug` still writes `entries.slug` — and reading the
            // handle read a column that does not exist, then reported a
            // successful erasure having erased nothing.
            $column = (string) $storage->promotedColumn();

            $rewritten = 0;

            if ($this->getAttribute($column) !== $replacement) {
                // withoutRevisions, because an erasure is not an authored
                // version — filing the redacted state as a new revision would
                // add a row to the very history it is clearing.
                $this->withoutRevisions(function (self $entry) use ($column, $replacement): void {
                    $entry->setAttribute($column, $replacement);
                    $entry->save();
                });

                $rewritten++;
            }

            // Revisions snapshot the promoted columns too, so the sweep has to
            // reach them there as well — by the same column name, for the same
            // reason.
            //
            // ⚠️ `redactColumn`, stated rather than inferred. This dispatched
            // on strategy correctly and then called a method that decided
            // between column and JSON key by testing the name against the
            // snapshot list — so an INLINE field handled `status` was erased as
            // though it were promoted. See EntryRevision::redactColumn().
            foreach ($this->revisions()->get() as $revision) {
                $rewritten += $revision->redactColumn($column, $replacement) ? 1 : 0;
            }

            return $rewritten;
        }

        $rewritten = 0;

        $values = $this->values ?? [];

        if (array_key_exists($handle, $values)) {
            $this->withoutRevisions(function (self $entry) use ($values, $handle, $replacement): void {
                $entry->values = [...$values, $handle => $replacement];
                $entry->save();
            });

            $rewritten++;
        }

        // The handle, into `values`, whatever the handle happens to be called.
        foreach ($this->revisions()->get() as $revision) {
            $rewritten += $revision->redactValue($handle, $replacement) ? 1 : 0;
        }

        return $rewritten;
    }

    /**
     * Remove a storage definition's targets from every revision's snapshot.
     *
     * Returns how many revisions were rewritten, so the caller's count still
     * distinguishes an erasure that reached something from one that silently
     * matched nothing.
     *
     * ⚠️ The key is kept and set to NULL, and dropping it was wrong.
     *
     * Three states have to be distinguishable, and dropping the key collapsed two
     * of them:
     *
     *   a list  — this field had exactly these targets at that version
     *   `null`  — this field's history was erased; the version says nothing
     *   absent  — this field had no relations at that version
     *
     * An empty list and an absent key are STATEMENTS, and `replaceRelations()`
     * acts on them by clearing the field. So dropping the key made an erased
     * field indistinguishable from one that never had relations — and restoring a
     * redacted revision then deleted a replacement relation added after the
     * erasure, which is the opposite of what erasure is for. `null` says
     * "unknown", and a restore leaves an unknown field alone.
     */
    private function redactRelationHistory(FieldStorage $storage): int
    {
        $key = (string) $storage->getKey();
        $rewritten = 0;

        foreach ($this->revisions()->get() as $revision) {
            $state = $revision->relation_state;

            // A key already null has been erased before; there is nothing left
            // to sweep and rewriting it would inflate the count.
            if ($state === null || ($state[$key] ?? null) === null) {
                continue;
            }

            $state[$key] = null;

            $revision->relation_state = $state;
            $revision->save();

            $rewritten++;
        }

        return $rewritten;
    }

    /** @return Collection<int, int> */
    private function relatedIdsFor(FieldStorage $storage): Collection
    {
        return $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->pluck('entries.id');
    }

    /**
     * The entries this one relates to through one field, in the author's order.
     *
     * ⚠️ ORDERED, which `relatedIdsFor()` above is not. That one answers "is anything
     * still attached" for the erasure and cardinality guards, where order is irrelevant.
     * A relation PICKER is different: an author arranges related entries and the
     * arrangement is the content — `entry_relations.ordering` exists for it, and reading
     * without it hands back a set that reshuffles on every page load.
     *
     * ⚠️ Public because a form needs it. The admin cannot hydrate a relation control from
     * a private method, and the alternative — a second query written in the panel — is a
     * second place for the field-storage constraint to be forgotten.
     *
     * @return list<int>
     */
    public function relatedIdsForField(FieldStorage $storage): array
    {
        return $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->orderBy('entry_relations.ordering')
            // `id` as the tiebreaker, so two rows sharing an ordering do not swap places
            // between reads. Every row defaults to ordering 0, so this is the common case
            // rather than a corner of it.
            ->orderBy('entry_relations.id')
            ->pluck('entries.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Replaces the entries related through one field, keeping the given order.
     *
     * ⚠️ SCOPED TO ONE FIELD STORAGE, and this is the property the whole method rests on.
     * An entry relates through several fields at once — `authors` and `tags` both live in
     * `entry_relations` — so a sync that ignored `field_storage_id` would silently detach
     * every other field's relations while appearing to save one. Measured before relying
     * on it: `related()->wherePivot('field_storage_id', X)->sync(...)` leaves rows carrying
     * a different storage id untouched.
     *
     * ⚠️ `ordering` is the POSITION IN THE GIVEN ARRAY, not a column the caller supplies.
     * The author's arrangement is what the control returns, so deriving it here is the only
     * way it cannot disagree with what they saw.
     *
     * ⚠️ It goes through `related()` rather than the pivot table, which is what gets the
     * org stamp, the serialised write, the cardinality check and the relation revision —
     * all of which `GuardedBelongsToMany` supplies and a raw insert would skip.
     *
     * @param  list<int|string>  $ids
     */
    public function syncFieldRelations(FieldStorage $storage, array $ids): void
    {
        $payload = [];
        $position = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            // ⚠️ Duplicates collapse rather than throwing. A select can hand back the same
            // entry twice, and two rows pointing at one target is a state nothing else in
            // the schema expects — while refusing the save would lose the author's other
            // edits over a mistake the UI let them make.
            if ($id <= 0 || isset($payload[$id])) {
                continue;
            }

            $payload[$id] = [
                'field_storage_id' => $storage->getKey(),
                'ordering' => $position++,
            ];
        }

        $this->related()
            ->wherePivot('field_storage_id', $storage->getKey())
            ->sync($payload);
    }

    /**
     * How many revisions an entry keeps.
     *
     * The decision log lists "revision storage growth — full-JSON snapshots
     * get expensive; consider diffs" as an open question, and it is still
     * open. Retention is the honest interim answer: a bound that is applied
     * rather than a cost that accumulates silently while the question waits.
     * Diffs remain the better fix and would raise this number, not remove it.
     */
    public const KEEP_REVISIONS = 50;

    /**
     * Pre-conversion values awaiting the revision that will record them.
     *
     * ⚠️ Transient, and cleared by the recorder rather than left to expire. A
     * surviving entry here would attach one write's originals to a later, unrelated
     * revision — a false record of what an author wrote, which is worse than none.
     *
     * @var array<string, mixed>
     */
    private array $retainedOriginals = [];

    /**
     * Take over another instance's pending originals.
     *
     * ⚠️ Needed because the instance whose values were CONVERTED is not always the
     * instance that RECORDS. `AuditedBuilder` records inside the write transaction
     * and reloads the row to do it — deliberately, so the snapshot is persisted state
     * — and that reload is a different object.
     *
     * The source is cleared, so a hand-off cannot record the same originals twice.
     */
    public function carryRetainedOriginalsFrom(self $source): void
    {
        $this->retainedOriginals = $source->retainedOriginals;
        $source->retainedOriginals = [];
    }

    /**
     * Every value being written, put through its field type's `toStorage()`.
     *
     * ⚠️ Called from the BUILDER, not from a `saving` listener, and that was the
     * defect in the first version of this pipeline.
     *
     * `saveQuietly()`, `createQuietly()`, `updateQuietly()` and anything inside
     * `withoutEvents()` suppress model events while still reaching the builder — so
     * a `rich_text` payload containing a `<script>` tag went in unchanged and was
     * recorded unsanitized in the revision as well. My own commit message said "the
     * guard is where the write is" while the guard sat in an event, which is not
     * where the write is. This project has now found that shape ten times, and this
     * is the first time I have been the one to add it.
     *
     * ⚠️ The short-circuit is structural rather than a check: only columns actually
     * present in `$values` are converted, so a save that moves `title` or `site_id`
     * resolves no schema at all. The first version claimed that optimisation in a
     * comment and did not implement it — it queried the type and its fields on every
     * save, which on the 1 vCPU / SQLite floor (ADR-027) is several queries added to
     * every write for nothing.
     *
     * @param  array<string, mixed>  $values  Raw column values, as the builder has them.
     * @return array<string, mixed>
     */
    public function convertFieldValuesForWrite(array $values): array
    {
        $this->retainedOriginals = [];

        $registry = app(FieldTypeRegistry::class);

        // ⚠️ THE SHORT-CIRCUIT, before any query, and it has to come first to be one.
        //
        // The convertible columns are `values` plus whatever the registered types
        // promote to — which the REGISTRY knows without touching the database, because
        // `promotedColumn()` is named for the type. So a write carrying neither can be
        // dismissed here, and a save that moves only `title`, `status` or `site_id`
        // resolves no schema at all.
        //
        // The first version put its dirty check after resolving the type and its
        // fields, which is to say it did not have one. On the 1 vCPU / SQLite floor
        // (ADR-027) that was several queries on every write, for nothing.
        //
        // ⚠️ Shares `promotableColumns()` with `recordPromotedProvenance()` rather than
        // deriving the list again. That listener made the identical mistake — a dirty
        // check downstream of the queries it should avoid — and it was written on a
        // different branch, so the lesson did not travel. Two copies of this list would
        // also drift the moment a type starts promoting a column.
        $convertible = ['values', ...self::promotableColumns($registry)];

        // ⚠️ A TYPE CHANGE is a conversion trigger, because the type decides what the
        // stored bytes MEAN.
        //
        // `values` is keyed by handle and unknown keys pass through untouched, so an
        // entry can hold `<script>` under a key its current type does not declare —
        // nothing converts it, because no field claims it. Move the entry to a type
        // where that key IS `rich_text` and the value becomes rich text having never
        // met the sanitiser. `entry_type_id` is explicitly mutable (ADR-010), so this
        // is a supported operation and not an edge case.
        $convertible[] = 'entry_type_id';

        if (array_intersect(array_keys($values), $convertible) === []) {
            return $values;
        }

        $typeId = $values['entry_type_id'] ?? $this->entry_type_id;

        // ⚠️ Falsy rather than `=== null`: the docblock types this column non-null, so
        // a strict null check is dead code to static analysis — but a fresh instance
        // may genuinely not have set it yet, and an id of 0 is not a type either.
        if (! $typeId) {
            return $values;
        }

        $type = EntryType::query()->whereKey($typeId)->first();

        if ($type === null) {
            // The foreign key's job to report, not this one's.
            return $values;
        }

        // ⚠️ `values` reaches the builder ALREADY ENCODED on an instance save: the
        // array cast runs in `setAttribute()`, long before this. Decoding and
        // re-encoding in the shape it arrived is what keeps this from double-encoding
        // — the same trap the guarded builders hit with `newModelInstance()`.
        $encoded = array_key_exists('values', $values) && is_string($values['values']);
        $inline = match (true) {
            ! array_key_exists('values', $values) => null,
            is_string($values['values']) => json_decode($values['values'], true),
            is_array($values['values']) => $values['values'],
            default => null,
        };

        // ⚠️ On a type change that carries no `values` of its own, the STORED values
        // are converted against the destination type. Without this the trigger above
        // would resolve the new schema and then find nothing to apply it to.
        //
        // Only for a row that exists: on an insert there is nothing stored yet, and
        // seeding from the instance would write a key the caller never sent.
        $retyping = $this->exists
            && array_key_exists('entry_type_id', $values)
            && (int) $values['entry_type_id'] !== (int) $this->getRawOriginal('entry_type_id');

        if ($inline === null && $retyping) {
            $inline = $this->values ?? [];
            // Written back in the encoded shape, because that is what the column takes
            // and nothing else in this write is carrying it.
            $encoded = true;
        }

        foreach ($type->fields()->with('fieldStorage')->get() as $field) {
            $storage = $field->fieldStorage;

            if ($storage === null || ! $registry->has((string) $storage->type)) {
                continue;
            }

            $fieldType = $registry->get((string) $storage->type);
            $config = new FieldConfig($storage, $field, $this);
            $handle = (string) $storage->handle;

            if ($storage->strategy() === StorageStrategy::Inline) {
                // Absent is not null: a key the write does not carry must stay as it
                // is, not be overwritten with a converted null.
                if (is_array($inline) && array_key_exists($handle, $inline)) {
                    $submitted = $inline[$handle];

                    /*
                     * ⚠️ CONVERTED FIRST, STAMPED AFTER, and the loss check below is asked about the
                     * CONVERTED value rather than the stamped one. Direction is not part of the
                     * conversion — it is applied to the result of one — so a check that saw it would be
                     * answering about an attribute this model added a line earlier.
                     */
                    $converted = $fieldType->toStorage($submitted, $config);
                    $inline[$handle] = $this->withPerBlockDirection($fieldType, $converted);

                    /*
                     * Kept only when the type says its conversion is lossy AND the conversion took
                     * something — a value that survived unchanged has no original worth storing in
                     * a column erasure has to sweep.
                     *
                     * ⚠️ THE TYPE ANSWERS THE SECOND HALF NOW, where this used to compare bytes
                     * itself. `rich_text` stamps `dir="auto"` on each block (issue #39), so its
                     * conversion ADDS as well as removes and a byte comparison was true on every
                     * save — retaining an original identical to the input but for an attribute the
                     * type had just added. Only the type knows which part of its own conversion is
                     * lossy, which is the same argument `retainsOriginal()` is asked for.
                     *
                     * ⚠️ ASKED OF A CORE CLASS, NOT OF THE TYPE, and that is the API freeze
                     * talking. Declaring it on `FieldType` broadens the extension API before v1.2,
                     * which CONTRIBUTING lists among the things that will not merge; putting it on
                     * `BaseFieldType` with an `@internal` tag is no better, because a tag is not a
                     * visibility and a plugin subclass with a same-named method still collides.
                     * As a `final` class of its own it was still autoloadable, so a plugin could
                     * bind to it and the intended v1.2 removal would become a compatibility break.
                     * A private method on this model is the first version a plugin cannot reach.
                     */
                    if ($fieldType->retainsOriginal() && $converted !== $submitted) {
                        $this->retainedOriginals[$handle] = $submitted;
                    }
                }

                continue;
            }

            if ($storage->strategy() !== StorageStrategy::Promoted) {
                continue;
            }

            $column = $storage->promotedColumn();

            // ⚠️ Only a column this write carries. The column is shared across handles
            // (`SlugType::promotedColumn()` is named for the type), so converting one
            // the write never touched would run another field's value through this
            // field's type.
            if ($column === null || ! array_key_exists($column, $values)) {
                continue;
            }

            $values[$column] = $this->withPerBlockDirection(
                $fieldType,
                $fieldType->toStorage($values[$column], $config),
            );
        }

        if ($inline !== null) {
            $values['values'] = $encoded ? json_encode($inline) : $inline;
        }

        return $values;
    }

    /**
     * Every saved version, newest first.
     *
     * @return HasMany<EntryRevision, $this>
     */
    public function revisionHistory(): HasMany
    {
        return $this->revisions()->orderByDesc('id');
    }

    /**
     * Refuse a revision that does not belong to this entry.
     *
     * ⚠️ ONE implementation for both call sites deliberately. The check runs twice
     * — once on the caller's instance and once on the locked row — and two copies
     * of the same rule are how the pre-lock and post-lock answers drift apart.
     */
    private function refuseForeignRevision(EntryRevision $revision): void
    {
        if ($revision->entry_id !== $this->getKey()) {
            throw new RuntimeException(
                "Revision [{$revision->getKey()}] belongs to another entry and cannot be restored onto this one."
            );
        }
    }

    /**
     * Put a revision's state back, as a NEW revision.
     *
     * History is append-only in the sense that matters: restoring version 3
     * does not delete versions 4 and 5, it adds version 6 that happens to
     * match 3. Rewriting history would make "what did this say last Tuesday"
     * unanswerable, which is the question revisions exist to answer.
     */
    public function restoreRevision(EntryRevision $revision): self
    {
        // ⚠️ A courtesy check, and NOT the one that decides. It reads the caller's
        // instance before any lock exists, so it can only report what was true
        // when that instance was loaded. The authoritative check is the identical
        // one inside the transaction, against the locked row.
        //
        // Kept because failing here costs nothing and gives the caller the error
        // without opening a transaction — but it is the second check that is load
        // bearing, and the two must not drift apart.
        $this->refuseForeignRevision($revision);

        // ⚠️ Everything that JUDGES happens inside the transaction, after the
        // row is locked — and refreshing and judging outside it was still a race.
        //
        // A refresh read the current type, another request changed it, and the
        // comparison then passed against a type that was already stale. Worse,
        // filling the snapshot did not mark `entry_type_id` dirty against the
        // in-memory value, so the save wrote type A's values onto a row that had
        // become type B and the final revision claimed a type the row did not
        // have. A guard that reads outside the lock it is protecting is not a
        // guard; it is a hint.
        DB::transaction(function () use ($revision): void {
            // The lock first, so nothing below can be answered from stale state.
            self::query()->withoutGlobalScopes()->whereKey($this->getKey())->lockForUpdate()->get();

            // ⚠️ And the REVISION is re-read under the same lock, because the
            // caller's instance can be stale in the one way that matters.
            //
            // `redactField()` sweeps revisions through separately loaded models,
            // so a caller holding an `EntryRevision` from before an erasure still
            // has the pre-erasure snapshot in memory — and restoring it wrote the
            // erased values and relation ids straight back. An erasure that
            // succeeded could be undone by a restore that never re-read anything
            // (ADR-020). Trusting a caller's snapshot is trusting a copy of the
            // data to be the data.
            $revision = $revision->newQueryWithoutScopes()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // ⚠️ And OWNERSHIP is re-checked on the locked row, because the check
            // above read a copy.
            //
            // `EntryRevision` is #[Unscoped] and writable, so `entry_id` can be
            // reassigned between the caller's load and this lock. A revision moved
            // onto this entry passed the outer check on its stale in-memory value
            // and had its snapshot restored here — and when both entries share a
            // type the type guard below agreed, so the values of an entry in
            // ANOTHER ORG could be written onto this one (ADR-021: org isolation
            // has no framework safety net).
            //
            // This is the same lesson as the type check three comments down, and
            // it was applied to the type and not to the owner: re-reading the row
            // under the lock is only half the job if the answers derived from it
            // are still the ones computed before.
            $this->refuseForeignRevision($revision);

            // ⚠️ And the refresh is needed for a second, separate reason: a model
            // from `create()` holds only the attributes the caller set, so `slug`
            // and `published_at` are in neither `$attributes` nor `$original`.
            // Filling them from the snapshot with the same nulls marks them dirty
            // and `wasChanged()` reports a change that never happened, filing a
            // redundant version for a no-op restore.
            $this->refresh();

            // ⚠️ Refused ACROSS a type change, rather than silently applied.
            //
            // `values` are keyed by field handle and mean whatever the entry's
            // type says they mean, and `entry_type_id` is mutable. Restoring a
            // revision authored under the old type wrote its values back onto an
            // entry that now resolves a different field set — the same JSON read
            // against the wrong schema, which is the quiet content destruction
            // ADR-006 locks storage shape to prevent, arriving by another door.
            //
            // Refused rather than restoring the old type as well: moving an entry
            // between types changes which fields apply, its URL, and the relations
            // pointing at it. That is a deliberate act, not a side effect of
            // asking for last Tuesday's text.
            if ($revision->entry_type_id !== $this->entry_type_id) {
                throw new RuntimeException(sprintf(
                    'Revision [%s] was authored while this entry was type [%s] and it is now type '
                    .'[%s]. Its values are keyed by that type\'s field handles, so restoring them '
                    .'here would read them against a different schema (ADR-006, ADR-010). Change '
                    .'the type back first if that is what you mean.',
                    (string) $revision->getKey(),
                    (string) $revision->entry_type_id,
                    (string) $this->entry_type_id,
                ));
            }

            $state = $revision->relation_state;

            // Validated before anything is written, so the refusal below can say
            // nothing has changed and be telling the truth.
            if ($state !== null) {
                $this->refuseMissingTargets($revision, $state);
            }

            $relationsBefore = $this->relationState();

            // ⚠️ A SNAPSHOT, because `wasChanged()` cannot answer this.
            //
            // It reads the model's `$changes`, which is populated by the last save
            // that actually wrote something — and neither `refresh()` nor a save
            // with nothing dirty clears it. So an instance that had already
            // performed an update and then restored its newest revision saw the
            // EARLIER edit's changes, filed a duplicate revision for a restore that
            // changed nothing, and with enough repetitions pruned a genuine older
            // version off the end of the bounded history.
            //
            // Comparing before and after answers the question actually being asked
            // — did this restore change the entry? — without depending on when
            // Eloquent last synced its bookkeeping. It is the same comparison
            // `recordRevisionForEventlessWrite()` makes, for the same reason.
            $scalarBefore = $this->versionedState();

            // ⚠️ ONE version, recorded at the END. The scalar save fires
            // `updated`, so a revision filed there described the entry with its
            // OLD relations — and when nothing scalar changed it filed nothing at
            // all, leaving the newest revision not describing the entry. Both
            // halves are one restore, so recording waits for both.
            RevisionWrites::suspend(function () use ($revision, $state): void {
                $this->fill($revision->snapshot())->save();

                // `null` means the revision predates the column and says nothing
                // about relations. `[]` means it says there were none.
                if ($state !== null) {
                    $this->replaceRelations($state);
                }
            });

            if ($this->versionedState() !== $scalarBefore || $this->relationState() !== $relationsBefore) {
                $this->recordRevision();
            }
        });

        return $this;
    }

    /**
     * A relation snapshot with the keys whose field storage has since gone removed.
     *
     * ⚠️ ONE implementation, called by the validation and by the rebuild, because
     * the two disagreeing is precisely the defect this exists to fix. A key
     * discarded by one and enforced by the other makes a revision unrestorable for
     * a reason neither half intends.
     *
     * Discarded rather than refused: deleting the storage row already nulled those
     * pivots (`nullOnDelete`), so the relation is gone as a concept — there is no
     * field left to restore it into, and no version of this entry that could have
     * it back. Refusing would make every revision written before that field was
     * removed permanently unrestorable. It is the same rule `relationState()`
     * applies when it skips a null `field_storage_id`.
     *
     * @param  array<array-key, mixed>  $state
     * @return array<array-key, mixed>
     */
    private static function withLiveStorageOnly(array $state): array
    {
        $live = FieldStorage::query()
            ->whereKey(array_map('intval', array_keys($state)))
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_intersect_key($state, array_flip($live));
    }

    /**
     * Refuse a restore whose recorded targets are no longer there.
     *
     * ⚠️ Separate from the rebuild, and run BEFORE any write, because the
     * message promises nothing has changed.
     *
     * Quietly restoring the subset would report success for a version the author
     * cannot actually have back, and `entry_relations` cascades on delete so the
     * row would fail its foreign key regardless — this says why instead of
     * surfacing a constraint error.
     *
     * @param  array<string, list<int>|null>  $state
     */
    private function refuseMissingTargets(EntryRevision $revision, array $state): void
    {
        // ⚠️ Unknown fields are skipped, not flattened. A null marks a field
        // whose history was erased, and casting it to an int produced target 0 —
        // refusing every restore of a redacted revision because "entry 0 no
        // longer exists".
        // ⚠️ Filtered to LIVE storage first, so this agrees with the rebuild.
        //
        // `replaceRelations()` discards a key whose storage no longer exists — the
        // field is gone as a concept, so there is nothing to restore it into. This
        // check did not, so a revision naming a relation whose storage AND target
        // had both been deleted was refused for the missing target, even though the
        // rebuild would have thrown that key away regardless. An unrelated removed
        // field made the revision permanently unrestorable.
        //
        // Two filters that must agree, so there is one of them.
        $targets = collect(self::withLiveStorageOnly($state))
            ->filter(fn (mixed $ids): bool => $ids !== null)
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique();

        if ($targets->isEmpty()) {
            return;
        }

        $missing = $targets->diff(
            self::query()->withoutGlobalScopes()->whereKey($targets->all())->pluck('id'),
        );

        if ($missing->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Revision [%s] relates this entry to %s, which no longer exist%s. Restoring it '
            .'would put back a version this entry cannot have again, so nothing has been '
            .'changed (ADR-015).',
            (string) $revision->getKey(),
            'entr'.($missing->count() === 1 ? 'y ' : 'ies ').$missing->implode(', '),
            $missing->count() === 1 ? 's' : '',
        ));
    }

    /**
     * Make this entry's field-backed relations exactly what the snapshot says.
     *
     * ⚠️ REPLACES, and an earlier version patched. It deleted only the storage
     * ids present in the snapshot, so a relation added to a DIFFERENT field after
     * the revision was taken survived a restore — and an empty snapshot was
     * treated as "nothing recorded" and skipped entirely, leaving every current
     * link attached while reporting a successful restore. `[]` is a statement:
     * at that version, this entry had no relations.
     *
     * Only field-backed rows are touched. A row whose `field_storage_id` is NULL
     * belongs to a deleted field definition, is not addressable by any field, and
     * was never recorded — so a restore has nothing to say about it and must not
     * remove it.
     *
     * @param  array<string, list<int>|null>  $state
     */
    private function replaceRelations(array $state): void
    {
        // ⚠️ A key whose value is NULL is UNKNOWN, not empty. Its history was
        // erased, so the revision says nothing about that field and a restore must
        // leave whatever is there now alone — otherwise restoring a redacted
        // revision deletes a replacement relation added after the erasure, which
        // is the opposite of what erasure is for.
        $unknown = array_map(
            'intval',
            array_keys(array_filter($state, fn (mixed $ids): bool => $ids === null)),
        );

        // Everything field-backed goes, except the fields marked unknown.
        EntryRelation::query()
            ->where('source_entry_id', $this->getKey())
            ->whereNotNull('field_storage_id')
            ->when($unknown !== [], fn ($query) => $query->whereNotIn('field_storage_id', $unknown))
            ->delete();

        // ⚠️ A snapshot can name storage that no longer exists, and the insert
        // below would hit the `entry_relations.field_storage_id` foreign key —
        // failing the whole restore because an unrelated field was removed.
        //
        // DISCARDED rather than refused, unlike a missing target entry. Deleting
        // the storage row already nulled those pivots (`nullOnDelete`), so the
        // relation is gone as a concept: there is no field left to restore it
        // into, and no version of this entry that could have it back. Refusing
        // would make every revision written before that field was removed
        // permanently unrestorable. It is the same rule `relationState()` applies
        // when it skips a null `field_storage_id`.
        foreach (self::withLiveStorageOnly($state) as $storageId => $ids) {
            // Unknown: nothing was deleted above and nothing is rebuilt here.
            if ($ids === null) {
                continue;
            }

            foreach ($ids as $ordering => $targetId) {
                EntryRelation::create([
                    // ⚠️ `org_id` explicitly. The `related()` relation supplies
                    // it through `withPivotValue()`, and writing `EntryRelation`
                    // directly does not — it defaulted to 0 and the model's own
                    // guard refused the row, rolling the whole restore back. A
                    // relation belongs to the org of the entry it hangs off.
                    'org_id' => $this->org_id,
                    'source_entry_id' => $this->getKey(),
                    'target_entry_id' => (int) $targetId,
                    'field_storage_id' => (int) $storageId,
                    'ordering' => $ordering,
                ]);
            }
        }
    }

    /** Run a save that leaves no revision behind. */
    public function withoutRevisions(callable $work): mixed
    {
        // ⚠️ Delegates to a SHARED flag rather than holding it on the instance.
        //
        // An instance property was right while `created` and `updated` were the
        // only recorders. Recording now also happens in the builder, which has
        // no instance to read — so an instance-private flag would stand down one
        // recorder and not the other, exactly as `withoutScopeBecause()` once
        // did before `ScopeWrites` existed. Erasure depends on this covering
        // both: redacting inside `withoutRevisions()` is what stops the redacted
        // state being filed as a new version of the history being cleared.
        return RevisionWrites::suspend(fn (): mixed => $work($this));
    }

    /** @return HasMany<EntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class);
    }

    /**
     * Outgoing relations through the real table (ADR-015).
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    public function related(): GuardedBelongsToMany
    {
        return $this->relationsThrough('source_entry_id', 'target_entry_id');
    }

    /**
     * The reverse lookup that JSON storage cannot answer without a full scan:
     * "what references this asset?"
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    public function referencedBy(): GuardedBelongsToMany
    {
        return $this->relationsThrough('target_entry_id', 'source_entry_id');
    }

    /**
     * Both relation directions, with org_id stamped and constrained.
     *
     * withPivotValue() does two jobs and both are wanted. It supplies org_id
     * on attach, without which every attach fails on a NOT NULL constraint —
     * the pivot has no model, so nothing else can stamp it, and each call
     * site would otherwise have to remember. It also constrains reads to the
     * same org, so a relation row belonging to another customer cannot
     * surface here even if one existed.
     *
     * A relation always belongs to the org of the entry it hangs off, so
     * there is no case where these could legitimately differ. org_id is
     * non-nullable and EnforcesScope stamps it on create, so it is always
     * available here.
     *
     * @return GuardedBelongsToMany<Entry, $this>
     */
    private function relationsThrough(string $foreignPivotKey, string $relatedPivotKey): GuardedBelongsToMany
    {
        // Constructed rather than via belongsToMany(), so this relation —
        // and only this one — serialises its writes. The cardinality check in
        // EntryRelation is a count followed by an insert, and two of those
        // interleave: concurrent attaches to the same single-valued relation
        // both count zero and both insert, restoring the two-subject state
        // the check exists to prevent (ADR-020).
        $relation = new GuardedBelongsToMany(
            self::query(),
            $this,
            'entry_relations',
            $foreignPivotKey,
            $relatedPivotKey,
            $this->getKeyName(),
            $this->getKeyName(),
            __FUNCTION__,
        );

        $relation
            // A pivot MODEL, so the field's own cardinality and targetTypes
            // are enforced when a row is written. `attach()` goes nowhere
            // near validation, so without this they were metadata nobody
            // checked — see EntryRelation.
            ->using(EntryRelation::class)
            ->withPivot(['org_id', 'field_storage_id', 'ordering']);

        // org_id is declared non-nullable, and EnforcesScope stamps it on
        // create, so by the time a relation is reached it is always set.
        return $relation->withPivotValue('org_id', $this->org_id);
    }
}
