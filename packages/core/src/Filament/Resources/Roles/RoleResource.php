<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\Roles;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Resources\Roles\Pages\CreateRole;
use Kitsune\Core\Filament\Resources\Roles\Pages\EditRole;
use Kitsune\Core\Filament\Resources\Roles\Pages\ListRoles;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Role;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Validation\Rule;

/**
 * Defining a role, in the admin — issue #84, and the half of RBAC that lives in core.
 *
 * ⚠️ HALF. `roles` and `role_permissions` are org-owned configuration, like `entry_types`, so core owns
 * them and this form. **Assignment is not here**: `role_user` references the host application's `users`
 * table, which core did not create and must not own — the same boundary `org_user` and `site_user` sit on.
 * The skeleton carries that half, through `Role::assignTo()`. Neither half is useful alone, and saying so
 * is the point rather than an apology: a role nobody can be given is as unusable as an assignment screen
 * with no roles in it.
 *
 * ⚠️ OWNER-ONLY, AND THE LIMITATION IS THE SAME ONE THE SCHEMA BUILDER HAS. `architecture.md` publishes a
 * vocabulary of five actions on ENTRIES and nothing else, so there is no `role.manage` to ask for, and
 * inventing a subject widens the extension surface Standing Principle #1 keeps shut until v1.2. An org
 * cannot delegate role administration without making somebody an owner.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $slug = 'roles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    /** Navigation is supplied explicitly by the panel (ADR-012). */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * ⚠️ FILAMENT'S TENANT IS THE SITE AND A ROLE IS ORG-OWNED, so its automatic scope is the wrong scope
     * rather than a missing one — the same reason `EntryTypeResource` opts out, and the same 500 without it:
     * `LogicException: The model [Role] does not have a relationship named [site]`.
     *
     * Isolation comes from `Role` being `#[OrgScoped]` with `EnforcesScope`, which is stronger than the
     * panel's scope here: it applies to every query in the process rather than to this resource's.
     *
     * ⚠️ AND IT 500'd ON THE FIRST REQUEST, with the PHP suite green — the shape ADR-024 says this layer
     * exists to catch, found by issuing a request rather than by reading the code.
     */
    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The form state the role does not own, kept out of the model write.
     *
     * ⚠️ A GRANT IS A ROW IN ANOTHER TABLE, so it cannot ride along in the model's attributes — the same
     * shape as a relation on an entry (ADR-015), and the same trap: `dehydrated(false)` suppresses the leaf
     * and leaves the CONTAINER key in the form data, which the save then tries to write as a column.
     * `SyncsRolePermissions` takes it out and writes it after.
     */
    public const PERMISSION_STATE = 'grants';

    /** The state key for the explicit wildcard, kept out of the per-type map so `*` is never a path. */
    public const ANY_TYPE_STATE = 'grants_any_type';

    /** The state key for who holds this role — `role_user`, which is another table again. */
    public const HOLDER_STATE = 'holders';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Role')->schema([
                TextInput::make('name')->required()->maxLength(255)
                    ->extraAttributes(['dir' => 'auto']),

                /*
                 * ⚠️ `scopedUnique`, NOT Laravel's `unique`. AGENTS.md invariant 3: Laravel's rule does not
                 * go through Eloquent, so it ignores global scopes — and would tell one org that another
                 * org holds `editor`, which is both a false refusal and a disclosure.
                 */
                TextInput::make('handle')->required()->maxLength(255)
                    ->rules(fn (?Role $record): array => [
                        Rule::scopedUnique(Role::class, 'handle', $record?->getKey()),
                    ]),

                /*
                 * ⚠️ THE WIDEST GRANT IN THE SYSTEM, and the helper text says so rather than the label
                 * implying it. An owner bypasses every permission check, including the ones that govern
                 * this page and the schema builder — so the toggle is the one control here that can hand
                 * somebody the whole organisation.
                 *
                 * Taking the LAST one away is refused by `Role` itself, not by this form: an org that loses
                 * its last owner cannot get one back, and a guard that only exists in a form is bypassed by
                 * the API and the console.
                 */
                Toggle::make('is_owner')
                    ->label('Owner')
                    ->helperText('Bypasses every permission check, including the ones protecting this page '
                        .'and the entry type builder. Give it to people who administer the organisation.'),
            ])->columns(2),

            /*
             * ⚠️ ITS OWN SECTION, AND FIRST, because it is not one checkbox among two hundred. A grant here
             * covers entry types that do not exist yet — that is what it is for (ADR-033) and also how
             * somebody hands out more than they meant to. It is offered as a decision rather than hidden in
             * a list where it reads like the others.
             */
            Section::make('Every entry type, including ones added later')
                ->description('A type created next month is covered by these the day it appears. Leave them '
                    .'empty and grant per type below.')
                ->schema([
                    CheckboxList::make(self::ANY_TYPE_STATE)
                        ->hiddenLabel()
                        ->options(self::actionOptions())
                        ->columns(5)
                        ->dehydrated(false),
                ])
                ->collapsible(),

            /*
             * ⚠️ ASSIGNMENT IS HERE, AND ISSUE #84 SAID IT WOULD BE IN THE SKELETON — reversed on evidence,
             * which is what the reasoning was waiting for. The argument for the skeleton was that
             * `role_user` references a `users` table core did not create and must not own, and that still
             * holds: core owns no user model. What changed is that it does not need one. The PANEL names its
             * provider's model (`Permissions::userModel()`), which is the same lesson review taught about the
             * membership check — the provider cannot be wrong about which model it loads, and
             * `config('auth.providers.users.model')` was a guess that failed open.
             *
             * The alternative cost more than it bought: a resource in the skeleton would need a navigation
             * entry, navigation is supplied explicitly by `KitsunePanel` (ADR-012), and letting the host add
             * one means opening an extension point in core before the extension API exists — exactly what
             * Standing Principle #1 keeps shut until v1.2.
             *
             * ⚠️ IT WRITES THROUGH `Role::assignTo()` / `removeFrom()`, which is the audited path. A page
             * that touched the pivot directly would record nothing, and ADR-033 names assignment as the
             * audited security event.
             */
            Section::make('Held by')
                ->description('Everybody in this organisation who has this role. Assigning one is recorded.')
                ->schema([
                    Select::make(self::HOLDER_STATE)
                        ->hiddenLabel()
                        ->multiple()
                        ->searchable()
                        /*
                         * ⚠️ SEARCH RESULTS RATHER THAN A PRELOADED LIST, the same reasoning the relation
                         * picker records: an org's user table is not a dropdown. Smaller than an entry table
                         * today and the same shape of cost at scale.
                         */
                        ->getSearchResultsUsing(self::searchHolders(...))
                        ->getOptionLabelUsing(fn (mixed $value, ?Model $record): ?string => self::holderLabel((int) $value, self::roleKey($record)))
                        /*
                         * ⚠️ THE PLURAL RESOLVER IS NOT OPTIONAL ON A `multiple()` SELECT, and leaving it out
                         * is a 500 rather than a missing label: *"Filament failed to validate the
                         * [data.holders] field's selected options because it did not have an [options()] or
                         * [getOptionLabelsUsing()] configuration."* Filament validates a multi-select's
                         * submitted options through it.
                         *
                         * `FieldValueRenderer::entryPicker()` records the same trap — review found it there
                         * when an unconstrained resolver let a forged id pass form validation — and I read
                         * that docblock and hit it anyway, which is the argument for it being a docblock
                         * rather than a memory.
                         */
                        ->getOptionLabelsUsing(fn (array $values, ?Model $record): array => self::holderLabels($values, self::roleKey($record)))
                        /*
                         * ⚠️ DISABLED WHEN THE IDS WOULD MEAN NOTHING — see `holdersAreAdministrable()`. An
                         * installation whose panel authenticates against a model `role_user` does not
                         * reference can still show this control, and every id it offers would name a different
                         * person in the table the pivot points at. Disabled with the reason on it rather than
                         * silently empty, because "nobody matched" is a lie about the installation.
                         */
                        ->disabled(fn (): bool => ! self::holdersAreAdministrable())
                        ->helperText(fn (): ?string => self::holdersAreAdministrable()
                            ? null
                            : 'Assignment is unavailable: this panel authenticates against a model that '
                              .'role_user does not reference, so the ids here would name different people.')
                        ->dehydrated(false),
                ]),

            ...self::perTypeSections(),
        ]);
    }

    /**
     * Can the ids this panel produces mean anything to `role_user`?
     *
     * ⚠️ THE SELECTOR WAS THE THIRD PLACE THIS CHECK BELONGED AND THE ONE I MISSED. `Permissions::roleIdsFor()`
     * refuses assignments resolved through a model the pivot does not reference, and `Role::effectiveOwners()`
     * refuses to count owners through one — but the holder picker went on listing that model's users, and
     * `syncHolders()` passes whatever ids come back to `assignTo()`. `role_user.user_id` means a row in the
     * table it REFERENCES, so if a matching id exists there, saving the form hands authority to a different
     * person entirely: the panel shows one name and the grant lands on another.
     *
     * Asked in one method rather than three, and the control is disabled rather than merely emptied — an empty
     * search reads as "nobody matched", which is a lie about the installation.
     */
    public static function holdersAreAdministrable(): bool
    {
        $model = Permissions::userModel();

        return $model !== null && Permissions::assignmentsAreAbout($model);
    }

    /**
     * Members of the current org whose name or email matches.
     *
     * ⚠️ THROUGH THE USER MODEL'S OWN SCOPED QUERY, so `#[OrgScopedThroughPivot]` decides who is visible —
     * this must never become a way to enumerate another customer's people. `OrgAwareUserProvider` documents
     * the same exposure from the other side: the carve-out that lets authentication find one user by
     * identifier is deliberately not a licence to LIST users.
     *
     * @return array<int, string>
     */
    private static function searchHolders(string $search): array
    {
        $model = Permissions::userModel();

        if ($model === null || ! self::holdersAreAdministrable()) {
            return [];
        }

        return $model::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(25)
            ->get()
            ->mapWithKeys(fn (Model $user): array => [(int) $user->getKey() => self::describe($user)])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    /**
     * ⚠️ PUBLIC SO A TEST CAN REACH IT, which is the same trade `FieldValueRenderer::relationLabels()` makes
     * and for the same reason: the resolver's answer is the thing that decides whether the form can be saved,
     * and building the form itself needs a Livewire component the package suite has no way to make (ADR-024
     * puts that layer in the browser). The wiring — that the select actually uses this — is asserted there.
     *
     * @param  list<mixed>  $ids
     * @return array<int, string>
     */
    public static function holderLabels(array $ids, ?int $roleId): array
    {
        $model = Permissions::userModel();

        if ($model === null) {
            return [];
        }

        $wanted = array_values(array_unique(array_map(intval(...), array_filter($ids, is_numeric(...)))));

        $labels = $model::query()
            ->whereKey($wanted)
            ->get()
            ->mapWithKeys(fn (Model $user): array => [(int) $user->getKey() => self::describe($user)])
            ->all();

        return $labels + self::labelsForFormerMembers($wanted, array_keys($labels), $roleId);
    }

    /**
     * A non-disclosing label for somebody who still holds the role but is no longer a member.
     *
     * ⚠️ A MISSING LABEL IS AN INVALID OPTION, AND IT FROZE THE FORM — the same defect review found on the
     * relation picker, in the place it was always going to appear next. `role_user` has no membership
     * constraint (ADR-033 says so in as many words), so a user removed from the org can keep an assignment;
     * `mutateFormDataBeforeFill()` hydrates that id, the org-scoped user query cannot see it, and Filament
     * validates a multiple select's submitted options through this resolver — so the owner could not rename
     * the role or change a grant until they noticed the one chip that would not save.
     *
     * ⚠️ THE ID IS NAMED AND THE PERSON IS NOT. Whoever it is, they are not in this org, so the panel has no
     * business resolving their name through a query that deliberately cannot see them — and the id is already
     * in the form state by the time this runs. `FieldValueRenderer::relationLabels()` makes the same trade for
     * the same reason.
     *
     * ⚠️ AND ONLY FOR AN ID THIS ROLE ALREADY HOLDS. "Already assigned" was the first version of that
     * sentence and review found the gap under it: the query filtered on `user_id` alone, so ANY id with a
     * `role_user` row anywhere — another role, another org — got a label, Filament accepted the forged
     * option, and `syncHolders()` called `assignTo()` for it. The exception meant to keep a form saveable
     * became a way to add somebody this org cannot see, and a way to ask whether an arbitrary id holds a
     * role somewhere. Scoped to the role being edited, a labelled id is one this role already holds, and
     * assigning it again is what `assignTo()` is already idempotent about.
     *
     * ⚠️ NO ROLE MEANS NO FALLBACK, which is the create form: a role that does not exist yet holds nobody,
     * so every id on it must resolve through the org-scoped query or not at all.
     *
     * @param  list<int>  $wanted
     * @param  list<int>  $resolved
     * @return array<int, string>
     */
    private static function labelsForFormerMembers(array $wanted, array $resolved, ?int $roleId): array
    {
        $missing = array_values(array_diff($wanted, $resolved));

        if ($missing === [] || $roleId === null) {
            return [];
        }

        $assigned = DB::table('role_user')
            ->where('role_id', $roleId)
            ->whereIn('user_id', $missing)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique();

        $labels = [];

        foreach ($assigned as $id) {
            $labels[$id] = sprintf('User #%d — no longer a member of this organisation', $id);
        }

        return $labels;
    }

    private static function holderLabel(int $id, ?int $roleId): ?string
    {
        $model = Permissions::userModel();

        if ($model === null) {
            return null;
        }

        $user = $model::query()->whereKey($id)->first();

        if ($user instanceof Model) {
            return self::describe($user);
        }

        // ⚠️ The singular resolver renders a saved value; it withholds the same name for the same reason —
        // see `labelsForFormerMembers()`.
        return self::labelsForFormerMembers([$id], [], $roleId)[$id] ?? null;
    }

    /**
     * The role a form is editing, or null on the create form.
     *
     * ⚠️ ONLY A SAVED `Role`. Filament hands a resolver whatever the schema's record is, and a label
     * fallback keyed on anything else would be scoped to a row that is not the one being edited.
     */
    private static function roleKey(?Model $record): ?int
    {
        return $record instanceof Role && $record->exists ? (int) $record->getKey() : null;
    }

    /** A person, as an administrator would recognise them. */
    private static function describe(Model $user): string
    {
        $name = $user->getAttribute('name');
        $email = $user->getAttribute('email');

        return is_string($name) && $name !== ''
            ? $name.(is_string($email) ? ' ('.$email.')' : '')
            : (is_string($email) ? $email : '#'.$user->getKey());
    }

    /**
     * One section per entry type the org has.
     *
     * ⚠️ PER TYPE RATHER THAN ONE LIST OF EVERY PAIR, which is a readability decision with a cost stated.
     * An org with forty types gets forty sections; the alternative is a single searchable list of two
     * hundred `type.action` options, which is one control and no structure. The question an operator asks
     * is *what may an editor do with articles*, so the shape follows the question — and a list of two
     * hundred checkboxes is a list nobody audits.
     *
     * @return array<int, Section>
     */
    private static function perTypeSections(): array
    {
        $site = app(Context::class)->site();

        return EntryType::visibleFor($site, app(Context::class)->orgId())
            ->map(fn (EntryType $type): Section => Section::make($type->plural_name)
                ->description('Permissions named entry.'.$type->handle.'.{action}')
                ->schema([
                    CheckboxList::make(self::PERMISSION_STATE.'.'.$type->handle)
                        ->hiddenLabel()
                        ->options(self::actionOptions())
                        ->columns(5)
                        ->dehydrated(false),
                ])
                ->collapsed())
            ->all();
    }

    /**
     * The five actions, from the registry rather than a literal.
     *
     * ⚠️ READ FROM `Permissions::ACTIONS`, so a form offering an action the registry would refuse is
     * impossible rather than merely unlikely — `Role::grant()` fails closed on an unregistered action, and
     * a control that could produce one would turn a save into an exception.
     *
     * @return array<string, string>
     */
    private static function actionOptions(): array
    {
        return array_combine(
            Permissions::ACTIONS,
            array_map(ucfirst(...), Permissions::ACTIONS),
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('handle')->badge(),
                IconColumn::make('is_owner')->label('Owner')->boolean(),
                TextColumn::make('permissions_count')->counts('permissions')->label('Grants'),

                /*
                 * ⚠️ Counted through the pivot rather than a relation, because `role_user` is the
                 * skeleton's table and core declares no relation to a user model it does not own.
                 */
                TextColumn::make('holders')->label('Held by')
                    ->state(fn (Role $record): int => DB::table('role_user')
                        ->where('role_id', $record->getKey())->count()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Administering roles is owner-only in v1.0 — ADR-033.
     *
     * ⚠️ THE ROUTE, NOT THE LINK, which is the argument `EntryTypeResource` makes at length and for the same
     * reason: `canViewAny()` is what `canAccess()` returns, so this gates the URL rather than the sidebar
     * item. Hiding a link an authenticated user can still type is the shape of bug ADR-024 says the PHP
     * suite structurally cannot see.
     */
    public static function canViewAny(): bool
    {
        return self::mayAdministerRoles();
    }

    public static function canCreate(): bool
    {
        return self::mayAdministerRoles();
    }

    public static function canEdit(Model $record): bool
    {
        return self::mayAdministerRoles() && $record instanceof Role;
    }

    public static function canDelete(Model $record): bool
    {
        return self::mayAdministerRoles() && $record instanceof Role;
    }

    /** Filament asks this for the bulk action rather than `canDelete()` per row. */
    public static function canDeleteAny(): bool
    {
        return self::mayAdministerRoles();
    }

    private static function mayAdministerRoles(): bool
    {
        $user = Permissions::currentUser();

        return $user !== null && Permissions::isOwner($user);
    }
}
