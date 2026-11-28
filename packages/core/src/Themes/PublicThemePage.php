<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Themes;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Kitsune\Core\Fields\StorageStrategy;
use Kitsune\Core\Fields\Types\RichTextType;
use Kitsune\Core\Kitsune;
use Kitsune\Core\Media\InlineMediaPresentation;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Relations\EntryRelationResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-import-type PublicItem from SiteMenus
 * @phpstan-import-type Selection from ThemeViewSelection
 */
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

            return $this->response($template, $theme->contract === 1
                ? ['kitsune' => $this->versionedView($site, $menu, $this->themeSelection($site))]
                : $view);
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

        $selection = $theme->contract === 1 ? $this->themeSelection($site, $type->handle) : null;
        $type->loadMissing('fields.fieldStorage');
        $storages = [];
        foreach ($type->fields as $field) {
            $storage = $field->fieldStorage;
            if ($storage !== null && ($selection === null || in_array($storage->handle, $selection['fields'], true))
                && $storage->strategy() === StorageStrategy::Relational) {
                $storages[$field->field_storage_id] = $storage;
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

        if ($selection !== null) {
            $links = [];
            foreach ($related as $field => $entries) {
                $links[$field] = array_map(static fn (Entry $target): array => [
                    'title' => $target->title, 'path' => $relatedPaths[$target->id],
                ], $entries);
            }

            return $this->response($template, ['kitsune' => $this->versionedView($site, $menu, $selection, $entry, $type, $links)]);
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

    /**
     * @param  Closure(string): list<PublicItem>  $menu
     * @param  Selection  $selection
     * @param  array<string, list<array{title: ?string, path: string}>>  $related
     */
    private function versionedView(Site $site, Closure $menu, array $selection, ?Entry $entry = null, ?EntryType $type = null, array $related = []): ThemeView
    {
        $fields = [];
        $richText = [];
        foreach ($type->fields ?? [] as $field) {
            $storage = $field->fieldStorage;
            if ($storage === null || ! in_array($storage->handle, $selection['fields'], true)) {
                continue;
            }
            if (! in_array($storage->type, ['text', 'textarea', 'number', 'boolean', 'date', 'datetime', 'select', 'multi_select', 'rich_text'], true)
                || $storage->strategy() !== StorageStrategy::Inline) {
                continue;
            }

            $multiple = $storage->isMultiValue() || $storage->type === 'multi_select';
            $value = $entry?->values[$storage->handle] ?? ($multiple ? [] : null);
            if ($storage->type === RichTextType::handle()) {
                if (! $multiple && is_string($value)) {
                    $richText[$storage->handle] = $value;
                    $value = InlineMediaPresentation::withoutReferences($value);
                } else {
                    $value = null;
                }
            }
            $fields[$storage->handle] = ['type' => $storage->type, 'multiple' => $multiple, 'value' => $value];
        }

        $rendered = [];

        return new ThemeView(
            siteName: $site->name,
            homePath: $site->path_prefix ?: '/',
            locale: app()->getLocale(),
            direction: Kitsune::textDirection(),
            entry: $entry === null ? null : [
                'title' => $entry->title,
                'type' => $entry->type_handle,
                'path' => PublicEntryPaths::path($site, $entry->type_handle, (string) $entry->slug),
            ],
            hostData: $selection['hostData'],
            related: $related,
            menus: $menu,
            fields: $fields,
            renderRichText: static function (string $handle) use ($entry, $site, $richText, &$rendered): ?HtmlString {
                if ($entry === null || ! isset($richText[$handle])) {
                    return null;
                }

                return $rendered[$handle] ??= InlineMediaPresentation::publicHtml($entry, $site, $richText[$handle]);
            },
        );
    }

    /** @return Selection */
    private function themeSelection(Site $site, ?string $entryType = null): array
    {
        return app()->bound(ThemeViewSelection::class)
            ? app(ThemeViewSelection::class)->forRender($site->org_id, $site->id, $entryType)
            : ['fields' => [], 'hostData' => []];
    }

    /** @param array<string, mixed> $data */
    private function response(string $template, array $data): Response
    {
        // The host's Blade can read the current request, reader guard or session.
        return response()->make(View::file($template, $data)->render(), headers: ['Cache-Control' => 'private, no-store']);
    }
}
