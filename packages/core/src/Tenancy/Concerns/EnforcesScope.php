<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Tenancy\Concerns;

use Illuminate\Database\Query\Builder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Attributes\OrgScopedThroughPivot;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\ScopedBuilder;
use Kitsune\Core\Tenancy\ScopeResolver;
use Kitsune\Core\Tenancy\Scopes\OrgMembershipScope;
use Kitsune\Core\Tenancy\Scopes\OrgScope;
use Kitsune\Core\Tenancy\Scopes\SiteScope;
use ReflectionClass;
use RuntimeException;

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

        if ($declared === OrgScopedThroughPivot::class) {
            // The attribute carries the pivot's shape, so the scope is
            // configured from the declaration rather than from convention —
            // a model that gets the table name wrong fails at boot, not on
            // the first query that should have been constrained.
            $attribute = (new ReflectionClass(static::class))
                ->getAttributes(OrgScopedThroughPivot::class)[0]
                ->newInstance();

            static::addGlobalScope(new OrgMembershipScope($attribute));
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

            $model->guardScopeWrite($declared);
        });

        // ⚠️ And on UPDATE. Stamping happens on create, so nothing stopped a
        // saved row being moved: `$entry->org_id = $rival; $entry->save()`.
        static::updating(function (self $model) use ($declared): void {
            if ($model->isDirty(['org_id', 'site_id'])) {
                $model->guardScopeWrite($declared);
            }
        });
    }

    /**
     * ⚠️ Every write goes through the scoped builder as well.
     *
     * The guards below run from model events, and a mass update instantiates
     * no models: `Entry::query()->update(['org_id' => $rival])` transferred
     * rows into another org without dispatching anything. A model that needs a
     * different builder overrides this — `Entry`, `FieldStorage` and
     * `AuditLog` each do, and each carries the same scope-key check.
     *
     * @param  Builder  $query
     * @return ScopedBuilder<$this>
     */
    public function newEloquentBuilder($query): ScopedBuilder
    {
        return new ScopedBuilder($query, $this);
    }

    /**
     * ⚠️ Stamping is not ENFORCING, and this trait's docblock claimed both.
     *
     * The stamp filled `site_id` only when the key was absent and `org_id`
     * only through `?? $context->orgId()`, so a caller who supplied BOTH had
     * them inserted verbatim. With the context on org A,
     * `Entry::create(['org_id' => $b, 'site_id' => $siteB, ..., 'status' =>
     * 'published'])` planted published content on a rival's public site —
     * where SiteScope then showed it to them and hid it from its author.
     * `$guarded = []` on these models is what made it one array away.
     *
     * The existing hostile test covered the HALF-forced case (`org_id` alone,
     * with `site_id` still pinning the row) and passed, which is why this
     * survived: the two-key case was the one nobody wrote.
     *
     * Silent with no context rather than refusing, because console commands,
     * migrations and the installer legitimately run without one — the same
     * reason the stamp cannot happen there either. What that leaves is a
     * database NOT NULL, which is a constraint doing the work rather than the
     * design; ADR-021 records it.
     */
    private function guardScopeWrite(?string $declared): void
    {
        if (self::$scopeWritesUnguarded) {
            return;
        }

        $context = app(Context::class);

        if ($declared === SiteScoped::class || $declared === OrgScoped::class) {
            $this->guardScopeKey('org_id', $context->orgId());
        }

        if ($declared === SiteScoped::class) {
            // NULL is org-shared and legitimate; any other value must be the
            // site we are in.
            $this->guardScopeKey('site_id', $context->siteId(), nullable: true);
        }
    }

    private function guardScopeKey(string $column, ?int $current, bool $nullable = false): void
    {
        $value = $this->getAttribute($column);

        if ($value === null && $nullable) {
            return;
        }

        if ($current === null || $value === null || (int) $value === $current) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to write %s with [%s] = %s from a context scoped to %s. A scope that only '
            .'filters SELECTs still lets a caller write a row belonging to somebody else, and '
            .'reading and writing are separate holes (ADR-021). Use withoutScopeBecause() if this '
            .'is deliberate.',
            static::class,
            $column,
            (string) $value,
            (string) $current,
        ));
    }

    /**
     * Set while withoutScopeBecause() runs, so the reviewable escape hatch can
     * still write across scopes — provisioning and cross-org admin tooling
     * legitimately do.
     */
    private static bool $scopeWritesUnguarded = false;

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

        $previous = self::$scopeWritesUnguarded;
        self::$scopeWritesUnguarded = true;

        try {
            return $callback(static::withoutGlobalScopes([
                SiteScope::class,
                OrgScope::class,
                OrgMembershipScope::class,
            ]));
        } finally {
            self::$scopeWritesUnguarded = $previous;
        }
    }
}
