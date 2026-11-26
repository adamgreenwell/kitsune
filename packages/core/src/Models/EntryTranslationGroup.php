<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Kitsune\Core\Relations\EntryTranslationGroupBuilder;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\FixesColumnsAtCreation;

/**
 * The relation target's identity, independent of the lifecycle of any member.
 *
 * @property int $id
 * @property int $org_id
 * @property string $uuid
 */
#[OrgScoped]
final class EntryTranslationGroup extends Model implements FixesColumnsAtCreation
{
    use EnforcesScope;

    public $timestamps = false;

    protected $fillable = ['uuid'];

    /** @param Builder $query */
    public function newEloquentBuilder($query): EntryTranslationGroupBuilder
    {
        return new EntryTranslationGroupBuilder($query, $this);
    }

    public static function columnsFixedAtCreation(): array
    {
        return [
            'id' => 'stored relations use this group identity.',
            'org_id' => 'a group belongs to the same organisation for its entire lifetime.',
            'uuid' => 'membership is keyed by the UUID assigned to the group.',
        ];
    }

    /**
     * Public publication eligibility. The caller must still enforce its entry
     * access policy and the relation field's target restrictions.
     */
    public static function publishedMember(int $groupId): ?Entry
    {
        $context = app(Context::class);
        $siteId = $context->siteId();
        $orgId = $context->orgId();

        if ($siteId === null || $orgId === null) {
            return null;
        }

        return Entry::query()
            ->where('org_id', $orgId)
            ->where('site_id', $siteId)
            ->whereIn('translation_group', self::query()->where('org_id', $orgId)->whereKey($groupId)->select('uuid'))
            ->published()
            ->first();
    }
}
