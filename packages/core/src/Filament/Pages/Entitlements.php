<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Auth\ReaderGuard;
use Kitsune\Core\Entitlements\EntitlementName;
use Kitsune\Core\Entitlements\EntitlementRefused;
use Kitsune\Core\Entitlements\EntitlementSource;
use Kitsune\Core\Entitlements\EntitlementWriter;
use Kitsune\Core\Entitlements\GrantOutcome;
use Kitsune\Core\Filament\AuditActors;
use Kitsune\Core\Filament\Schemas\SiteTime;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Entitlement;
use Kitsune\Core\Tenancy\Context;
use LogicException;
use PDOException;
use RuntimeException;

/**
 * Where an owner sees who holds what on this site, from which source, until when and how it got there; gives a comp;
 * and revokes one source — ADR-040, entitlements' second half.
 *
 * ⚠️ OWNER-ONLY, AND THE NULL USER IS THE LOAD-BEARING HALF. With nobody signed in the writer trusts its caller as the
 * system, so the page, each action and each handler refuse a missing user here, as the credentials page does.
 *
 * ⚠️ EVERY PUBLIC METHOD THIS CLASS ADDS IS STATIC. Livewire lets a browser call a component's public instance methods,
 * never its static ones; the instance methods here are Filament's own — `content`, `table`, `getTitle`, and the six
 * reads below — and `EntitlementPageTest` asserts it by reflection.
 *
 * ⚠️ NO STORED VALUE IN AN ATTRIBUTE OR IN MARKUP. A reader's identifier is any printable ASCII, `"` and `<` included,
 * and Filament merges an action's extra attributes unescaped, while `__()` escapes none of its parameters. So stored
 * values reach the browser only through a table column, an entry, a modal's heading or description, or a notice through
 * `e()`; the reader's identifier only through its column; and the row actions carry their visible label alone.
 *
 * ⚠️ A FAILED READ CARRIES ITS SQLSTATE ALONE. A filtered read binds the reader's identifier, and a `QueryException`
 * interpolates its bindings into the message the exception handler logs — so every read of the table, like the
 * writer's doors, chains nothing: the two a render makes, and the four a browser can call by name though this table
 * selects nothing (review). The fifth, a group's keys, fails before any query on a table with no grouping.
 *
 * ⚠️ A READER IS SHOWN BY IDENTIFIER until reader accounts name them (ADR-040).
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class Entitlements extends Page implements HasTable
{
    // Every inherited read that runs the filtered query, renamed so the page's own override maps it (`mapped()`).
    use InteractsWithTable {
        getTableRecords as private unmappedTableRecords;
        getTableRecord as private unmappedTableRecord;
        getAllTableRecordsCount as private unmappedAllTableRecordsCount;
        getAllSelectableTableRecordKeys as private unmappedAllSelectableTableRecordKeys;
        getAllSelectableTableRecordsCount as private unmappedAllSelectableTableRecordsCount;
        getSelectedTableRecords as private unmappedSelectedTableRecords;
    }

    // Every Livewire class core ships restricts uploads to its schema's own fields (`UploadSurfaceTest`); this one has none.
    use RestrictsFileUploadsToSchemaComponents;

    protected static ?string $slug = 'entitlements';

    // Navigation is explicit (`KitsunePanel::navigation()`), and hidden until a usable reader guard is declared.
    protected static bool $shouldRegisterNavigation = false;

    /** What a history row's action reads as; any other is shown as stored. */
    private const ACTIONS = [
        EntitlementWriter::GRANTED => 'granted',
        EntitlementWriter::EXTENDED => 'extended',
        EntitlementWriter::REINSTATED => 'reinstated',
        EntitlementWriter::REVOKED => 'revoked',
        EntitlementWriter::ERASED => 'erased',
    ];

    // ---- Access ------------------------------------------------------------------------------------------------------

    /** An owner of the org in context — the credentials page's rule, and no permission subject. */
    public static function canAccess(): bool
    {
        $user = Permissions::currentUser();

        return $user !== null && Permissions::isOwner($user);
    }

    /** The sidebar link: for whoever may open the page, once the installation declares a usable reader guard. */
    public static function belongsInNavigation(): bool
    {
        return self::canAccess() && app(ReaderGuard::class)->fault() === null;
    }

    public function getTitle(): string
    {
        return __('kitsune::entitlements.title');
    }

    // ---- Content -----------------------------------------------------------------------------------------------------

    /**
     * The rows, or — with no usable reader guard — the guard's fault and nothing else (Adam, answer 1): every check
     * answers no while it lasts, so a row badged "Live" would contradict the door, and a revoke would be refused.
     */
    public function content(Schema $schema): Schema
    {
        $fault = app(ReaderGuard::class)->faultSentence();

        if ($fault !== null) {
            return $schema->components([
                EmptyState::make(__('kitsune::entitlements.fault.heading'))
                    ->description(__('kitsune::entitlements.fault.description', ['fault' => $fault]))
                    ->icon(Heroicon::OutlinedNoSymbol),
            ]);
        }

        return $schema->components([
            Text::make(__('kitsune::entitlements.intro', ['site' => (string) app(Context::class)->site()?->name])),
            Text::make(__('kitsune::entitlements.holds'))->color('gray'),
            EmbeddedTable::make(),
        ]);
    }

    // ---- The table ---------------------------------------------------------------------------------------------------

    public function table(Table $table): Table
    {
        // One instant per build, for every badge and the state filter alike.
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        return $table
            ->query(static fn (): Builder => Entitlement::query()
                // ⚠️ EXPLICIT: Filament's tenancy scope is a Resource's, never a page's. The site is the equality the
                // planner leads with, and the org the fence `SiteScope`'s site branch lacks.
                ->where('entitlements.site_id', app(Context::class)->siteId())
                ->where('entitlements.org_id', app(Context::class)->orgId())
                // ⚠️ NOTHING AT ALL WITHOUT A USABLE READER GUARD. The page embeds no table then, and a read a browser
                // calls by name — Livewire returns its value — must list nothing either (review).
                ->when(app(ReaderGuard::class)->fault() !== null, static fn (Builder $query): Builder => $query->whereRaw('0 = 1')))
            ->columns([
                TextColumn::make('reader_id')->label(__('kitsune::entitlements.column.reader')),
                TextColumn::make('entitlement')->label(__('kitsune::entitlements.column.entitlement')),
                TextColumn::make('source')->label(__('kitsune::entitlements.column.source'))
                    ->formatStateUsing(static fn (string $state, Entitlement $record): string => $record->isComp() ? __('kitsune::entitlements.source.comp') : $state),
                TextColumn::make('state')->label(__('kitsune::entitlements.column.state'))
                    ->state(static fn (Entitlement $record): string => self::stateOf($record, $now))
                    ->formatStateUsing(static fn (string $state): string => __('kitsune::entitlements.state.'.$state))
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'live' => 'success',
                        'lapsed' => 'gray',
                        default => 'danger',
                    }),
                SiteTime::columnOr('expires_at', __('kitsune::entitlements.ends.none'))->label(__('kitsune::entitlements.column.ends')),
                SiteTime::column('changed_at')->label(__('kitsune::entitlements.column.changed')),
            ])
            ->filters(self::filters($now), layout: FiltersLayout::AboveContent)
            // Said here, not left to Filament's default: a host's `Table::configureUsing()` could switch it on (review).
            ->persistFiltersInSession(false)
            ->recordActions([self::historyAction(), self::revokeAction()])
            ->toolbarActions([])
            ->defaultSort('changed_at', 'desc')
            ->paginated([25])
            ->defaultPaginationPageOption(25)
            // Under a filter, "nothing has been given" would be false of the site: the answer is only that nothing matches.
            ->emptyStateHeading(static fn (HasTable $livewire): string => __(self::narrowed($livewire) ? 'kitsune::entitlements.empty.filtered' : 'kitsune::entitlements.empty.heading'))
            ->emptyStateDescription(static fn (HasTable $livewire): ?string => self::narrowed($livewire) ? null : __('kitsune::entitlements.empty.description'));
    }

    /** Whether any filter narrows the rows — read from the applied state, since Filament's own test misses an exact value. */
    private static function narrowed(HasTable $livewire): bool
    {
        $filters = $livewire instanceof self ? (array) $livewire->tableFilters : [];

        foreach (['reader', 'entitlement', 'source', 'state'] as $name) {
            if (filled($filters[$name]['value'] ?? null)) {
                return true;
            }
        }

        return (bool) ($filters['comps']['isActive'] ?? false);
    }

    /**
     * One row's state at `$now`: the model's own rule, so the badge and the state filter cannot disagree with the check.
     *
     * @return 'live'|'lapsed'|'revoked'
     */
    public static function stateOf(Entitlement $row, CarbonImmutable $now): string
    {
        return $row->revoked_at !== null ? 'revoked' : ($row->isLiveAt($now) ? 'live' : 'lapsed');
    }

    /**
     * Exact matches, each binding only what its column's one encoding accepts, and the state composed from `liveAt`.
     *
     * ⚠️ NOTHING PERSISTED: no `#[Url]`, no `persistFiltersInSession()`, so a reader's identifier reaches no address, no
     * `Referer`, no access log and no session.
     *
     * @return list<BaseFilter>
     */
    private static function filters(CarbonImmutable $now): array
    {
        return [
            self::exact('reader', 'reader_id', 'exact', static fn (string $value): ?string => app(ReaderGuard::class)->key($value)),
            self::exact('entitlement', 'entitlement', 'exact', static fn (string $value): ?string => EntitlementName::isName($value) ? $value : null),
            // Not "as shown": the column shows a comp as "Comp", which is no source — comps have their own toggle (review).
            self::exact('source', 'source', 'source_help', static fn (string $value): ?string => EntitlementSource::isSource($value) ? $value : null),
            Filter::make('comps')
                ->label(__('kitsune::entitlements.filter.comps'))
                ->toggle()
                ->query(static fn (Builder $query): Builder => $query->where('entitlements.source', EntitlementSource::COMP)),
            SelectFilter::make('state')
                ->label(__('kitsune::entitlements.filter.state'))
                ->options([
                    'live' => __('kitsune::entitlements.state.live'),
                    'lapsed' => __('kitsune::entitlements.state.lapsed'),
                    'revoked' => __('kitsune::entitlements.state.revoked'),
                ])
                ->query(static fn (Builder $query, array $data): Builder => self::inState($query, $data['value'] ?? null, $now)),
        ];
    }

    /**
     * The state filter, composed from the model's own `liveAt` so it cannot drift from the check: revoked is set,
     * lapsed is neither revoked nor live.
     *
     * @param  Builder<Entitlement>  $query
     * @return Builder<Entitlement>
     */
    private static function inState(Builder $query, mixed $state, CarbonImmutable $now): Builder
    {
        return match ($state) {
            'live' => $query->liveAt($now),
            'revoked' => $query->whereNotNull('entitlements.revoked_at'),
            'lapsed' => $query->whereNull('entitlements.revoked_at')->whereNot(static fn (Builder $live) => $live->liveAt($now)),
            default => $query,
        };
    }

    /**
     * An exact match on one column, binding only what the column's one encoding accepts, and otherwise nothing at all.
     *
     * @param  Closure(string): ?string  $accept
     */
    private static function exact(string $name, string $column, string $help, Closure $accept): Filter
    {
        return Filter::make($name)
            ->schema([
                TextInput::make('value')
                    ->label(__('kitsune::entitlements.filter.'.$name))
                    ->helperText(__('kitsune::entitlements.filter.'.$help)),
            ])
            ->query(static function (Builder $query, array $data) use ($column, $accept): Builder {
                $value = $data['value'] ?? null;

                if (! is_string($value) || $value === '') {
                    return $query;
                }

                $accepted = $accept($value);

                return $accepted === null ? $query->whereRaw('0 = 1') : $query->where('entitlements.'.$column, $accepted);
            });
    }

    // ---- Reads -------------------------------------------------------------------------------------------------------

    /**
     * The page of rows, or a refusal carrying the SQLSTATE alone.
     *
     * @return Collection<array-key, Model>|Paginator<array-key, Model>|CursorPaginator<array-key, Model>
     */
    public function getTableRecords(): Collection|Paginator|CursorPaginator
    {
        return self::mapped(fn (): Collection|Paginator|CursorPaginator => $this->unmappedTableRecords());
    }

    /**
     * The row an action names, or a refusal carrying the SQLSTATE alone.
     *
     * @return Model|array<string, mixed>|null
     */
    public function getTableRecord(?string $key): Model|array|null
    {
        return self::mapped(fn (): Model|array|null => $this->unmappedTableRecord($key));
    }

    public function getAllTableRecordsCount(): int
    {
        return self::mapped(fn (): int => $this->unmappedAllTableRecordsCount());
    }

    /** @return array<string> */
    public function getAllSelectableTableRecordKeys(): array
    {
        return self::mapped(fn (): array => $this->unmappedAllSelectableTableRecordKeys());
    }

    public function getAllSelectableTableRecordsCount(): int
    {
        return self::mapped(fn (): int => $this->unmappedAllSelectableTableRecordsCount());
    }

    /** @return EloquentCollection<int, Model>|Collection<array-key, mixed>|LazyCollection<array-key, mixed> */
    public function getSelectedTableRecords(bool $shouldFetchSelectedRecords = true, ?int $chunkSize = null): EloquentCollection|Collection|LazyCollection
    {
        return self::mapped(fn (): EloquentCollection|Collection|LazyCollection => $this->unmappedSelectedTableRecords($shouldFetchSelectedRecords, $chunkSize));
    }

    /**
     * ⚠️ EVERY READ THAT BINDS A FILTER'S VALUE COMES THROUGH HERE: the count and the page a render makes, the row an
     * action resolves through the filtered query, and the four selection and count reads above — which nothing on this
     * page calls, and a browser can call by name (review). A group's keys need a grouping this table does not have,
     * and fail before any query. History binds a row's id and staff ids; the writer maps its
     * own.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    private static function mapped(Closure $read): mixed
    {
        try {
            return $read();
        } catch (QueryException $e) {
            throw self::unread((string) ($e->errorInfo[0] ?? $e->getCode()));
        } catch (PDOException $e) {
            // ⚠️ A `DeadlockException` — Laravel's for a deadlock or a lock wait inside a transaction, a host's
            // `databaseTransactions()` panel's included — is a PDOException whose message is the query's, bindings and all.
            throw self::unread(isset($e->errorInfo[0]) && is_string($e->errorInfo[0])
                ? $e->errorInfo[0]
                : (preg_match('/SQLSTATE\[(\w{5})\]/', $e->getMessage(), $match) === 1 ? $match[1] : (string) $e->getCode()));
        }
    }

    private static function unread(string $state): RuntimeException
    {
        return new RuntimeException(sprintf(
            'The entitlements could not be read: the database refused (SQLSTATE %s). Its message is not repeated, because it can carry a reader\'s identifier.',
            $state,
        ));
    }

    // ---- Comp --------------------------------------------------------------------------------------------------------

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return app(ReaderGuard::class)->fault() === null ? [self::compAction()] : [];
    }

    /**
     * Give a comp by hand: a reader, a name, and an end said out loud.
     *
     * ⚠️ "ENDS" HAS NO DEFAULT (Adam, answer 2): "no end" is always chosen, never the reading of a forgotten date. The
     * only rules are `required` and the picker's `date` — the writer is the validation, and its refusal keeps the form
     * open with what was typed (answer 3). Nothing reads the action's arguments, so a link opens an empty form.
     */
    public static function compAction(): Action
    {
        return Action::make('comp')
            ->label(__('kitsune::entitlements.comp.action'))
            ->authorize(static fn (): bool => self::canAccess())
            // The writer owns its transaction, and a host's `databaseTransactions()` would hold the site's lock till commit.
            ->databaseTransaction(false)
            ->modalHeading(__('kitsune::entitlements.comp.heading'))
            ->modalDescription(__('kitsune::entitlements.comp.description'))
            ->modalSubmitActionLabel(__('kitsune::entitlements.comp.submit'))
            ->schema([
                // No `maxLength`: a browser silently truncates a paste, where the writer refuses instead.
                TextInput::make('reader')
                    ->label(__('kitsune::entitlements.comp.reader'))
                    ->helperText(__('kitsune::entitlements.comp.reader_help'))
                    ->required(),
                TextInput::make('entitlement')
                    ->label(__('kitsune::entitlements.comp.entitlement'))
                    ->helperText(__('kitsune::entitlements.comp.entitlement_help'))
                    ->required(),
                Radio::make('ends')
                    ->label(__('kitsune::entitlements.comp.ends'))
                    ->options([
                        'none' => __('kitsune::entitlements.comp.ends_none'),
                        'on' => __('kitsune::entitlements.comp.ends_on'),
                    ])
                    ->required()
                    ->live(),
                SiteTime::picker('until')
                    ->label(__('kitsune::entitlements.comp.until'))
                    ->required()
                    ->visible(static fn (Get $get): bool => $get('ends') === 'on'),
            ])
            ->action(static function (array $data, Action $action): void {
                $given = self::giveComp(
                    (string) ($data['reader'] ?? ''),
                    (string) ($data['entitlement'] ?? ''),
                    ($data['ends'] ?? null) === 'on' && is_string($data['until'] ?? null) ? $data['until'] : null,
                );

                if (! $given) {
                    $action->halt();
                }
            });
    }

    /**
     * Give a comp as the signed-in owner, and say what happened — or, refused, the writer's own words. False when refused,
     * so the modal stays open.
     *
     * ⚠️ `$until` IS WALL-CLOCK TIME IN THE APPLICATION'S ZONE: the picker reads the site's and hands back the app's,
     * never UTC by assumption.
     */
    public static function giveComp(#[\SensitiveParameter] string $reader, string $entitlement, ?string $until): bool
    {
        abort_unless(self::canAccess(), 403);

        try {
            $outcome = app(EntitlementWriter::class)->comp(
                $reader,
                $entitlement,
                $until === null ? null : CarbonImmutable::parse($until, (string) config('app.timezone')),
            );
        } catch (EntitlementRefused $refused) {
            self::refused(__($refused->mayHaveApplied ? 'kitsune::entitlements.comp.uncertain' : 'kitsune::entitlements.comp.refused'), $refused);

            return false;
        }

        Notification::make()->success()->title(e(__('kitsune::entitlements.comp.'.match ($outcome) {
            GrantOutcome::Granted => 'granted',
            GrantOutcome::Extended => 'extended',
            GrantOutcome::Reinstated => 'reinstated',
            GrantOutcome::Unchanged => 'unchanged',
            // A false "already comped" would be worse than an error.
            GrantOutcome::StillRevoked => throw new LogicException('comp() never answers StillRevoked (EntitlementWriter::give()).'),
        })))->send();

        return true;
    }

    // ---- Revoke ------------------------------------------------------------------------------------------------------

    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('kitsune::entitlements.revoke.action'))
            ->color('danger')
            // Live OR LAPSED: a lapsed source is revoked so that a later grant of it cannot extend it.
            ->visible(static fn (Entitlement $record): bool => $record->revoked_at === null)
            ->authorize(static fn (): bool => self::canAccess())
            ->databaseTransaction(false)
            ->requiresConfirmation()
            ->modalHeading(static fn (Entitlement $record): string => $record->isComp()
                ? __('kitsune::entitlements.revoke.heading_comp')
                : __('kitsune::entitlements.revoke.heading', ['source' => $record->source]))
            ->modalDescription(static fn (Entitlement $record): string => __($record->isComp()
                ? 'kitsune::entitlements.revoke.description_comp'
                : 'kitsune::entitlements.revoke.description'))
            ->modalSubmitActionLabel(__('kitsune::entitlements.revoke.submit'))
            ->action(static fn (Entitlement $record) => self::revokeRow($record));
    }

    /**
     * Revoke one source: the resolved row's own reader, name and source, never anything a client sent.
     *
     * ⚠️ THE 403 IS LOAD-BEARING: with nobody signed in the writer trusts its caller as the system. And every refusal is
     * caught — under a guard fault, or for a row whose reader no longer fits the guard's key, the writer refuses.
     */
    public static function revokeRow(Entitlement $row): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $revoked = app(EntitlementWriter::class)->revoke($row->reader_id, $row->entitlement, $row->source);
        } catch (EntitlementRefused $refused) {
            self::refused(__($refused->mayHaveApplied ? 'kitsune::entitlements.revoke.uncertain' : 'kitsune::entitlements.revoke.refused'), $refused);

            return;
        }

        $revoked
            ? Notification::make()->success()->title(e(__('kitsune::entitlements.revoke.revoked')))->send()
            : Notification::make()->info()->title(e(__('kitsune::entitlements.revoke.nothing')))->send();
    }

    // ---- History -----------------------------------------------------------------------------------------------------

    public static function historyAction(): Action
    {
        return Action::make('history')
            ->label(__('kitsune::entitlements.history.action'))
            ->color('gray')
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading(static fn (Entitlement $record): string => __('kitsune::entitlements.history.heading', [
                'entitlement' => $record->entitlement,
                'source' => $record->isComp() ? __('kitsune::entitlements.source.comp') : $record->source,
            ]))
            // Built when opened, never at render: its queries run on demand.
            ->schema(static fn (Entitlement $record): array => self::historyOf($record))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('kitsune::entitlements.history.close'));
    }

    /**
     * One source's changes, oldest first: what, when, and by whom.
     *
     * @return list<Component>
     */
    public static function historyOf(Entitlement $row): array
    {
        abort_unless(self::canAccess(), 403);

        $records = AuditLog::query()->for($row)->orderBy('id')->get(['id', 'action', 'actor_type', 'actor_id', 'created_at']);

        if ($records->isEmpty()) {
            return [Text::make(__('kitsune::entitlements.history.empty'))];
        }

        $names = AuditActors::of($records);

        return [
            RepeatableEntry::make('history')
                ->hiddenLabel()
                ->state($records->map(static fn (AuditLog $record): array => [
                    'change' => self::actionLabel($record->action),
                    'when' => $record->created_at,
                    'by' => $names[$record->id],
                ])->all())
                ->table([
                    TableColumn::make(__('kitsune::entitlements.history.change')),
                    TableColumn::make(__('kitsune::entitlements.history.when')),
                    TableColumn::make(__('kitsune::entitlements.history.by')),
                ])
                // Each cell's label is its column's header, so it is not shown again inside the cell; Filament still
                // repeats it there for a screen reader, as an entry's term (review: an empty label is regenerated).
                ->schema([
                    TextEntry::make('change')->hiddenLabel(),
                    SiteTime::entry('when')->hiddenLabel(),
                    TextEntry::make('by')->hiddenLabel(),
                ]),
        ];
    }

    public static function actionLabel(string $action): string
    {
        return isset(self::ACTIONS[$action]) ? __('kitsune::entitlements.history.'.self::ACTIONS[$action]) : $action;
    }

    /**
     * A refusal, in the writer's own words, escaped and left on screen until it is read.
     *
     * ⚠️ ITS TITLE NEVER SAYS "NOT" OVER A COMMIT THE DATABASE REFUSED: whether that change applied cannot be told, and
     * the body says so (review).
     */
    private static function refused(string $title, EntitlementRefused $refused): void
    {
        Notification::make()->danger()->persistent()->title(e($title))->body(e($refused->getMessage()))->send();
    }
}
