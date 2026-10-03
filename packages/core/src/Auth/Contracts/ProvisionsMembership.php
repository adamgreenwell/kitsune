<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Auth\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;

/**
 * The host's panel user model, as far as core may create one account in it and let that account in — ADR-026, as
 * amended by ADR-039's first owner.
 *
 * ⚠️ CORE OWNS NO USER MODEL AND NAMES NONE OF ITS COLUMNS OR PIVOTS (ADR-033: "core owns no user model … it asks the
 * panel"). The first owner is the one account core creates, so it asks the host's model to write its own row and its own
 * memberships rather than learning that they are `users`, `org_user` and `site_user`. Everything else about the account —
 * its other columns, its key type, its connection — stays the host's.
 *
 * ⚠️ PUBLIC, AND THE ONE EXCEPTION ITS SLICE MAKES TO "NO NEW PUBLIC API BEFORE v1.2" (CONTRIBUTING, amended by ADR-039):
 * every application built from the skeleton implements it. First-party only before v1.2, on ADR-038's footing.
 */
interface ProvisionsMembership extends Authenticatable
{
    /**
     * Create and save an account that signs in with this address and this password hash, and nothing else.
     *
     * ⚠️ THE HASH IS FINAL. Store it unchanged as the password the panel's guard checks — a `hashed` cast passes an
     * existing hash through — and never hash it again, log it, or put it in an exception message. Fill any other required
     * column from these values or the host's own defaults, and drop what there is no column for. Attach no membership:
     * core does, in the order its audit needs.
     */
    public static function provisionAccount(string $email, string $name, #[\SensitiveParameter] string $passwordHash): static;

    /** Make this account a member of the org, through the relation `#[OrgScopedThroughPivot]` reads. */
    public function admitToOrg(Org $org): void;

    /** Let this account enter the site in the admin: afterwards the panel's `canAccessTenant($site)` is true. */
    public function admitToSite(Site $site): void;
}
