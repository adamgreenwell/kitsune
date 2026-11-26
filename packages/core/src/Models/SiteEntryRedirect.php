<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kitsune\Core\Tenancy\Attributes\SiteScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;
use Kitsune\Core\Tenancy\Context;
use Kitsune\Core\Tenancy\Contracts\FixesColumnsAtCreation;

/**
 * @property int $id
 * @property int $site_id
 * @property int $org_id
 * @property int $target_entry_id
 * @property string $source_type
 * @property string $source_slug
 * @property string $source_key
 */
#[SiteScoped]
final class SiteEntryRedirect extends Model implements FixesColumnsAtCreation
{
    use EnforcesScope;

    protected $fillable = ['source_type', 'source_slug', 'source_key', 'target_entry_id'];

    public static function sourceKey(string $type, string $slug): string
    {
        return hash('sha256', $type.'/'.$slug);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'target_entry_id')->where('site_id', app(Context::class)->siteId());
    }

    public static function columnsFixedAtCreation(): array
    {
        return [
            'site_id' => 'the former address belongs to this Site.',
            'org_id' => 'the former address and target must remain in the same Org.',
            'source_type' => 'the type identifies the original address.',
            'source_slug' => 'the slug identifies the original address.',
            'source_key' => 'the key indexes the exact source type and slug.',
            'target_entry_id' => 'record a new redirect to replace the destination.',
        ];
    }
}
