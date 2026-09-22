<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Blueprints;

use Illuminate\Support\Str;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use RuntimeException;

/**
 * Create the first org and its first site, on an installation that has neither — ADR-039.
 *
 * @internal
 *
 * ⚠️ THIS IS WHAT MAKES ADR-030's TRIGGER SATISFIABLE, and without it the *done when* cannot be met by any
 * amount of blueprint code. That ADR moves `kitsunecms.org` onto Kitsune when the Marketing Site blueprint
 * "applies to a fresh install with no manual step outside the apply flow — no hand-edited config, no SQL, no
 * *and then you also need to*". A fresh install has no org, so an apply that requires one already existing
 * needs a step outside itself; the operator had to open a console and write two `create()` calls, which is
 * precisely the "and then you also need to" the condition forbids.
 *
 * ⚠️ ONLY WHEN THE INSTALLATION IS EMPTY, which is what makes this safe to do without asking. Zero orgs is a
 * state with nothing to damage and one unambiguous reading. On an installation that already has an org, an
 * unknown `--org` slug stays an error: creating one there would be inventing a customer because somebody
 * mistyped, and the receipt would record a blueprint applied into it.
 *
 * ⚠️ IT CREATES NO USER, AND THAT IS ADR-026 RATHER THAN AN OMISSION. "The installer never creates a default
 * administrator account; onboarding creates the first user interactively." An org and a site are tenancy rows;
 * an account is a credential. This makes the admin REACHABLE — Filament's tenant is the Site, so without one
 * there is no panel URL at all — and stops there.
 *
 * ⚠️ THE SITE CLAIMS NO HOST. `base_url` is left null, which is the admin-only site ADR-021 describes, so
 * none of the host-claim machinery runs: no canonical host, no overlap check, no mutex. A fresh install does
 * not know its own public URL yet, and guessing one would take a claim the operator has not made.
 */
final class FirstOrg
{
    /**
     * @throws RuntimeException when the installation already has an org
     */
    public static function create(string $slug, ?string $name, ?string $siteSlug, string $locale): Org
    {
        /*
         * Past the scopes on purpose: this asks whether the INSTALLATION is empty, from a console with no org
         * in context, where a scoped read answers about nothing whatever is in the table. `Org` is the root of
         * the hierarchy and unscoped, but the withTrashed is load-bearing — `Org` soft-deletes, and a trashed
         * org is still a row whose slug is taken and whose content is recoverable.
         */
        $existing = Org::query()->withTrashed()->count();

        if ($existing > 0) {
            throw new RuntimeException(
                "Refusing to create an organisation: this installation already has {$existing}. A blueprint "
                .'creates the first org and site only on an installation that has neither, because that is the '
                .'one state with nothing to damage. Name an organisation that exists, or create the one you '
                .'meant deliberately.'
            );
        }

        $name ??= Str::headline($slug);
        $siteSlug ??= $slug;

        $context = app(Context::class);

        /*
         * One transaction, because a site slug is globally unique and can fail after the org is written —
         * which is the exact sequence `BenchmarkStorageCommand::fixture()` records paying for: "a site slug
         * another org already held failed the insert after the org was made, and left the org". Here the org
         * being left behind would be worse than untidy: the next run would find one org, refuse to bootstrap,
         * and tell the operator to name an organisation that exists but has no site.
         */
        return (new Org)->getConnection()->transaction(static function () use ($context, $slug, $name, $siteSlug, $locale): Org {
            /* `Org` is `#[Unscoped]` — it is the root of the hierarchy, so there is no context to set first. */
            $org = Org::create(['slug' => $slug, 'name' => $name]);

            /* And now there is. Context first, then the row: `EnforcesScope` refuses a scope key nobody vouched for. */
            $context->setOrg($org);

            Site::create([
                'handle' => $siteSlug,
                'slug' => $siteSlug,
                'name' => $name,
                'locale' => $locale,
            ]);

            return $org;
        });
    }
}
