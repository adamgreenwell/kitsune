<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Entries;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

use function Filament\Support\original_request;

use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Fields\FieldConfig;
use Kitsune\Core\Filament\Resources\Entries\Pages\CreateEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\EditEntry;
use Kitsune\Core\Filament\Resources\Entries\Pages\ListEntries;
use Kitsune\Core\Filament\Resources\Entries\Pages\ManageEntryRelations;
use Kitsune\Core\Filament\Resources\Entries\Pages\ViewEntry;
use Kitsune\Core\Filament\Schemas\FieldValueRenderer;
use Kitsune\Core\Filament\Schemas\SiteTime;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\EntryTypeAvailability;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Validation\Rule;

/**
 * One Resource for every entity type, with the type as a path segment.
 *
 * ADR-012, settled by measurement rather than reasoning. Seven admin routes
 * whether an org has three entity types or three hundred, and route:cache
 * stays safe because {type} is a parameter with nothing to go stale.
 */
class EntryResource extends Resource
{
    /**
     * The column the entry list sorts by when the author has chosen nothing.
     *
     * ⚠️ A CONSTANT SO A SCHEMA TEST CAN READ IT. `EntryListSortIsIndexedTest` asserts that `entries`
     * carries an index leading with the scope key and ending on this column — because nothing did until
     * something measured the admin, and the list page paid a full sort of every row in the site on every
     * request. Changing the sort here fails that test until an index covers the new one, which is the
     * only way this stays true after the person who measured it has moved on.
     */
    public const DEFAULT_SORT = 'updated_at';

    protected static ?string $model = Entry::class;

    /** ADR-012: the type lives beneath this segment. */
    protected static ?string $slug = 'c';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * ⚠️ NOT optional, and not a preference.
     *
     * Filament auto-registers one navigation item per Resource and calls
     * getUrl() on it while rendering the sidebar. With {type} in the URI and
     * no {type} in the current request, that throws UrlGenerationException
     * and 500s EVERY page outside /c/{type} — the dashboard included.
     *
     * Found by opening a browser during the 2026-09-07 spike, while the PHP
     * suite was seven-of-eight green. Navigation is supplied explicitly by
     * the panel instead.
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * The type's own name, so the page says what the sidebar said.
     *
     * ⚠️ ONE RESOURCE FOR EVERY TYPE MEANT ONE LABEL FOR EVERY TYPE. Filament derives the list title, the
     * breadcrumb and "Create …" from the model, so a list scoped to articles was headed "Entries" beneath a
     * sidebar item reading "Articles", and the breadcrumb read "Entries › List". The navigation already
     * names each type by `plural_name` (`KitsunePanel::navigation()`); these read the same two columns off the
     * type `IdentifyEntryType` bound, which is the one this request is about.
     *
     * ⚠️ AS THE OPERATOR WROTE THEM, not lowercased to Filament's convention. Filament's title-case variants only
     * capitalise, so "FAQ" stays "FAQ" — and a sentence reading "No Articles" is the smaller cost than a
     * heading reading "Faq".
     *
     * Outside `/c/{type}` nothing is bound, and Filament's own label stands — the same guard as
     * `currentFields()`, for the same reason: reaching for the type unguarded is what 500s the dashboard.
     */
    public static function getModelLabel(): string
    {
        return app()->bound(EntryType::class) && filled($name = app(EntryType::class)->name)
            ? (string) $name
            : parent::getModelLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return app()->bound(EntryType::class) && filled($name = app(EntryType::class)->plural_name)
            ? (string) $name
            : parent::getPluralModelLabel();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // ⚠️ `dir="auto"` on the INPUT, not the page. Filament sets `dir` once, on
            // the root `<html>`, from the panel locale — so every field value renders in
            // the direction of the CHROME rather than its own. Arabic in an English
            // admin puts punctuation, parentheses and mixed-direction numerals on the
            // wrong side; English in an Arabic admin does the mirror.
            //
            // The result is legible-ish and wrong, which is the worst kind of broken:
            // nobody files a bug, editors just work around it. ADR-018 rule 2 exists
            // because one org has editors working in different languages, and the seed
            // fixture models exactly that — so bidirectional content in one admin is the
            // designed case, not an edge one (issue #39).
            //
            // `auto` rather than a computed direction: the browser reads the first strong
            // directional character in the VALUE, per field, per row. It costs nothing
            // when the content and the chrome agree.
            TextInput::make('title')->required()->maxLength(255)
                ->extraInputAttributes(['dir' => 'auto']),
            TextInput::make('slug')
                ->maxLength(255)
                // A slug is generated from the title and carries its script.
                ->extraInputAttributes(['dir' => 'auto'])
                /*
                 * ⚠️ WITHHELD FROM AN ORG-SHARED ENTRY — ADR-042 decision 2, making ADR-021's rule a guard. A shared
                 * entry is not publicly addressable, so it has no slug, and `AuditedBuilder` refuses one; offering the
                 * control would put a refusal behind every edit. Asked of the stored row, and a hidden control is not
                 * sent, so an ordinary save of a shared entry never names the column.
                 */
                ->hidden(fn (?Entry $record): bool => $record?->isStoredAsShared() ?? false)
                ->helperText('The entry\'s address on this site. Entries shared across the organisation have none.')
                // scopedUnique, never Laravel's unique: that rule does not go
                // through Eloquent, so it ignores global scopes and would tell
                // one org that another org holds the slug.
                // Constrained to the entry type, matching the database's own
                // UNIQUE (site_id, entry_type_id, slug). Without it an
                // `about` page would block an `about` product, which the
                // database permits and the type-qualified URL expects.
                ->rules(fn (?Entry $record): array => [
                    Rule::scopedUnique(
                        Entry::class,
                        'slug',
                        $record?->getKey(),
                        function ($query) use ($record): void {
                            $typeId = app()->bound(EntryType::class)
                                ? app(EntryType::class)->getKey()
                                : $record?->entry_type_id;

                            if ($typeId !== null) {
                                $query->where('entry_type_id', $typeId);
                            }
                        },
                    ),
                ]),
            /*
             * ⚠️ `published` IS WITHHELD FROM SOMEBODY WHO MAY NOT PUBLISH, AND ALSO REFUSED IN VALIDATION.
             * ADR-033 registers `entry.{type}.publish` as an action, and AGENTS.md #14 says a published
             * constraint has to be enforceable — a permission nothing consults is worse than an absent one,
             * because a reader believes it. Filtering the options is the visible half; the `in` rule is the
             * half that survives a hand-built request, which is the only half an attacker meets.
             *
             * ⚠️ AND THE OTHER TWO STAY AVAILABLE, deliberately. Withholding the whole control would take
             * `archived` with it, which is a different action the vocabulary does not name — so a writer
             * without publish rights could not file their own draft away. The bundling is stated rather
             * than silent: `archive` is not one of the five actions `architecture.md` publishes.
             */
            Select::make('status')
                ->options(fn (?Entry $record): array => self::statusOptionsFor(Permissions::currentUser(), $record))
                ->default('draft')
                // A string rule rather than `Illuminate\Validation\Rule::in()`, because `Rule` in this
                // file is Kitsune's own — the one that goes through Eloquent so global scopes apply.
                ->rule(fn (?Entry $record): string => 'in:'.implode(',', array_keys(
                    self::statusOptionsFor(Permissions::currentUser(), $record),
                )))
                ->required(),
            ...self::fieldControls(),
        ]);
    }

    /**
     * The statuses this user may set on THIS entry, asked of the database rather than of the instance.
     *
     * ⚠️ THE CONCESSION IS ABOUT THE STORED ROW, AND IT WAS READING A LOADED ATTRIBUTE — review found the
     * gap between the sentence this file already published and the value it passed. `published` stays
     * available to somebody who may not publish only because the entry IS published; `$record->status` is
     * what the instance was loaded with, so a form held open across a demotion by somebody else kept
     * offering the option, and the `in` rule kept accepting it.
     *
     * ⚠️ MEASURED BEFORE FIXING, BECAUSE THE REPORTED CONSEQUENCE DID NOT REPRODUCE: a save from that stale
     * instance does not put the entry back — Eloquent writes dirty attributes, and an instance whose
     * original is `published` submitting `published` writes no status at all, so the newer draft survives.
     * `EntryStatusOptionsTest` pins that, because it is the only reason the window was not an unpermitted
     * publication, and it is a fact about the framework rather than about this guard.
     *
     * What was wrong either way is the question being asked. A permission decided from an attribute the
     * request carries is decided from the request; one keyed read is what makes the answer the row's.
     *
     * @return array<string, string>
     */
    public static function statusOptionsFor(?Authenticatable $user, ?Entry $record): array
    {
        return self::statusOptions($user, self::storedStatus($record));
    }

    /**
     * The status the database holds for an entry right now.
     *
     * ⚠️ THROUGH THE SCOPED QUERY AND THE ORIGINAL KEY. Scoped, because a status read outside the tenancy
     * scope would answer about another org's row; the original key, because that is the row an instance
     * write lands on — `getKeyForAuthorization()` exists for exactly this, and `EntryPolicy` already asks
     * the same way.
     */
    private static function storedStatus(?Entry $record): ?string
    {
        if ($record === null || ! $record->exists || $record->getKeyForAuthorization() === null) {
            return null;
        }

        $stored = Entry::query()->whereKey($record->getKeyForAuthorization())->value('status');

        return is_string($stored) ? $stored : null;
    }

    /**
     * The statuses this user may set on this entry type.
     *
     * ⚠️ RESOLVED AT RENDER AND AT VALIDATION, from one place, so the two cannot disagree. A list computed
     * once for the control and again for the rule is a list that drifts the day somebody edits one of them.
     *
     * ⚠️ PUBLIC SO IT CAN BE TESTED DIRECTLY, which is earned rather than habitual: it is the single source
     * both the control and the validation rule read, so a test of it is a test of both halves — and the
     * half that matters against an attacker is the rule, which no browser test can reach without building
     * a request by hand.
     *
     * ⚠️ AND THE USER IS A PARAMETER RATHER THAN `Filament::auth()` INSIDE, because reaching for the panel's
     * guard in here made the method unreachable from the package suite — `Target class [filament] does not
     * exist`, since core's tests stand up no panel by design (ADR-024 puts that layer in the browser). The
     * caller is inside a panel and supplies it; this is a function of a user and a type.
     *
     * ⚠️ AN ENTRY THAT IS ALREADY PUBLISHED KEEPS THAT OPTION, WHICHEVER PERMISSIONS THE EDITOR HOLDS, and
     * review found what the first version cost. `publish` is permission to move an entry INTO the published
     * state — but withholding the option outright also withheld the entry's own CURRENT value, so a
     * copy-editor could not fix a typo on a published article without first demoting or archiving it. The
     * permission became a licence to unpublish.
     *
     * The distinction is the transition rather than the value: `published` is offered when the user may
     * publish OR when the entry already is, and because the stored status is what decides, the concession
     * cannot be used to reach the state — draft stays draft, and an entry demoted to draft in one save is
     * offered no way back in the next.
     *
     * @return array<string, string>
     */
    public static function statusOptions(?Authenticatable $user, ?string $current = null): array
    {
        /*
         * ⚠️ THE LABELS ARE HERE AND THE VOCABULARY IS `Entry::STATUSES`, which the write boundary enforces.
         * Two lists of what a status may be is one list that drifts — and the drift would be silent, because
         * a value this control never offers is refused at the write rather than rendered wrongly.
         */
        $options = ['draft' => 'Draft', 'archived' => 'Archived'];

        $mayPublish = $user !== null && app()->bound(EntryType::class) && Permissions::allows(
            $user, Permissions::forEntryType(app(EntryType::class)->handle, 'publish'),
        );

        if ($mayPublish || $current === 'published') {
            $options['published'] = 'Published';
        }

        // Ordered as an author reads them rather than as they were assembled.
        return array_filter([
            'draft' => $options['draft'],
            'published' => $options['published'] ?? null,
            'archived' => $options['archived'],
        ]);
    }

    /**
     * The controls for whatever fields the current entry type defines.
     *
     * ⚠️ `title`, `slug` and `status` above are PLATFORM columns and stay hand-written:
     * they are not user-definable (field-types.md §2), every entry type has them, and
     * `slug` carries a `scopedUnique` rule that no field descriptor expresses. Everything
     * an operator added comes from here.
     *
     * ⚠️ Direction is NOT set here, and that is the point. `FieldValueRenderer` is the
     * only place that decides it (ADR-029), so this method cannot forget — there is
     * nothing here to forget. That is the difference from the three hand-written columns,
     * which each had to be fixed separately and two of which were missed.
     *
     * @return array<int, Component>
     */
    private static function fieldControls(): array
    {
        return array_map(
            static fn (Field $field): Component => FieldValueRenderer::formComponent(
                new FieldConfig($field->fieldStorage, $field),
            ),
            self::currentFields(),
        );
    }

    /**
     * The current entry type's fields, in the order an operator arranged them.
     *
     * ⚠️ Empty when no type is bound, which is not a defect: a Resource is constructed
     * for pages outside `/c/{type}` too, and `IdentifyEntryType` has bound nothing there.
     * Reaching for `app(EntryType::class)` unguarded is what 500s the dashboard — the
     * same failure `$shouldRegisterNavigation = false` above exists to avoid.
     *
     * ⚠️ Eager-loads `fieldStorage`, because a control is built per field and each one
     * asks its storage for the type. Without it a ten-field entry type renders eleven
     * queries to draw one form, against the 1 vCPU / SQLite floor of ADR-027.
     *
     * @return array<int, Field>
     */
    private static function currentFields(): array
    {
        if (! app()->bound(EntryType::class)) {
            return [];
        }

        return app(EntryType::class)
            ->fields()
            ->with('fieldStorage')
            ->orderBy('ordering')
            ->get()
            // A field whose storage has been deleted would build a control against null.
            // Cannot happen through the admin — the relation cascades — but a direct
            // database edit is not the model's to trust.
            ->filter(fn (Field $field): bool => $field->fieldStorage !== null)
            ->values()
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // The same reasoning as the form input: a list of entry titles in one
                // org can hold several scripts, and the cell has to resolve each on its
                // own content rather than on the panel's direction.
                TextColumn::make('title')->searchable()->sortable()
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('type_handle')->badge()->label('Type'),
                TextColumn::make('status')->badge()->sortable(),
                SiteTime::column('updated_at')->sortable()->toggleable(isToggledHiddenByDefault: true),
                ...self::fieldColumns(),
            ])
            // Record links are exactly what 500s without isPersistent: true.
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort(self::DEFAULT_SORT, 'desc')
            ->paginationMode(static fn (): PaginationMode => self::paginationModeFor(
                app()->bound(EntryType::class) ? app(EntryType::class) : null,
            ))
            /*
             * ⚠️ AND "SELECT ALL" MEANS THIS PAGE, or the count comes straight back — review found it. With bulk actions
             * on, Filament counts every selectable record on each render, and it can read that number off the paginator
             * only when the paginator has one: a simple paginator does not, so it ran the very `count(*)` the pagination
             * mode exists to avoid, for every user who may delete. A media list's bulk actions act on the page in view.
             */
            ->selectCurrentPageOnly(static fn (): bool => self::paginationModeFor(
                app()->bound(EntryType::class) ? app(EntryType::class) : null,
            ) === PaginationMode::Simple);
    }

    /**
     * How a type's list pages — decided by Adam on the measurements, ADR-042 decision 2.
     *
     * ⚠️ A MEDIA TYPE'S LIST PAGES WITHOUT A TOTAL. Its rows are this site's and the org's shared ones, read through the
     * org-leading index, and that index also holds every other site's files of the type — which a `count(*)` and a deep
     * `OFFSET` have to walk and throw away. Measured at 290,000 entries (ADR-042's *Measured*), the count took 178 ms on
     * SQLite and 123 ms on MySQL, on every request to the list, and the last page 178 ms and 165 ms. Previous and Next
     * need neither; page 1 is one ordered read on all four engines. Every other type keeps its total.
     */
    public static function paginationModeFor(?EntryType $type): PaginationMode
    {
        return $type?->is_media === true ? PaginationMode::Simple : PaginationMode::Default;
    }

    /**
     * List columns for the current entry type's fields.
     *
     * ⚠️ Hidden by default, all of them. An operator may define twenty fields, and a
     * twenty-column table is unreadable and expensive — `toggleable` lets a reader add the
     * one they want. The three platform columns above stay visible because every entry has
     * them and they are what a list is scanned by.
     *
     * ⚠️ A `Cell::None` field yields null from the renderer and is dropped here, so rich
     * text and JSON contribute no column rather than a truncated one.
     *
     * @return array<int, Column>
     */
    private static function fieldColumns(): array
    {
        $columns = [];

        foreach (self::currentFields() as $field) {
            $column = FieldValueRenderer::tableColumn(new FieldConfig($field->fieldStorage, $field));

            if ($column !== null) {
                $columns[] = $column->toggleable(isToggledHiddenByDefault: true);
            }
        }

        return $columns;
    }

    /**
     * Filament's tenant scope for entries: this site's rows, and — for media types only — the org's shared ones.
     *
     * ⚠️ ADR-042 DECISION 2, AND THE ONE PLACE FILAMENT'S SITE BOUNDARY IS WIDENED. Filament registers one global
     * scope per model per panel and calls this through `static::`, so every `Entry` query in the panel passes here:
     * the list, record binding, the relation picker's search and labels, the related page and Attach, the private
     * download route and the checks a new link's two ends must pass. Admitting shared media in one place is what keeps
     * those from disagreeing — a picker that offers a file the save then cannot see. (Relation hydration reads the
     * links themselves, `Entry::linkedIdsForField()`, so a link this site cannot see is kept rather than dropped.)
     *
     * ⚠️ `SiteScope`'S OWN RULE, NARROWED, AND NEVER WIDER. `SiteScope` admits `site_id = S OR (site_id IS NULL AND
     * org_id = O)` for every type. This admits the second half only for the media types enabled at this site —
     * ADR-022's availability applied together with the widening, so a shared file whose type is switched off here is
     * neither listed, offered nor served — and the kernel's `SiteScope` stays ANDed on every query regardless.
     * `MediaTenantScopeTest` pins the two row for row.
     *
     * ⚠️ `org_id` INSIDE THE SHARED ARM, NOT AT THE TOP — measured, on all four engines (ADR-042's *Measured*). At the
     * top it looked free, since `SiteScope` implies it for every row it admits, and it steered every query onto the
     * org-leading index: a query across types then walked every row of the org, on every site, and at 290k rows the
     * picker's search took 287 ms on SQLite and 251 ms on MariaDB against 26 and 162 as built. The media LIST adds the
     * conjunct itself,
     * in `getEloquentQuery()`, because that is the one read the org-leading index serves in order.
     */
    public static function scopeEloquentQueryToTenant(Builder $query, ?Model $tenant): Builder
    {
        $tenant ??= Filament::getTenant();

        if (! $query->getModel() instanceof Entry || ! $tenant instanceof Site) {
            return parent::scopeEloquentQueryToTenant($query, $tenant);
        }

        $media = self::sharedMediaTypeIds($tenant);

        if ($media === []) {
            return parent::scopeEloquentQueryToTenant($query, $tenant);
        }

        $model = $query->getModel();

        return $query->where(static function (Builder $rows) use ($model, $tenant, $media): void {
            $rows->where($model->qualifyColumn('site_id'), $tenant->getKey())
                ->orWhere(static fn (Builder $shared): Builder => $shared
                    ->whereNull($model->qualifyColumn('site_id'))
                    ->where($model->qualifyColumn('org_id'), $tenant->org_id)
                    ->whereIn($model->qualifyColumn('entry_type_id'), $media));
        });
    }

    /**
     * The media types enabled at this site, whose org-shared rows the panel admits.
     *
     * Global and org-owned alike, and NOT collapsed by handle: an org that defines its own `image` shadows the global
     * one in navigation, and the global type's shared files stay reachable where they are linked and served rather
     * than vanishing from every site at once.
     *
     * @return list<int>
     */
    public static function sharedMediaTypeIds(Site $site): array
    {
        // Scalars only, for `once()`'s key — AGENTS.md §13.
        $siteId = (int) $site->getKey();
        $siteGroupId = $site->site_group_id === null ? null : (int) $site->site_group_id;
        $orgId = (int) $site->org_id;

        return once(static function () use ($siteId, $siteGroupId, $orgId): array {
            $ids = EntryType::query()
                ->where('is_media', true)
                ->where(static fn (Builder $query): Builder => $query->whereNull('org_id')->orWhere('org_id', $orgId))
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $enabled = EntryTypeAvailability::enabledMapFor($ids, $siteId, $siteGroupId, $orgId);

            return array_values(array_filter($ids, static fn (int $id): bool => $enabled[$id] ?? true));
        });
    }

    /**
     * Narrow a panel query back to this site's own rows: Filament's unwidened rule.
     *
     * For a non-media type's list and a picker with no media target, which can hold no shared row, and for the
     * dashboard's recent entries and counts, which keep this site's own rows by Adam's decision on ADR-042's
     * measurement. The OR the widened rule adds would cost each of them its site-leading read.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function onlyThisSitesRows(Builder $query): Builder
    {
        // No panel at all — a host without Filament's provider, or a test that boots none — leaves nothing to narrow.
        if (! app()->bound('filament')) {
            return $query;
        }

        $panel = Filament::getCurrentPanel();
        $tenant = Filament::getTenant();

        if ($panel === null || ! $panel->hasTenancy() || ! $tenant instanceof Site) {
            return $query;
        }

        /*
         * Filament's own rule for a `BelongsTo` ownership — `scopeEloquentQueryToTenant()` is exactly this `whereBelongsTo()`
         * for one — applied directly rather than through `parent::`, whose signature is not generic.
         */
        return $query
            ->withoutGlobalScope($panel->getTenancyScopeName())
            ->whereBelongsTo($tenant, static::getTenantOwnershipRelationshipName());
    }

    /**
     * Filament's creation hook, which stamps the tenant on every new entry — except an explicitly shared media one.
     *
     * ⚠️ THE OTHER THING FILAMENT'S TENANCY DOES TO ENTRIES. Its `creating` listener associates the current site
     * with every record created in the panel, so `MediaLibrary::store()`'s explicit `site_id = null` was overwritten
     * and no panel upload could be shared. This leaves that one case alone: the attribute present and null, on a
     * type whose STORED flag says media. Everything else is stamped as before — `Entry::create(['site_id' => null])`
     * on an article included, which the slug guard and `SiteScope` would otherwise have to reason about.
     *
     * Filament's `created` listener is not repeated: for a `BelongsTo` ownership it returns without doing anything.
     */
    public static function observeTenancyModelCreation(Panel $panel): void
    {
        if (! static::isScopedToTenant()) {
            return;
        }

        Entry::creating(static function (Entry $entry) use ($panel): void {
            if (Filament::getCurrentPanel() !== $panel) {
                return;
            }

            $tenant = Filament::getTenant();

            if (! $tenant) {
                return;
            }

            $attributes = $entry->getAttributes();

            if (array_key_exists('site_id', $attributes)
                && $attributes['site_id'] === null
                && (bool) EntryType::query()->whereKey($entry->getAttribute('entry_type_id'))->value('is_media')) {
                return;
            }

            $relationship = static::getTenantOwnershipRelationship($entry);

            if ($relationship instanceof BelongsTo) {
                $relationship->associate($tenant);
            }
        });
    }

    /** @return Builder<Entry|Model> */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Filter by the RESOLVED type's id, not by the handle string.
        //
        // A global type and an org type may share a handle, so filtering on
        // type_handle would mix records from two different schemas under one
        // URL. IdentifyEntryType has already resolved exactly which type this
        // URL means, including precedence, so use its answer.
        if (app()->bound(EntryType::class)) {
            $type = app(EntryType::class);
            $query->where('entry_type_id', $type->getKey());

            /*
             * ⚠️ A MEDIA TYPE'S LIST ADMITS SHARED ROWS; ANY OTHER TYPE'S IS THIS SITE'S, AS IT ALWAYS WAS. The
             * widened rule could admit no row of a non-media type here, and would cost its list the ordered read
             * of `(site_id, entry_type_id, updated_at)`.
             */
            if (! $type->is_media) {
                return self::onlyThisSitesRows($query);
            }

            /*
             * ⚠️ AND THE MEDIA LIST SAYS `org_id`, which every row it admits already has: it is the prefix that lets
             * `(org_id, entry_type_id, updated_at)` deliver the page in order — one ordered index read on all four
             * engines, where `SiteScope`'s OR alone is a multi-index OR and a sort of every matching row.
             */
            $tenant = Filament::getTenant();

            return $tenant instanceof Site ? $query->where($query->qualifyColumn('org_id'), $tenant->org_id) : $query;
        }

        $type = request()->route()?->parameter('type')
            ?? original_request()->route()?->parameter('type');

        return is_string($type) && $type !== ''
            ? $query->where('type_handle', $type)
            : $query;
    }

    public static function getRelations(): array
    {
        // A relation manager COMPONENT registers no routes of its own (spike
        // #10), so this adds no URL that would need {type} threading through
        // it and no new reserved type handle.
        return [RelationManagers\RevisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        // Hard-coded segments BEFORE wildcards, or /{type}/create is
        // swallowed by /{type}/{record} (ADR-012 detail 1).
        return [
            'index' => ListEntries::route('/{type}'),
            'create' => CreateEntry::route('/{type}/create'),
            'view' => ViewEntry::route('/{type}/{record}'),
            'edit' => EditEntry::route('/{type}/{record}/edit'),
            // Spike #10: a page-based relation manager, which unlike a
            // RelationManager component registers its own route.
            'relations' => ManageEntryRelations::route('/{type}/{record}/related'),
        ];
    }
}
