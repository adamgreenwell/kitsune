<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Resources\EntryTypes;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kitsune\Core\Filament\Resources\EntryTypes\Pages\CreateEntryType;
use Kitsune\Core\Filament\Resources\EntryTypes\Pages\EditEntryType;
use Kitsune\Core\Filament\Resources\EntryTypes\Pages\ListEntryTypes;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Field;
use Kitsune\Core\Validation\Rule;

/**
 * The entity type builder — Phase 4's flagship, and the reason the schema
 * engine exists rather than a fixed set of content types.
 *
 * Unlike EntryResource this is an ORDINARY resource with ordinary routes: the
 * type is the record here, not a path segment, so ADR-012's constraint does
 * not apply and nothing is parameterised.
 */
class EntryTypeResource extends Resource
{
    protected static ?string $model = EntryType::class;

    protected static ?string $slug = 'entry-types';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /** Navigation is supplied explicitly by the panel (ADR-012). */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * ⚠️ NOT tenant-scoped, and this is a correctness statement.
     *
     * Filament's tenant is the Site (ADR-021), and it scopes every resource
     * through a relationship on the tenant. Schema is **org-owned**, not
     * site-owned — an entry type is available to a site through
     * `entry_type_availability`, which is a different question from ownership
     * — so `EntryType` has no `site()` relationship and Filament's automatic
     * scope is the wrong scope, not a missing one.
     *
     * Without this the page 500s outright:
     * `LogicException: The model [EntryType] does not have a relationship
     * named [site]`. Found by opening a browser, with the PHP suite green —
     * the same shape of defect as the ADR-012 spike, and the reason ADR-024
     * makes the browser layer mandatory rather than optional.
     *
     * Isolation is supplied by `getEloquentQuery()` instead, explicitly.
     */
    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->description('The handle appears in URLs and in the API, and cannot be changed once entries exist.')
                ->schema([
                    TextInput::make('handle')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Lowercase, no spaces. Used in /c/{type} and in the API.')
                        // Reserved handles are enforced at save (ADR-012), but
                        // a form error beats an exception the author cannot act
                        // on — so the same list is surfaced here as validation.
                        ->rules([
                            'regex:/^[a-z][a-z0-9_]*$/',
                            fn (): callable => static function (string $attribute, mixed $value, callable $fail): void {
                                if (in_array(strtolower((string) $value), EntryType::RESERVED_HANDLES, true)) {
                                    $fail("[{$value}] collides with a route segment and cannot be a type handle.");
                                }
                            },
                            // scopedUnique, never Laravel's unique: that rule
                            // bypasses Eloquent, so it would tell one org that
                            // another org holds the handle.
                            fn (?EntryType $record): mixed => Rule::scopedUnique(EntryType::class, 'handle', $record?->getKey()),
                        ])
                        ->disabled(fn (?EntryType $record): bool => $record?->exists === true)
                        ->dehydrated(),
                    TextInput::make('name')->required()->maxLength(255)->label('Singular name'),
                    TextInput::make('plural_name')->required()->maxLength(255),
                    TextInput::make('icon')
                        ->maxLength(255)
                        ->helperText('A Heroicon name, e.g. heroicon-o-rectangle-stack.'),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('Privacy')
                ->description('ADR-020: "everything you hold about this person" is unanswerable without knowing which field identifies the person.')
                ->schema([
                    Select::make('subject_field_id')
                        ->label('Subject identifier')
                        ->options(fn (?EntryType $record): array => $record === null ? [] : Field::query()
                            ->where('entry_type_id', $record->getKey())
                            ->with('fieldStorage')
                            ->get()
                            ->mapWithKeys(fn (Field $field): array => [$field->getKey() => $field->label])
                            ->all())
                        ->searchable()
                        ->helperText(fn (?EntryType $record): string => $record === null
                            ? 'Available once the type has fields.'
                            : 'The field identifying the data subject. Leave empty if this type holds no personal data.')
                        ->disabled(fn (?EntryType $record): bool => $record === null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('handle')->badge()->searchable(),
                TextColumn::make('fields_count')->counts('fields')->label('Fields'),
                IconColumn::make('is_system')
                    ->boolean()
                    ->label('System')
                    ->tooltip('Global types belong to every org and cannot be edited here.'),
                // The compliance surface, in the list rather than buried:
                // a type holding personal data with no subject nominated is a
                // hole a subject-access request cannot see (ADR-020).
                IconColumn::make('subject_field_id')
                    ->label('Subject')
                    // getStateUsing, because boolean() on a NULL renders
                    // nothing at all — and "nothing" is exactly the state
                    // this column exists to make visible.
                    ->getStateUsing(fn (EntryType $record): bool => $record->subject_field_id !== null)
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedExclamationTriangle)
                    ->falseColor('warning')
                    ->tooltip('Whether a data subject identifier is nominated.'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('ordering');
    }

    /**
     * ⚠️ Org-scoped explicitly, because EntryType is `#[Unscoped]`.
     *
     * The attribute is a declaration that this model carries no automatic
     * scope, not permission to ignore isolation — global system types
     * (`org_id IS NULL`) belong to every org, so the query has to say which
     * rows it means rather than relying on a scope that is not there.
     *
     * @return Builder<EntryType|Model>
     */
    public static function getEloquentQuery(): Builder
    {
        return EntryType::constrainToCurrentOrg(parent::getEloquentQuery());
    }

    public static function getRelations(): array
    {
        return [RelationManagers\FieldsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEntryTypes::route('/'),
            'create' => CreateEntryType::route('/create'),
            'edit' => EditEntryType::route('/{record}/edit'),
        ];
    }
}
