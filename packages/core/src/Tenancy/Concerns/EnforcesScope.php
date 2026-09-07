<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tenancy\Scopes\SiteScope;

/**
 * Applies the global scope a model's attribute declares, and stamps the scope
 * key on create so a row cannot be written outside the context that made it.
 *
 * Reading and writing are separate holes and both need plugging. A scope that
 * only filters SELECTs still lets a caller insert a row belonging to another
 * org, which then becomes invisible to the org that owns it and visible to
 * nobody — a leak and a data-loss bug at once.
 */
trait EnforcesScope
{
    public static function bootEnforcesScope(): void
    {
        // Throws for an undeclared model. Boot time is the right moment:
        // the failure is loud, immediate, and impossible to ship past.
        $declared = ScopeResolver::for(static::class);

        if ($declared === SiteScoped::class) {
            static::addGlobalScope(new SiteScope);
        }

        if ($declared === OrgScoped::class) {
            static::addGlobalScope(new OrgScope);
        }

        static::creating(function (self $model) use ($declared): void {
            $context = app(Context::class);

            if ($declared === SiteScoped::class) {
                // site_id left explicitly null means org-shared, which is
                // legitimate — only fill it when nothing was said at all.
                if (! array_key_exists('site_id', $model->getAttributes())) {
                    $model->setAttribute('site_id', $context->siteId());
                }

                $model->setAttribute('org_id', $model->getAttribute('org_id') ?? $context->orgId());
            }

            if ($declared === OrgScoped::class) {
                $model->setAttribute('org_id', $model->getAttribute('org_id') ?? $context->orgId());
            }
        });
    }

    /**
     * Escape hatch, named to be greppable and uncomfortable.
     *
     * Legitimate uses exist — provisioning, cross-org admin tooling, the
     * migration framework. Every one of them should be reviewable by
     * searching for this method.
     */
    public static function withoutScopeBecause(string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('withoutScopeBecause() requires a reason.');
        }

        return $callback(static::withoutGlobalScopes([SiteScope::class, OrgScope::class]));
    }
}
