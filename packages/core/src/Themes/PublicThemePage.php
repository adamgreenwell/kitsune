<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationResolver;

final class PublicThemePage
{
    public function __construct(private readonly ThemeRegistry $themes, private readonly EntryRelationResolver $relations) {}

    /** Returns null for a Site whose operator has not selected a usable theme. */
    public function render(Request $request, Site $site): ?Response
    {
        $theme = $site->theme === null ? null : $this->themes->find($site->theme);
        if ($theme === null) {
            return null;
        }

        $path = trim($request->getPathInfo(), '/');
        $prefix = trim((string) $site->path_prefix, '/');

        if ($prefix !== '') {
            abort_unless($path === $prefix || str_starts_with($path, $prefix.'/'), 404);
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        $view = ['site' => $site, 'locale' => app()->getLocale(), 'direction' => Kitsune::textDirection()];
        if ($path === '') {
            $template = $theme->home();
            abort_if($template === null, 404);

            return $this->response($template, $view);
        }

        $segments = explode('/', $path);
        abort_unless(count($segments) === 2, 404);
        [$handle, $slug] = array_map(rawurldecode(...), $segments);
        abort_if($slug === '' || strlen($slug) > 255 || preg_match('/[\/\\\\\x00-\x20\x7f]/', $slug), 404);

        $template = $theme->entry($handle);
        abort_if($template === null, 404);

        $published = EntryType::visibleFor($site, $site->org_id)
            ->filter(fn (EntryType $type): bool => ! $type->is_media && in_array($type->handle, $theme->entryTypes, true))
            ->keyBy('handle');

        $type = $published->get($handle);
        abort_unless($type instanceof EntryType, 404);

        $entry = Entry::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->where('entry_type_id', $type->id)->where('slug', $slug)->published()->firstOrFail();

        $type->loadMissing('fields.fieldStorage');
        $storages = [];
        foreach ($type->fields as $field) {
            if ($field->fieldStorage?->strategy() === StorageStrategy::Relational) {
                $storages[$field->field_storage_id] = $field->fieldStorage;
            }
        }

        $resolved = $this->relations->resolveMany([$entry], $storages, true, static function (Entry $target) use ($site, $published): bool {
            return $target->site_id === $site->id && $target->slug !== null && $target->slug !== ''
                && $published->get($target->type_handle)?->id === $target->entry_type_id;
        });

        $related = [];
        foreach ($storages as $storage) {
            $related[$storage->handle] = array_column($resolved[$entry->id][$storage->id] ?? [], 'entry');
        }

        return $this->response($template, $view + ['entry' => $entry, 'related' => $related]);
    }

    /** @param array<string, mixed> $data */
    private function response(string $template, array $data): Response
    {
        return response()->make(View::file($template, $data)->render());
    }
}
