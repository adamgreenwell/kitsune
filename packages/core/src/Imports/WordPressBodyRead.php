<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Imports;

/** @internal Retain body bytes only for selected posts and only within the source reader's budget. */
final class WordPressBodyRead
{
    public const MAX_BODY_BYTES = 524288;

    public const MAX_TOTAL_BYTES = 16777216;

    private int $retained = 0;

    /** @param list<string> $types */
    public function __construct(private readonly array $types) {}

    public function selects(string $type, string $status): bool
    {
        return in_array($type, $this->types, true) && in_array($status, ['publish', 'draft'], true);
    }

    /** @return array{body?: string, body_finding?: string} */
    public function collect(?string $body, bool $protected): array
    {
        if ($protected) {
            return ['body_finding' => 'body-is-password-protected'];
        }
        if ($body === null || strlen($body) > self::MAX_BODY_BYTES) {
            return ['body_finding' => 'body-exceeds-byte-limit'];
        }
        if (strlen($body) > self::MAX_TOTAL_BYTES - $this->retained) {
            return ['body_finding' => 'body-batch-exceeds-byte-limit'];
        }
        $this->retained += strlen($body);

        return ['body' => $body];
    }
}
