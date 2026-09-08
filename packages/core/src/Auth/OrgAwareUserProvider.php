<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;

/**
 * The authentication carve-out for an org-scoped user model.
 *
 * ⚠️ Here rather than on the model, and that distinction is the whole point.
 * `EloquentUserProvider` builds its own query through `newModelQuery()` and
 * never calls a method on the user — so a carve-out written as
 * `User::resolveForAuthentication()` would have read correctly in review and
 * never executed.
 *
 * Why it is needed: `OrgMembershipScope` fails closed with no org context,
 * and authentication runs BEFORE any org exists, because the org is derived
 * from the site the user is on their way to. Scoped, the provider matches
 * nobody and login is impossible — the same bootstrap cycle that broke
 * `getTenants()` and `Site` route binding (ADR-021).
 *
 * Why it is safe: every query here resolves ONE user by an identifier the
 * caller already supplied — an id from the session, a credential, or a
 * remember token. It never lists users, so it discloses nothing an attacker
 * did not already hold, and the credential check still has to pass.
 * Authorisation to reach anything comes from `site_user` afterwards.
 *
 * ⚠️ Do not widen this into a general "users are unscoped here" helper. The
 * exposure the scope closes is user ENUMERATION, and the moment something
 * lists users through this provider, that protection is gone.
 */
class OrgAwareUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)
            ->withoutGlobalScope(OrgMembershipScope::class);
    }
}
