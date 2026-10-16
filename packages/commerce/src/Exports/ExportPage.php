<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Exports;

use Carbon\CarbonImmutable;

/** A complete, checked and bounded artifact, never a lazy database-backed response. @internal */
final readonly class ExportPage
{
    public const MAX_BYTES = 16777216;

    public function __construct(
        #[\SensitiveParameter] public string $body,
        public string $format,
        public ?string $nextCursor,
        public int $count,
    ) {
        if (! in_array($format, ['jsonl', 'csv'], true) || strlen($body) > self::MAX_BYTES || $count < 0 || $count > 100) {
            throw new ExportRefused;
        }
    }

    public function filename(): string
    {
        return 'commerce-history-'.CarbonImmutable::now('UTC')->format('Ymd\THis\Z').'.'.$this->format;
    }

    public function contentType(): string
    {
        return $this->format === 'jsonl' ? 'application/x-ndjson; charset=UTF-8' : 'text/csv; charset=UTF-8';
    }
}
