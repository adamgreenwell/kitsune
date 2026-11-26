<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;

abstract class SiteNavigationPage extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $context = app(Context::class);
        $site = $context->site();
        $user = Permissions::currentUser();
        $panel = app()->bound('filament') ? Filament::getCurrentPanel() : null;
        if ($site === null || $site->org_id !== $context->orgId() || $panel === null
            || ! $user instanceof FilamentUser || ! $user instanceof HasTenants
            || ! $user->canAccessPanel($panel) || ! $user->canAccessTenant($site)
            || Filament::getTenant()?->getKey() !== $site->id) {
            return false;
        }

        Permissions::forget();

        return Permissions::allows($user, Permissions::forEntryType(Permissions::ANY_TYPE, 'view'))
            && Permissions::allows($user, Permissions::forEntryType(Permissions::ANY_TYPE, 'publish'));
    }

    public function content(Schema $schema): Schema
    {
        self::site();

        return $schema->components([EmbeddedTable::make()]);
    }

    protected static function site(): Site
    {
        abort_unless(static::canAccess(), 403);

        return app(Context::class)->site() ?? abort(404);
    }

    /** @return Builder<Entry> */
    protected static function localEntries(): Builder
    {
        $site = self::site();
        $types = EntryType::visibleFor($site, $site->org_id)->reject(fn (EntryType $type): bool => $type->is_media)
            ->map(fn (EntryType $type): int => $type->id)->all();

        return Entry::query()->where('site_id', $site->id)->where('org_id', $site->org_id)
            ->whereIn('entry_type_id', $types);
    }
}
