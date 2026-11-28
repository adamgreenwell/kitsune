<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

use DomainException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Kitsune\Core\Models\Entry;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Models\SiteEntryRedirect;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Themes\PublicEntryPaths;
use Kitsune\Core\Themes\PublicEntryRedirects;
use stdClass;

/** @internal Generates an operator report after draft import. */
final readonly class ImportRedirectPlan
{
    public function __construct(
        private ImportJournal $journal,
        private PublicEntryPaths $paths,
        private PublicEntryRedirects $redirects,
    ) {}

    public static function digest(string $report): string
    {
        return hash('sha256', "kitsune.import-redirects.v1\0".$report);
    }

    public function preview(Site $site, string $approvedReport, string $approval, string $inventory, string $entryRoute): string
    {
        return $this->report($site, $approvedReport, $approval, $inventory, $entryRoute, lock: false);
    }

    /** @return list<int> */
    public function apply(
        Site $site, string $approvedReport, string $approval, string $inventory, string $entryRoute, string $reviewed, string $redirectApproval,
    ): array {
        if (! hash_equals(self::digest($reviewed), $redirectApproval)) {
            throw new DomainException('The redirect approval does not match the reviewed report.');
        }

        return $site->getConnection()->transaction(function () use ($site, $approvedReport, $approval, $inventory, $entryRoute, $reviewed): array {
            $destination = Site::query()->where('org_id', $site->org_id)->whereKey($site->id)->lockForUpdate()->first();
            if ($destination === null) {
                throw new DomainException('The redirect destination Site is unavailable.');
            }

            $current = $this->report($destination, $approvedReport, $approval, $inventory, $entryRoute, lock: true);
            if (! hash_equals($reviewed, $current)) {
                throw new DomainException('The former addresses or destinations changed. Review a new redirect report.');
            }

            $ids = [];
            foreach (json_decode($current, true, flags: JSON_THROW_ON_ERROR)['rows'] as $row) {
                if ($row['disposition'] !== 'candidate') {
                    continue;
                }

                $entry = Entry::query()->where('org_id', $destination->org_id)->where('site_id', $destination->id)
                    ->findOrFail($row['destination']['entry_id']);
                $type = $row['former_type'];
                $slug = $row['former_slug'];
                if (PublicEntryPaths::path($destination, $type, $slug) !== $row['former_path']) {
                    throw new DomainException('The former path changed. Review a new redirect report.');
                }
                $request = Request::create($row['old_address']['origin']);
                $ids[] = $this->redirects->record($request, $destination, $entry, $type, $slug, $entryRoute)->id;
            }

            return $ids;
        }, attempts: 3);
    }

    private function report(Site $site, string $approvedReport, string $approval, string $inventory, string $entryRoute, bool $lock): string
    {
        $plan = ImportPlan::restore($approvedReport);
        if ($plan->orgId !== $site->org_id || $plan->siteId !== $site->id) {
            throw new DomainException('The import approval belongs to a different Org or Site.');
        }
        $execution = $this->journal->executionReport($approvedReport, $approval);
        if ($execution['state'] !== 'completed') {
            throw new DomainException('Finish the approved draft import before planning redirects.');
        }

        $input = json_decode($inventory, depth: 32, flags: JSON_THROW_ON_ERROR);
        if (! $input instanceof stdClass || array_diff(array_keys(get_object_vars($input)), ['source_key', 'source_blog', 'addresses']) !== []
            || ($input->source_key ?? null) !== $plan->sourceKey || ! is_string($input->source_blog ?? null)
            || ! is_array($input->addresses ?? null) || ! array_is_list($input->addresses)) {
            throw new InvalidArgumentException('Supply a redirect inventory for the completed import source.');
        }
        $blog = self::address($input->source_blog);
        $confirmed = $plan->sourceAddress === null ? null : self::address($plan->sourceAddress);
        if ($blog === null || $confirmed === null || $blog['origin'] !== $confirmed['origin'] || $blog['path'] !== $confirmed['path'] || $blog['query'] !== null || $blog['fragment'] !== null) {
            throw new InvalidArgumentException('Supply the confirmed source blog URL in the redirect inventory.');
        }

        $receipts = [];
        foreach (array_slice($execution['records'], 0, count($plan->records)) as $record) {
            $receipts[DraftRelations::identity($record['source_type'], $record['source_id'])] = $record['entry_id'];
        }
        $entryQuery = Entry::query()->useWritePdo()->where('org_id', $site->org_id)->where('site_id', $site->id)
            ->whereKey(array_values(array_filter($receipts)))->orderBy('id');
        if ($lock) {
            $entryQuery->lockForUpdate();
        }
        $entries = $entryQuery->get()->keyBy('id');
        $origin = ReaderLinks::origin($site) ?? ($site->canonical_host === '' ? config('app.url') : null);
        $request = is_string($origin) && self::address($origin) !== null ? Request::create($origin) : null;
        $rows = [];
        $keys = [];
        $handoffKeys = [];
        foreach ($input->addresses as $row) {
            if (! $row instanceof stdClass || array_diff(array_keys(get_object_vars($row)), ['source_type', 'source_id', 'url']) !== []
                || ! is_string($row->source_type ?? null) || ! is_string($row->source_id ?? null) || ! is_string($row->url ?? null)) {
                throw new InvalidArgumentException('Each redirect inventory row requires a source type, source ID and old URL.');
            }

            $entryId = $receipts[DraftRelations::identity($row->source_type, $row->source_id)] ?? null;
            $entry = $entryId === null ? null : $entries->get($entryId);
            $address = self::address($row->url);
            $result = $this->evaluate($address, $blog, $site, $entry, $request, $entryRoute, $lock);
            if ($result['former_path'] !== null) {
                $keys[$result['former_path']][] = count($rows);
            }
            if ($address !== null && $address['fragment'] === null) {
                $handoffKeys[$address['origin'].$address['path'].'?'.($address['query'] ?? '')][] = count($rows);
            }
            $rows[] = [
                'identity_basis' => 'inventory-asserted',
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'old_url' => $row->url,
                'old_address' => $address,
                'destination' => $entry === null ? null : [
                    'entry_id' => $entry->id,
                    'type' => $entry->type_handle,
                    'slug' => $entry->slug,
                    'state' => $entry->status,
                    'fingerprint' => hash('sha256', json_encode($entry->getRawOriginal(), JSON_THROW_ON_ERROR)),
                ],
                ...$result,
                'host_route' => $request === null ? null : self::hostRoute(
                    $address, $blog['path'], $row->source_id, $result, $request->getSchemeAndHttpHost(),
                ),
            ];
        }
        foreach ($keys as $positions) {
            if (count($positions) === 1) {
                continue;
            }
            foreach ($positions as $position) {
                $rows[$position]['disposition'] = 'held';
                $rows[$position]['findings'][] = 'duplicate-former-address';
            }
        }

        foreach ($handoffKeys as $positions) {
            if (count($positions) > 1) {
                foreach ($positions as $position) {
                    if ($rows[$position]['host_route'] !== null) {
                        $rows[$position]['findings'][] = 'duplicate-old-request';
                        $rows[$position]['host_route'] = null;
                    }
                }
            }
        }

        return json_encode([
            'version' => 1,
            'org_id' => $site->org_id, 'site_id' => $site->id, 'source_key' => $plan->sourceKey,
            'approved_import' => $approval, 'inventory_sha256' => hash('sha256', $inventory),
            'source_blog' => $input->source_blog, 'entry_route' => $entryRoute,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array{origin: string, path: string, request_uri: string, query: ?string, fragment: ?string}|null  $address
     * @param  array{origin: string, path: string, request_uri: string, query: ?string, fragment: ?string}  $blog
     * @return array{disposition: string, findings: list<string>, former_path: ?string, former_type: ?string, former_slug: ?string, target_path: ?string}
     */
    private function evaluate(?array $address, array $blog, Site $site, ?Entry $entry, ?Request $request, string $entryRoute, bool $lock): array
    {
        $findings = [];
        $formerPath = $formerType = $formerSlug = null;
        $canonical = $entry?->slug === null ? null : PublicEntryPaths::path($site, $entry->type_handle, $entry->slug);
        if ($address === null) {
            $findings[] = 'invalid-old-url';
        } else {
            if ($address['origin'] !== $blog['origin']) {
                $findings[] = 'outside-source-origin';
            }
            if (! str_starts_with($address['path'].'/', $blog['path'].'/')) {
                $findings[] = 'outside-source-blog-path';
            }
            if ($request === null || $request->getSchemeAndHttpHost() !== $address['origin']) {
                $findings[] = 'old-origin-needs-host-routing';
            }
            $segments = explode('/', ltrim(substr($address['path'], strlen($blog['path'])), '/'));
            if ($address['query'] !== null || $address['fragment'] !== null || count($segments) !== 2
                || ! PublicEntryPaths::validType($segments[0]) || ! PublicEntryPaths::validSlug($segments[1])) {
                $findings[] = 'unsupported-former-address';
                if ($request !== null && $request->getSchemeAndHttpHost() === $address['origin']
                    && ! $this->paths->ownsPath($request, $site, $address['path'] === '' ? '/' : $address['path'], $entryRoute)) {
                    $findings[] = 'former-address-not-owned';
                }
            } elseif ($findings === []) {
                [$formerType, $formerSlug] = $segments;
                $formerPath = PublicEntryPaths::path($site, $formerType, $formerSlug);
                if ($formerPath !== $address['path']) {
                    $findings[] = 'old-path-needs-host-routing';
                    $formerPath = $formerType = $formerSlug = null;
                } elseif ($request !== null && ! $this->paths->ownsPath($request, $site, $formerPath, $entryRoute)) {
                    $findings[] = 'former-address-not-owned';
                } elseif ($formerPath !== $canonical) {
                    $entries = Entry::query()->useWritePdo()->where('org_id', $site->org_id)->where('site_id', $site->id)
                        ->ofType($formerType)->where('slug', $formerSlug)->published();
                    $redirects = SiteEntryRedirect::query()->useWritePdo()->where('org_id', $site->org_id)->where('site_id', $site->id)
                        ->where('source_key', SiteEntryRedirect::sourceKey($formerType, $formerSlug));
                    if ($lock) {
                        $entries->lockForUpdate();
                        $redirects->lockForUpdate();
                    }
                    $occupied = $entries->first(['slug', 'type_handle']);
                    $previous = $redirects->first(['target_entry_id']);
                    if ($occupied?->slug === $segments[1] && $occupied->type_handle === $segments[0]) {
                        $findings[] = 'former-address-is-a-published-entry';
                    }
                    if ($previous !== null && $previous->target_entry_id !== $entry?->id) {
                        $findings[] = 'former-address-has-another-target';
                    }
                }
            }
        }
        if ($entry === null) {
            $findings[] = 'destination-mapping-missing';
        } elseif ($entry->status !== 'published') {
            $findings[] = 'destination-unpublished';
        } elseif (! $this->redirects->supports($entry, $site) || $canonical === null || $request === null
            || ! $this->paths->ownsPath($request, $site, $canonical, $entryRoute)) {
            $findings[] = 'destination-not-routable';
        }

        return [
            'disposition' => $findings !== [] ? 'held' : ($formerPath === $canonical ? 'no-op' : 'candidate'),
            'findings' => $findings, 'former_path' => $formerPath, 'former_type' => $formerType, 'former_slug' => $formerSlug, 'target_path' => $canonical,
        ];
    }

    /**
     * @param  array{origin: string, path: string, request_uri: string, query: ?string, fragment: ?string}|null  $address
     * @param  array{disposition: string, findings: list<string>, former_path: ?string, former_type: ?string, former_slug: ?string, target_path: ?string}  $row
     * @return array{origin: string, request_uri: string, location: string}|null
     */
    private static function hostRoute(?array $address, string $blogPath, string $sourceId, array $row, string $destinationOrigin): ?array
    {
        if ($address === null || $address['fragment'] !== null || $row['disposition'] !== 'held' || $row['target_path'] === null
            || array_diff($row['findings'], ['unsupported-former-address', 'old-origin-needs-host-routing', 'old-path-needs-host-routing']) !== []) {
            return null;
        }

        if (in_array('unsupported-former-address', $row['findings'], true)) {
            $relative = ltrim(substr($address['path'], strlen($blogPath)), '/');
            $dated = $address['query'] === null && preg_match('/^[0-9]{4}\/[0-9]{2}(?:\/[0-9]{2})?\/[a-z0-9]+(?:-[a-z0-9]+)*$/D', $relative) === 1;
            $byId = $address['path'] === $blogPath && preg_match('/^[1-9][0-9]{0,63}$/D', $sourceId) === 1
                && $address['query'] === 'p='.$sourceId;
            if (! $dated && ! $byId) {
                return null;
            }
        }

        return [
            'origin' => $address['origin'],
            'request_uri' => $address['request_uri'],
            'location' => $destinationOrigin.$row['target_path'],
        ];
    }

    /** @return array{origin: string, path: string, request_uri: string, query: ?string, fragment: ?string}|null */
    private static function address(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || preg_match('/[\x00-\x20\\\\]/', $url) === 1
            || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        $origin = $scheme.'://'.strtolower($parts['host']);
        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $origin .= ':'.$port;
        }

        $path = $parts['path'] ?? '/';
        $query = $parts['query'] ?? null;

        return [
            'origin' => $origin, 'path' => rtrim($path, '/'),
            'request_uri' => $path.($query === null ? '' : '?'.$query), 'query' => $query, 'fragment' => $parts['fragment'] ?? null,
        ];
    }
}
