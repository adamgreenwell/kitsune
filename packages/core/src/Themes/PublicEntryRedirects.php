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
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Kitsune\Core\Audit\Auditor;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Tenancy\Context;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** @internal Former addresses point straight to an entry identity. */
final class PublicEntryRedirects
{
    public function __construct(
        private readonly Context $context,
        private readonly ThemeRegistry $themes,
        private readonly PublicEntryPaths $paths,
        private readonly Auditor $audit,
    ) {}

    public function supports(Entry $entry, Site $site): bool
    {
        if ($site->canonical_host === null || $site->theme === null || $entry->site_id !== $site->id
            || $entry->org_id !== $site->org_id || $entry->status !== 'published' || $entry->slug === null
            || ! PublicEntryPaths::validSlug($entry->slug)) {
            return false;
        }

        $theme = $this->themes->find($site->theme);

        return $theme !== null && $theme->entry($entry->type_handle) !== null
            && PublicEntryPaths::publishedTypes($site, $theme)->get($entry->type_handle)?->id === $entry->entry_type_id;
    }

    /** @param Closure(Entry): Entry $save */
    public function preserveOnSave(Request $request, Site $site, Entry $entry, Closure $save, string $entryRoute): Entry
    {
        $publicRequest = $this->publicRequest($request, $site);
        if ($publicRequest === null) {
            return $save($entry);
        }

        return $entry->getConnection()->transaction(function () use ($publicRequest, $site, $entry, $save, $entryRoute): Entry {
            $stored = $this->entryOn($site, $entry->id, lock: true);
            abort_if($stored === null || $stored->entry_type_id !== $entry->entry_type_id, 404);
            $type = $stored->type_handle;
            $slug = $stored->slug;
            $hadAddress = $slug !== null && $this->supports($stored, $site)
                && $this->paths->ownsPath($publicRequest, $site, PublicEntryPaths::path($site, $type, $slug), $entryRoute);

            $entry->setRawAttributes($stored->getAttributes(), sync: true);
            $saved = $save($entry);
            if ($hadAddress && ($saved->slug !== $slug || $saved->type_handle !== $type)
                && $saved->slug !== null && $saved->status === 'published') {
                $this->store($publicRequest, $site, $saved, $type, $slug, $entryRoute, replaceDormant: true);
            }

            return $saved;
        });
    }

    public function recordFromAdmin(Request $request, Site $site, Entry $target, string $type, string $slug, string $entryRoute): SiteEntryRedirect
    {
        $publicRequest = $this->publicRequest($request, $site);
        if ($publicRequest === null) {
            $this->refuse(__('kitsune::navigation.missing_site_address'));
        }

        return $this->record($publicRequest, $site, $target, $type, $slug, $entryRoute);
    }

    public function remove(Site $site, SiteEntryRedirect $redirect): void
    {
        abort_unless($this->context->orgId() === $site->org_id && $this->context->siteId() === $site->id, 404);
        $site->getConnection()->transaction(function () use ($site, $redirect): void {
            $removed = SiteEntryRedirect::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
                ->whereKey($redirect->id)->delete();
            abort_unless($removed === 1, 404);
            $this->audit->recordOrFail('site.redirect_removed', $redirect);
        });
    }

    /**
     * Record a former address after the editor has permission to publish the target.
     * A caller changing the entry can include both writes in the same transaction.
     */
    public function record(Request $request, Site $site, Entry $target, string $type, string $slug, string $entryRoute): SiteEntryRedirect
    {
        return $this->store($request, $site, $target, $type, $slug, $entryRoute, replaceDormant: false);
    }

    private function store(
        Request $request,
        Site $site,
        Entry $target,
        string $type,
        string $slug,
        string $entryRoute,
        bool $replaceDormant,
    ): SiteEntryRedirect {
        if ($this->context->orgId() !== $site->org_id || $this->context->siteId() !== $site->id
            || ! PublicEntryPaths::validType($type) || ! PublicEntryPaths::validSlug($slug)) {
            $this->refuse(__('kitsune::navigation.invalid_source'));
        }

        return $target->getConnection()->transaction(function () use ($request, $site, $target, $type, $slug, $entryRoute, $replaceDormant): SiteEntryRedirect {
            $entry = $this->entryOn($site, $target->id, lock: true);
            $theme = $site->theme === null ? null : $this->themes->find($site->theme);
            if ($entry === null || $theme === null) {
                $this->refuse(__('kitsune::navigation.inactive_target'));
            }

            $types = PublicEntryPaths::publishedTypes($site, $theme);
            $targetPath = $this->paths->forEntries($request, $site, $theme, [$entry], $types, $entryRoute)[$entry->id] ?? null;
            $sourcePath = PublicEntryPaths::path($site, $type, $slug);
            if ($targetPath === null || $targetPath === $sourcePath || ! $this->paths->ownsPath($request, $site, $sourcePath, $entryRoute)) {
                $this->refuse(__('kitsune::navigation.unreachable_redirect'));
            }

            $occupied = Entry::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
                ->ofType($type)->where('slug', $slug)->published()->first(['slug', 'type_handle']);
            if ($occupied?->slug === $slug && $occupied->type_handle === $type) {
                $this->refuse(__('kitsune::navigation.entry_has_source'));
            }

            $scope = SiteEntryRedirect::query()->where('org_id', $site->org_id)->where('site_id', $site->id);
            $source = ['source_key' => SiteEntryRedirect::sourceKey($type, $slug)];
            $destination = ['source_type' => $type, 'source_slug' => $slug, 'target_entry_id' => $entry->id];
            $redirect = $scope->firstOrCreate($source, $destination);
            if ($redirect->source_type !== $type || $redirect->source_slug !== $slug) {
                $this->refuse(__('kitsune::navigation.redirect_has_source'));
            }

            if ($redirect->target_entry_id !== $entry->id) {
                if (! $replaceDormant || $scope->whereKey($redirect->id)->delete() !== 1) {
                    $this->refuse(__('kitsune::navigation.redirect_has_source'));
                }

                $redirect = SiteEntryRedirect::query()->create($source + $destination);
            }

            if ($redirect->wasRecentlyCreated) {
                $this->audit->recordOrFail('site.redirect_saved', $redirect);
            }

            return $redirect;
        }, attempts: 3);
    }

    /** @param Collection<string, EntryType> $types */
    public function response(
        Request $request,
        Site $site,
        SiteTheme $theme,
        Collection $types,
        string $type,
        string $slug,
        string $entryRoute,
    ): ?RedirectResponse {
        $redirect = SiteEntryRedirect::query()->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->where('source_key', SiteEntryRedirect::sourceKey($type, $slug))->first();
        if ($redirect === null || $redirect->source_type !== $type || $redirect->source_slug !== $slug) {
            return null;
        }

        $entry = $this->entryOn($site, $redirect->target_entry_id, lock: false);
        if ($entry === null) {
            return null;
        }

        $path = $this->paths->forEntries($request, $site, $theme, [$entry], $types, $entryRoute)[$entry->id] ?? null;
        if ($path === null || $path === PublicEntryPaths::path($site, $type, $slug)) {
            return null;
        }

        return new RedirectResponse($path, 302, ['Cache-Control' => 'no-store']);
    }

    private function entryOn(Site $site, int $id, bool $lock): ?Entry
    {
        $query = Entry::query()->where('org_id', $site->org_id)->where('site_id', $site->id)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function publicRequest(Request $request, Site $site): ?Request
    {
        $origin = match ($site->canonical_host) {
            null => null,
            '' => $request->getSchemeAndHttpHost(),
            default => ReaderLinks::origin($site) ?? $request->getScheme().'://'.$site->canonical_host,
        };

        return $origin === null ? null : Request::create($origin);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['former_address' => $message]);
    }
}
