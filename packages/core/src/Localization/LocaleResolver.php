<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Localization;

use Illuminate\Contracts\Translation\HasLocalePreference;
use Kitsune\Core\Models\Site;

/**
 * Which locale a request should run in.
 *
 * ⚠️ TWO AXES, NOT ONE, and ADR-018 rule 2 is explicit about why. The CONTENT
 * locale belongs to the Site: it decides what language the public output is in.
 * The UI locale is a *viewer* preference, because a Swiss agency has German,
 * French and Italian editors on one org. They are independent, they are allowed
 * to disagree, and conflating them is the mistake the ADR names — so they are
 * two methods here and two middlewares above, rather than one of each.
 *
 * ⚠️ And resolution is PER REQUEST. `app()->setLocale()` is process state, so
 * whatever sets it must run on every request: under PHP-FPM a locale left over
 * from the previous request would serve the wrong site for the life of the
 * worker, and under Octane for the life of the process. That is the structural
 * half of issue #38, and it is the reason these are middlewares rather than a
 * one-time boot step.
 */
final class LocaleResolver
{
    /**
     * ⚠️ A SHAPE GUARD, because a locale reaches `setLocale()` from stored data.
     *
     * Laravel resolves translation files by treating the locale as a path
     * segment, so a preference of `../../../secrets` is a value that gets
     * concatenated into a filesystem path. The UI locale comes from a user's own
     * preference (AGENTS.md invariant 6 — anything reachable from a URL is
     * untrusted, and a self-service preference is exactly that), and the site
     * locale from an operator-editable column, so neither is trusted here.
     *
     * BCP 47's shape is narrower than this, deliberately: the point is to admit
     * every tag Laravel and Filament actually ship (`en`, `pt_BR`, `zh-Hant`,
     * `ckb`) while excluding separators, dots and anything path-like. A tag this
     * rejects is *ignored* rather than fatal — see `firstUsable()`.
     */
    private const LOCALE_SHAPE = '/^[A-Za-z]{2,8}(?:[_-][A-Za-z0-9]{1,8})*$/';

    /**
     * The CONTENT locale: what language a site's output is in.
     *
     * Falls back to the application default, which is also what a site with no
     * locale of its own means — the column is `NOT NULL` with a default of `en`,
     * so in practice this only falls through when there is no site at all.
     */
    public function forSite(?Site $site, ?string $default = null): string
    {
        return $this->firstUsable([$site?->locale], $default);
    }

    /**
     * The UI locale: what language the *viewer* reads the admin in.
     *
     * ⚠️ The chain is viewer → site → application default, and the first step is
     * what makes the two axes independent. A French editor administering an
     * Arabic site gets a French admin around Arabic content, which is the case
     * ADR-018 rule 2 exists for.
     *
     * ⚠️ `$requested` COMES FIRST, and leaving it out was routing around a decided
     * ADR rather than implementing it. ADR-019 settles the binding as "Livewire's
     * `#[Url]` query-string attribute, falling back to the user's stored preference",
     * so an explicitly localized admin URL must win over the stored one — that is what
     * makes such a URL shareable at all. This shipped as persisted-only, which is a
     * different behaviour wearing the same name (invariant 12).
     *
     * ⚠️ It is also the least trusted input here, arriving straight off the URL
     * (invariant 6) — which costs nothing extra, because `firstUsable()` shape-guards
     * every candidate rather than trusting any of them. And it is request state only:
     * nothing writes it back to the viewer's preference, so a shared link cannot change
     * the recipient's setting.
     *
     * ⚠️ Read through Laravel's own `HasLocalePreference`, not a Kitsune
     * interface and not a column. Core must not require a particular auth
     * schema — ADR-002 keeps it headless-capable, and the users table belongs to
     * the application — so core asks the contract and the app decides where the
     * preference lives. A user model that does not implement it simply has no
     * preference, which is the correct answer rather than an error.
     */
    public function forViewer(
        mixed $viewer,
        ?Site $site,
        ?string $requested = null,
        ?string $default = null,
    ): string {
        return $this->firstUsable([
            $requested,
            $viewer instanceof HasLocalePreference ? $viewer->preferredLocale() : null,
            $site?->locale,
        ], $default);
    }

    /**
     * The first candidate that is a usable locale, or the default.
     *
     * ⚠️ A malformed candidate is SKIPPED, not fatal. A stored preference that
     * stops matching — hand-edited, or written by an older release — would
     * otherwise take the admin down for that user, and the honest degradation is
     * the next locale in the chain. It is a fail-safe rather than a fail-closed
     * because the consequence of the wrong *language* is a legibility problem,
     * where ADR-020's fail-closed rule protects against disclosure.
     *
     * @param  list<string|null>  $candidates
     */
    private function firstUsable(array $candidates, ?string $default = null): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match(self::LOCALE_SHAPE, $candidate) === 1) {
                return $candidate;
            }
        }

        return $default ?? (string) config('app.locale', 'en');
    }
}
