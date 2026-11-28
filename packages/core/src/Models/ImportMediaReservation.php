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

/**
 * @internal
 *
 * @property int $id
 * @property int $import_source_id
 * @property int $import_execution_id
 * @property string $source_type
 * @property string $source_id_hash
 * @property string $descriptor_sha256
 * @property string $disk
 * @property ?string $path
 * @property string $managed_path
 * @property ?string $managed_sha256
 * @property ?string $managed_object_version
 * @property ?int $entry_id
 * @property string $content_sha256
 * @property int $source_size_bytes
 * @property bool $staged
 * @property ?string $staged_object_version
 * @property bool $staging_purged
 */
#[SiteScoped]
final class ImportMediaReservation extends Model
{
    use EnforcesScope;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $attributes = ['staged' => false];

    protected $casts = ['staged' => 'boolean', 'staging_purged' => 'boolean'];

    /** @return BelongsTo<ImportExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(ImportExecution::class, 'import_execution_id');
    }
}
