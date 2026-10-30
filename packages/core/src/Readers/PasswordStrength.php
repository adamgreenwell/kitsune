<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Readers;

/**
 * The optional, local password-form enhancement and the exact CSP source that permits it (ADR-037, #301).
 * No interpolated executable data: copy and bounds live in escaped HTML attributes instead.
 *
 * @internal First-party reader pages only.
 */
final class PasswordStrength
{
    public static function script(): string
    {
        $script = file_get_contents(__DIR__.'/../../resources/js/password-strength.js');

        return $script === false ? throw new \RuntimeException('Password feedback script is unavailable.') : $script;
    }

    public static function source(): string
    {
        return "'sha256-".base64_encode(hash('sha256', self::script(), true))."'";
    }
}
