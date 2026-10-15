<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Payments;

use RuntimeException;

/** No provider payload, credential or previous transport exception leaves this boundary. @internal */
final class StripeUnavailable extends RuntimeException
{
    /** @var 'unknown'|'rejected' */
    public readonly string $category;

    public function __construct(string $category = 'unknown')
    {
        $this->category = $category === 'rejected' ? 'rejected' : 'unknown';
        parent::__construct($this->category === 'rejected'
            ? 'The Stripe request was refused.'
            : 'The Stripe outcome could not be confirmed.');
    }
}
