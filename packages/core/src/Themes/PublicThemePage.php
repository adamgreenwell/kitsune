<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Media\InlineMediaPresentation;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationResolver;
use Symfony\Component\HttpFoundation\Response;

final class PublicThemePage
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly EntryRelationResolver $relations,
        private readonly PublicEntryPaths $paths,
        private readonly PublicEntryRedirects $redirects,
        private readonly SiteMenus $menus,
    ) {}

    /** Returns null for a Site whose operator has not selected a usable theme. */
    public function render(Request $request, Site $site, string $entryRoute): ?Response
    {
        $theme = $site->theme === null ? null : $this->themes->find($site->theme);
        if ($theme === null) {
            return null;
        }

        $path = trim($request->getPathInfo(), '/');
        $prefix = trim((string) $site->path_prefix, '/');

        if ($prefix !== '') {
            $normalized = strtolower(substr($path, 0, strlen($prefix) + 1));
            abort_unless($normalized === $prefix || $normalized === $prefix.'/', 404);
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }

        $menus = [];
        $menu = function (string $handle) use (&$menus, $request, $site, $theme, $entryRoute): array {
            return $menus[$handle] ??= $this->menus->render($request, $site, $theme, $handle, $entryRoute, 'home');
        };
        $view = ['site' => $site, 'locale' => app()->getLocale(), 'direction' => Kitsune::textDirection(), 'menu' => $menu];
        if ($path === '') {
            $template = $theme->home();
            abort_if($template === null, 404);

            return $this->response($template, $view);
        }

        $segments = explode('/', $path);
        abort_unless(count($segments) === 2, 404);
        [$handle, $slug] = array_map(rawurldecode(...), $segments);
        abort_unless(PublicEntryPaths::validType($handle) && PublicEntryPaths::validSlug($slug), 404);

        $published = PublicEntryPaths::publishedTypes($site, $theme);
        $type = $published->get($handle);
        $entry = $type === null ? null : Entry::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->where('entry_type_id', $type->id)->where('slug', $slug)->first();
        if ($type === null || $entry === null || $entry->slug !== $slug || $entry->status !== 'published') {
            return $this->redirects->response($request, $site, $theme, $published, $handle, $slug, $entryRoute) ?? abort(404);
        }

        $template = $theme->entry($handle);
        abort_if($template === null, 404);

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
        $targets = [];
        foreach ($storages as $storage) {
            $entries = array_column($resolved[$entry->id][$storage->id] ?? [], 'entry');
            $related[$storage->handle] = $entries;
            foreach ($entries as $target) {
                $targets[$target->id] = $target;
            }
        }

        $relatedPaths = $this->paths->forEntries($request, $site, $theme, $targets, $published, $entryRoute);
        foreach ($related as $handle => $entries) {
            $related[$handle] = array_values(array_filter($entries, fn (Entry $target): bool => isset($relatedPaths[$target->id])));
        }

        return $this->response($template, $view + [
            'entry' => $entry,
            'entryPath' => PublicEntryPaths::path($site, $entry->type_handle, $slug),
            'related' => $related,
            'relatedPaths' => $relatedPaths,
            'richText' => static function (string $handle) use ($type, $entry, $site): ?HtmlString {
                $storage = $type->fields->first(fn ($field) => $field->fieldStorage?->handle === $handle)?->fieldStorage;
                if ($storage === null || $storage->type !== RichTextType::handle() || $storage->cardinality !== 1) {
                    return null;
                }

                $value = $entry->values[$handle] ?? null;

                return is_string($value) ? InlineMediaPresentation::publicHtml($entry, $site, $value) : null;
            },
        ]);
    }

    /** @param array<string, mixed> $data */
    private function response(string $template, array $data): Response
    {
        // The host's Blade can read the current request, reader guard or session.
        return response()->make(View::file($template, $data)->render(), headers: ['Cache-Control' => 'private, no-store']);
    }
}
