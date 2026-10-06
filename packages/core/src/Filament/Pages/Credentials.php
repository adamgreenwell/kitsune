<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Models\Contracts\HasName;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentColor;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\Components\ButtonComponent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Credentials\CredentialState;
use Kitsune\Core\Credentials\CredentialStates;
use Kitsune\Core\Credentials\CredentialStatus;
use Kitsune\Core\Credentials\CredentialWriter;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Filament\Schemas\SiteTime;
use Kitsune\Core\Http\Controllers\CredentialSetController;
use Kitsune\Core\Models\AuditLog;
use Kitsune\Core\Models\Credential;
use Kitsune\Core\Tenancy\Context;

/**
 * Where an owner sets, replaces and removes the org's credentials, and switches it between test and live mode — ADR-040,
 * its admin half.
 *
 * ⚠️ IT NEVER HAS A VALUE IN HAND. Everything it renders comes from declared names and row metadata: status, when, by
 * whom, label, help and the declared format. A value reaches the server once, as the body of the plain form in the
 * Set modal, POSTed to `CredentialSetController` — never through Livewire, because a Livewire field's value returns in
 * the response snapshot when its save is refused. Remove and the mode switch carry no value, so they are ordinary
 * confirmed actions.
 *
 * ⚠️ OWNER-ONLY, AND THE NULL USER IS THE LOAD-BEARING HALF. The writer trusts its caller when nobody is signed in, so
 * the page, each action and each handler refuse a missing user here, as the controller does (ADR-033, ADR-040).
 *
 * ⚠️ EVERY PUBLIC METHOD THIS CLASS ADDS IS STATIC. Livewire lets a browser call a component's public instance methods,
 * never its static ones, so the page adds no browser endpoint; `CredentialPageTest` asserts it by reflection. The
 * statics are public for that test, as `MediaVisibilityActions`' are.
 *
 * ⚠️ AND IT OPENS NOTHING. Status comes from `CredentialStates::of()`, which reads the key id beside the ciphertext;
 * "Set" says a value is stored, not that it works, and the intro says so.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class Credentials extends Page
{
    // Every Livewire class core ships restricts uploads to its schema's own fields (`UploadSurfaceTest`); this one has none.
    use RestrictsFileUploadsToSchemaComponents;

    protected static ?string $slug = 'credentials';

    // Navigation is explicit (`KitsunePanel::navigation()`), and hidden until a module declares something to keep.
    protected static bool $shouldRegisterNavigation = false;

    /** The statuses under which a line holds a value: Replace and Remove, rather than Set. */
    private const HOLDING = [CredentialStatus::Set, CredentialStatus::SetUnderPreviousKey, CredentialStatus::Unreadable];

    // ---- Access ------------------------------------------------------------------------------------------------------

    /**
     * An owner of the org in context, as `RoleResource` asks it — no permission subject, because `credential.manage`
     * would be refused by vocabulary even for an owner (`Permissions::allows()`).
     */
    public static function canAccess(): bool
    {
        $user = Permissions::currentUser();

        return $user !== null && Permissions::isOwner($user);
    }

    /** The sidebar link: for whoever may open the page, once a module declares something to keep (Adam, answer 3). */
    public static function belongsInNavigation(): bool
    {
        return self::canAccess() && app(CredentialSlots::class)->all() !== [];
    }

    public function getTitle(): string
    {
        return __('kitsune::credentials.title');
    }

    // ---- Lines -------------------------------------------------------------------------------------------------------

    /**
     * Every declared credential at every mode it keeps, in the order modules declared them.
     *
     * ⚠️ EACH LINE'S MODE IS PASSED, so `of()` never reads the org's mode again, and the render costs a fixed count of
     * queries: one for the mode, one per line, two for the audit log and at most one for its actors.
     *
     * @return list<array{slot: CredentialSlot, mode: ?CredentialMode, state: CredentialState, inUse: bool, by: ?string}>
     */
    public static function lines(): array
    {
        $slots = app(CredentialSlots::class)->all();

        if ($slots === []) {
            return [];
        }

        $states = app(CredentialStates::class);
        $orgMode = app(CredentialSlots::class)->hasModed() ? $states->mode() : null;
        $lines = [];

        foreach ($slots as $slot) {
            foreach ($slot->moded ? [CredentialMode::Test, CredentialMode::Live] : [null] as $mode) {
                $lines[] = [
                    'slot' => $slot,
                    'mode' => $mode,
                    'state' => $states->of($slot->name, $mode),
                    'inUse' => $mode !== null && $mode === $orgMode,
                    'by' => null,
                ];
            }
        }

        $by = self::changedBy(array_values(array_filter(array_map(
            static fn (array $line): ?int => $line['state']->rowId,
            $lines,
        ))));

        foreach ($lines as $index => $line) {
            $lines[$index]['by'] = $line['state']->rowId !== null ? ($by[$line['state']->rowId] ?? null) : null;
        }

        return $lines;
    }

    /** A line's name in a heading or a notice: the module's label, and the mode for a credential kept per mode. */
    public static function labelOf(CredentialSlot $slot, ?CredentialMode $mode): string
    {
        $label = __($slot->label);

        return $mode === null ? $label : __('kitsune::credentials.line.moded', [
            'label' => $label,
            'mode' => __('kitsune::credentials.line.'.$mode->value),
        ]);
    }

    public static function holdsValue(CredentialState $state): bool
    {
        return in_array($state->status, self::HOLDING, true);
    }

    /**
     * What a value for this line looks like, from the declaration alone — never a piece of anything stored.
     *
     * "The line decides" where nothing in a value can: a credential kept per mode whose modes share a prefix, or that
     * declares none (ADR-040).
     */
    public static function formatOf(CredentialSlot $slot, ?CredentialMode $mode): string
    {
        $parts = [];
        $prefixes = $slot->prefixesFor($mode);

        if ($prefixes !== []) {
            $parts[] = __('kitsune::credentials.format.begins', [
                'prefixes' => implode(__('kitsune::credentials.format.or'), $prefixes),
            ]);
        }

        if ($slot->moded) {
            $test = $slot->prefixesFor(CredentialMode::Test);

            if ($test === [] || array_intersect($test, $slot->prefixesFor(CredentialMode::Live)) !== []) {
                $parts[] = __('kitsune::credentials.format.line_decides');
            }
        }

        $parts[] = __('kitsune::credentials.format.length', ['min' => $slot->minLength, 'max' => $slot->maxLength]);

        return implode(' ', $parts);
    }

    /**
     * A line's key, for its actions and its form's ids: the name with its one dot as `__`, then the mode.
     *
     * ⚠️ `__`, NOT `-`: a name may hold hyphens but never an underscore, so `fx.payment-key` and `fx-payment.key` stay
     * apart. And dotless, because Filament finds an action in a schema by a dot-separated path.
     */
    public static function keyOf(CredentialSlot $slot, ?CredentialMode $mode): string
    {
        return str_replace('.', '__', $slot->name).'-'.($mode->value ?? 'none');
    }

    /**
     * Who last changed each row, by row id: the latest set, replace or removal in the audit log, named only through the
     * panel's own user model, so a departed member is not found and a class a row names is never built.
     *
     * @param  list<int>  $rowIds
     * @return array<int, string>
     */
    private static function changedBy(array $rowIds): array
    {
        if ($rowIds === []) {
            return [];
        }

        $latest = AuditLog::query()
            ->where('target_type', (new Credential)->getMorphClass())
            ->whereIn('target_id', array_map('strval', $rowIds))
            ->whereIn('action', [CredentialWriter::SET, CredentialWriter::REPLACED, CredentialWriter::REMOVED])
            ->groupBy('target_id')
            ->selectRaw('max(id) as latest')
            ->pluck('latest')
            ->all();

        $records = AuditLog::query()->whereKey($latest)->get(['id', 'target_id', 'actor_type', 'actor_id']);
        $model = Permissions::userModel();
        $morph = $model !== null ? (new $model)->getMorphClass() : null;

        $ids = $records
            ->filter(static fn (AuditLog $record): bool => $morph !== null && $record->actor_type === $morph && $record->actor_id !== null)
            ->map(static fn (AuditLog $record): string => (string) $record->actor_id)
            ->unique()
            ->values()
            ->all();

        $users = [];

        if ($model !== null && $ids !== []) {
            foreach ($model::query()->whereKey(Permissions::userKeys($ids, $model))->get() as $user) {
                $users[(string) $user->getKey()] = $user;
            }
        }

        $by = [];

        foreach ($records as $record) {
            $by[(int) $record->target_id] = match (true) {
                $record->actor_type === null => __('kitsune::credentials.who.system'),
                $record->actor_type !== $morph => __('kitsune::credentials.who.other'),
                isset($users[(string) $record->actor_id]) => self::nameOf($users[(string) $record->actor_id]),
                default => __('kitsune::credentials.who.former'),
            };
        }

        return $by;
    }

    private static function nameOf(Model $user): string
    {
        if ($user instanceof HasName) {
            return $user->getFilamentName();
        }

        foreach (['name', 'email'] as $attribute) {
            $value = $user->getAttributeValue($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$user->getKey();
    }

    // ---- Content -----------------------------------------------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        $slots = app(CredentialSlots::class);

        if ($slots->all() === []) {
            return $schema->components([
                EmptyState::make(__('kitsune::credentials.empty.heading'))
                    ->description(__('kitsune::credentials.empty.description'))
                    ->icon(Heroicon::OutlinedLockClosed),
            ]);
        }

        $lines = self::lines();
        // The mode the lines were read under, rather than a second read that could disagree with them.
        $inUse = array_values(array_filter($lines, static fn (array $line): bool => $line['inUse']));
        $orgMode = $inUse[0]['mode'] ?? null;
        $components = [Text::make(__('kitsune::credentials.intro', ['org' => self::orgName()]))];

        if ($orgMode !== null) {
            $components[] = Section::make(__('kitsune::credentials.mode.heading'))->schema([
                Text::make(__('kitsune::credentials.mode.'.$orgMode->value))
                    ->badge()
                    ->color($orgMode === CredentialMode::Live ? 'danger' : 'gray'),
                Text::make(__('kitsune::credentials.mode.'.$orgMode->value.'_meaning')),
            ]);
        }

        foreach ($slots->all() as $slot) {
            $own = array_values(array_filter($lines, static fn (array $line): bool => $line['slot'] === $slot));

            $components[] = Section::make(__($slot->label))
                ->description(__($slot->help))
                ->schema($slot->moded
                    ? array_map(static fn (array $line): Fieldset => Fieldset::make(__('kitsune::credentials.mode.'.$line['mode']?->value))
                        ->columns(1)
                        ->schema(self::lineComponents($line)), $own)
                    : [Group::make(self::lineComponents($own[0]))]);
        }

        return $schema->components($components);
    }

    /**
     * One line: its status, whether it is in use, a note where the status has one, when, by whom, and its actions.
     *
     * @param  array{slot: CredentialSlot, mode: ?CredentialMode, state: CredentialState, inUse: bool, by: ?string}  $line
     * @return list<Component>
     */
    private static function lineComponents(array $line): array
    {
        ['slot' => $slot, 'mode' => $mode, 'state' => $state] = $line;
        $key = self::keyOf($slot, $mode);

        [$status, $color, $note] = self::badgeOf($state->status);

        $badges = [Text::make($status)->badge()->color($color)->grow(false)];

        if ($line['inUse']) {
            $badges[] = Text::make(__('kitsune::credentials.line.in_use'))->badge()->color('primary')->grow(false);
        }

        $components = [Flex::make($badges)];

        if ($note !== null) {
            $components[] = Text::make($note);
        }

        $components[] = Text::make(self::formatOf($slot, $mode))->color('gray');

        if ($state->changedAt !== null) {
            $components[] = SiteTime::entry('changed_'.str_replace('-', '_', $key))
                ->label(__('kitsune::credentials.line.changed'))
                ->state($state->changedAt)
                ->inlineLabel();
        }

        if ($line['by'] !== null) {
            $components[] = TextEntry::make('by_'.str_replace('-', '_', $key))
                ->label(__('kitsune::credentials.line.by'))
                ->state($line['by'])
                ->inlineLabel();
        }

        $components[] = Actions::make([
            self::setActionFor($slot, $mode, $state),
            self::removeActionFor($slot, $mode, $state, $line['inUse'] || $mode === null),
        ])->key('credential-'.$key);

        return $components;
    }

    /**
     * What a line says for a status: its badge, the badge's colour, and the note beneath, where it has one.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public static function badgeOf(CredentialStatus $status): array
    {
        [$key, $color, $note] = match ($status) {
            CredentialStatus::NotSet => ['not_set', 'gray', null],
            CredentialStatus::Removed => ['removed', 'gray', null],
            // ⚠️ Never green: "Set" is not a verdict on whether the provider accepts the value.
            CredentialStatus::Set => ['set', 'info', null],
            CredentialStatus::SetUnderPreviousKey => ['previous_key', 'warning', 'previous_key'],
            CredentialStatus::Unreadable => ['unreadable', 'danger', 'unreadable'],
        };

        return [__('kitsune::credentials.status.'.$key), $color, $note !== null ? __('kitsune::credentials.note.'.$note) : null];
    }

    private static function orgName(): string
    {
        return (string) app(Context::class)->org()?->name;
    }

    // ---- Set and replace ---------------------------------------------------------------------------------------------

    /**
     * Set or Replace on one line: a modal holding a plain form, and nothing else.
     *
     * ⚠️ NO SCHEMA, NO HANDLER, AND NO ARGUMENTS READ. Built per line, its closures capture the declared credential and
     * mode; Filament finds it again through the line's key in the server-built schema, so arguments a client forges are
     * merged into an action that never reads them. `formWrapper(false)` keeps the modal a `div`, so the form is not
     * nested inside Filament's own and dropped.
     */
    public static function setActionFor(CredentialSlot $slot, ?CredentialMode $mode, CredentialState $state): Action
    {
        $replacing = self::holdsValue($state);
        $line = self::labelOf($slot, $mode);

        return Action::make('set')
            ->label(__($replacing ? 'kitsune::credentials.set.replace' : 'kitsune::credentials.set.set'))
            ->extraAttributes(['aria-label' => __($replacing ? 'kitsune::credentials.aria.replace' : 'kitsune::credentials.aria.set', ['line' => $line])])
            ->color($replacing ? 'gray' : 'primary')
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading(__($replacing ? 'kitsune::credentials.set.heading_replace' : 'kitsune::credentials.set.heading_set', ['line' => $line]))
            ->modalDescription(implode(' ', array_filter([
                __('kitsune::credentials.set.description'),
                $mode !== null ? __('kitsune::credentials.set.description_mode', ['mode' => __('kitsune::credentials.line.'.$mode->value)]) : null,
                $replacing ? __('kitsune::credentials.set.description_replace') : null,
            ])))
            ->modalContent(static fn (): HtmlString => self::valueForm(self::setUrl(), (string) csrf_token(), $slot, $mode))
            ->formWrapper(false)
            ->modalSubmitAction(false);
    }

    /** Where the form posts: a path under the current site, with no host, query or fragment. */
    public static function setUrl(): string
    {
        return app(KitsunePanel::PANEL_BINDING)->route(CredentialSetController::ROUTE, ['tenant' => Filament::getTenant()], absolute: false);
    }

    /**
     * The form, and the one place a value is typed.
     *
     * ⚠️ RULES, EACH ASSERTED BY `CredentialPageTest` (and the double submit in a browser, by `credentials.spec.js`):
     * - the password input never carries a `value`, and has no `maxlength` or `minlength` — a browser silently truncates
     *   a paste, where the server refuses instead;
     * - the field is named `password` on purpose: Laravel's `$dontFlash` and `TrimStrings` skip that name, and many error
     *   reporters scrub it;
     * - `wire:key` carries the line, and there is no `wire:ignore`, because the modal's own key ignores which line opened
     *   it;
     * - the submit handler only disables the button, which has no name. It never clears the field: the submit event
     *   fires before the browser collects the form's data, so clearing it there would send nothing;
     * - every interpolation is escaped.
     */
    public static function valueForm(string $action, string $token, CredentialSlot $slot, ?CredentialMode $mode): HtmlString
    {
        $key = self::keyOf($slot, $mode);
        $id = 'credential-value-'.$key;

        return new HtmlString(sprintf(
            '<form method="post" action="%s" autocomplete="off" wire:key="credential-form-%s" x-data'
            .' x-on:submit="$el.querySelector(\'[type=submit]\').disabled = true" style="display:grid;gap:0.75rem">'
            .'<input type="hidden" name="_token" value="%s">'
            .'<input type="hidden" name="slot" value="%s">'
            .'%s'
            .'<label for="%s" class="fi-fo-field-label"><span class="fi-fo-field-label-content">%s</span></label>'
            .'<div class="fi-input-wrp"><input id="%s" class="fi-input" type="password" name="%s" required autocomplete="off"'
            .' spellcheck="false" autocapitalize="off" autocorrect="off" data-1p-ignore data-lpignore="true" data-bwignore'
            .' data-form-type="other" aria-describedby="%s-format"></div>'
            .'<p id="%s-format" style="font-size:0.875rem">%s</p>'
            .'<div><button type="submit" class="fi-btn fi-size-md %s">%s</button></div>'
            .'</form>',
            e($action),
            e($key),
            e($token),
            e($slot->name),
            $mode !== null ? sprintf('<input type="hidden" name="mode" value="%s">', e($mode->value)) : '',
            e($id),
            e(__('kitsune::credentials.set.field')),
            e($id),
            e(CredentialSetController::FIELD),
            e($id),
            e($id),
            e(self::formatOf($slot, $mode)),
            e(implode(' ', FilamentColor::getComponentClasses(ButtonComponent::make(), 'primary'))),
            e(__('kitsune::credentials.set.submit')),
        ));
    }

    // ---- Remove ------------------------------------------------------------------------------------------------------

    public static function removeActionFor(CredentialSlot $slot, ?CredentialMode $mode, CredentialState $state, bool $inUse): Action
    {
        $line = self::labelOf($slot, $mode);

        return Action::make('remove')
            ->label(__('kitsune::credentials.remove.action'))
            ->extraAttributes(['aria-label' => __('kitsune::credentials.aria.remove', ['line' => $line])])
            ->color('danger')
            ->visible(self::holdsValue($state))
            ->authorize(static fn (): bool => self::canAccess())
            // The writer owns its transaction, and a host's `databaseTransactions()` would hold the org's lock till commit.
            ->databaseTransaction(false)
            ->requiresConfirmation()
            ->modalHeading(__('kitsune::credentials.remove.heading', ['line' => $line]))
            ->modalDescription(__('kitsune::credentials.remove.description')
                .($inUse ? ' '.__('kitsune::credentials.remove.in_use') : ''))
            ->modalSubmitActionLabel(__('kitsune::credentials.remove.submit'))
            ->action(static function (self $livewire) use ($slot, $mode): void {
                self::removeLine($slot, $mode);
                self::reload($livewire);
            });
    }

    /**
     * Remove one line's value.
     *
     * ⚠️ THE STATE IS READ FIRST, because the writer is silent when nothing is stored: a removal that lands between the
     * page's build and this handler says "nothing was removed" rather than claim one. A page left open after another tab
     * removed the value no longer offers Remove at all — the action is hidden in the schema rebuilt on the next request —
     * so the click resolves nothing, and the reload shows the line as it is.
     */
    public static function removeLine(CredentialSlot $slot, ?CredentialMode $mode): void
    {
        abort_unless(self::canAccess(), 403);

        $line = self::labelOf($slot, $mode);

        if (! self::holdsValue(app(CredentialStates::class)->of($slot->name, $mode))) {
            Notification::make()->info()->title(e(__('kitsune::credentials.remove.nothing', ['line' => $line])))->send();

            return;
        }

        try {
            app(CredentialWriter::class)->remove($slot->name, $mode);
        } catch (CredentialRefused $refused) {
            self::refused(__('kitsune::credentials.remove.refused', ['line' => $line]), $refused);

            return;
        }

        Notification::make()->success()->title(e(__('kitsune::credentials.remove.removed', ['line' => $line])))->send();
    }

    // ---- The mode ----------------------------------------------------------------------------------------------------

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        if (! app(CredentialSlots::class)->hasModed()) {
            return [];
        }

        $mode = app(CredentialStates::class)->mode();

        return [
            self::switchActionTo(CredentialMode::Live)->visible($mode !== CredentialMode::Live),
            self::switchActionTo(CredentialMode::Test)->visible($mode !== CredentialMode::Test),
        ];
    }

    /**
     * Switch to one fixed mode — two actions, never one that works out "the other" when clicked, so a page left open
     * after another tab switched cannot switch back by accident.
     *
     * ⚠️ NO PASSWORD RE-ENTRY YET. Adam's answer 4 gives it to the payments slice, before the first live payment;
     * `CredentialPageTest` pins its absence so that slice flips the test on purpose.
     */
    public static function switchActionTo(CredentialMode $to): Action
    {
        $live = $to === CredentialMode::Live;

        return Action::make($live ? 'switchToLive' : 'switchToTest')
            ->label(__($live ? 'kitsune::credentials.switch.to_live' : 'kitsune::credentials.switch.to_test'))
            ->color($live ? 'danger' : 'gray')
            ->authorize(static fn (): bool => self::canAccess())
            ->databaseTransaction(false)
            ->requiresConfirmation()
            ->modalHeading(static fn (): string => __($live ? 'kitsune::credentials.switch.to_live_heading' : 'kitsune::credentials.switch.to_test_heading', ['org' => self::orgName()]))
            ->modalDescription(static fn (): string => $live
                ? trim(__('kitsune::credentials.switch.to_live_description').' '.self::missingLive())
                : __('kitsune::credentials.switch.to_test_description'))
            ->modalSubmitActionLabel(__($live ? 'kitsune::credentials.switch.to_live' : 'kitsune::credentials.switch.to_test'))
            ->action(static function (self $livewire) use ($to): void {
                self::switchOrgTo($to);
                self::reload($livewire);
            });
    }

    /** The sentence naming every credential kept per mode with no usable live value, or nothing when there is none. */
    public static function missingLive(): string
    {
        $states = app(CredentialStates::class);
        $missing = [];

        foreach (app(CredentialSlots::class)->all() as $slot) {
            if ($slot->moded && in_array($states->of($slot->name, CredentialMode::Live)->status, [CredentialStatus::NotSet, CredentialStatus::Removed, CredentialStatus::Unreadable], true)) {
                $missing[] = __($slot->label);
            }
        }

        return $missing === [] ? '' : __('kitsune::credentials.switch.missing', ['labels' => implode(', ', $missing)]);
    }

    public static function switchOrgTo(CredentialMode $to): void
    {
        abort_unless(self::canAccess(), 403);

        $live = $to === CredentialMode::Live;

        try {
            $switched = app(CredentialWriter::class)->switchTo($to);
        } catch (CredentialRefused $refused) {
            self::refused(__('kitsune::credentials.switch.refused'), $refused);

            return;
        }

        if (! $switched) {
            Notification::make()->info()->title(e(__($live ? 'kitsune::credentials.switch.already_live' : 'kitsune::credentials.switch.already_test')))->send();

            return;
        }

        Notification::make()->success()->title(e(__($live ? 'kitsune::credentials.switch.now_live' : 'kitsune::credentials.switch.now_test')))->send();
    }

    /**
     * Every change ends on a fresh GET of the page, as a value's does after its 303.
     *
     * ⚠️ NOT A RE-RENDER: Filament builds the page's content to find the action before running it, and renders from
     * that copy afterwards, so the line would still show the state from before the change (measured in the browser).
     */
    private static function reload(self $livewire): void
    {
        $livewire->redirect(self::getUrl());
    }

    /** A refusal, in the store's own words, escaped and left on screen until it is read. */
    private static function refused(string $title, CredentialRefused $refused): void
    {
        Notification::make()->danger()->persistent()->title(e($title))->body(e($refused->getMessage()))->send();
    }
}
