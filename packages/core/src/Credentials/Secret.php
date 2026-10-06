<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * A decrypted credential, which cannot be printed, logged, serialised or compared by accident — ADR-040.
 *
 * ⚠️ THE VALUE IS NOT A PROPERTY. It lives in a static `WeakMap` keyed by the object, so `var_dump`, `print_r`,
 * `var_export`, an `(array)` cast and `json_encode` find nothing to show — measured, each of them — and a stack frame
 * prints the object as `Object(Kitsune\Core\Credentials\Secret)`. A consumer can pass one through its own code without
 * marking every parameter `#[\SensitiveParameter]`.
 *
 * ⚠️ NO `__toString()`, so `"{$secret}"` and `{{ $secret }}` fail loudly rather than print. And `==` between two
 * secrets compares nothing — they have no properties: compare revealed values with `hash_equals()`.
 *
 * @internal First-party modules only; nothing outside this repository may rely on it existing or keeping its shape.
 */
final class Secret implements JsonSerializable
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        self::$values ??= new WeakMap;
        self::$values[$this] = $value;
    }

    /** The only way out. Call it where the provider's SDK is handed the key, and nowhere else. */
    public function reveal(): string
    {
        return self::$values[$this] ?? throw new LogicException('This secret holds no value.');
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[withheld]'];
    }

    /** So a log context that holds one stays readable, and says nothing. */
    public function jsonSerialize(): string
    {
        return '[withheld]';
    }

    /**
     * Never into a queue payload, a cache or a session: a job carries the credential's name and reads it when it runs.
     *
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret is never serialised. Pass the credential\'s name, and read it where it is used.');
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A secret is never unserialised.');
    }

    /** A clone would hold no value, and would fail only where it was finally revealed. */
    public function __clone()
    {
        throw new LogicException('A secret is never cloned.');
    }
}
