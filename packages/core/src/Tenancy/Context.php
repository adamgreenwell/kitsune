<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy;

use Kitsune\Core\Models\Org;
use Kitsune\Core\Models\Site;

/**
 * The current Org and Site for this request.
 *
 * Deliberately independent of Filament. Core is headless-capable (ADR-002),
 * so the scoping guarantee cannot be reachable only through a panel — the
 * REST API and console commands need the same enforcement. Filament's tenant
 * middleware sets this; so does anything else that establishes context.
 */
final class Context
{
    private ?Org $org = null;

    private ?Site $site = null;

    public function site(): ?Site
    {
        return $this->site;
    }

    public function org(): ?Org
    {
        return $this->org;
    }

    public function siteId(): ?int
    {
        return $this->site?->getKey();
    }

    public function orgId(): ?int
    {
        return $this->org?->getKey();
    }

    /** Setting a Site implies its Org — they cannot disagree. */
    public function setSite(?Site $site): self
    {
        $this->site = $site;
        $this->org = $site?->org;

        return $this;
    }

    public function setOrg(?Org $org): self
    {
        $this->org = $org;

        if ($this->site !== null && $this->site->org_id !== $org?->getKey()) {
            $this->site = null;
        }

        return $this;
    }

    public function forget(): self
    {
        $this->org = null;
        $this->site = null;

        return $this;
    }

    public function hasSite(): bool
    {
        return $this->site !== null;
    }

    public function hasOrg(): bool
    {
        return $this->org !== null;
    }
}
