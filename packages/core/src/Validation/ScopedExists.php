<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Existence checked through Eloquent, so global scopes apply.
 *
 * The mirror of ScopedUnique, and the more dangerous of the two. Laravel's
 * `exists` ignores global scopes, so a form accepting an id would happily
 * validate a row belonging to another org — and then the application would
 * write a reference to it. That is not an information leak, it is a
 * cross-org write.
 */
final class ScopedExists implements ValidationRule
{
    /** @param class-string<Model> $model */
    public function __construct(
        private readonly string $model,
        private readonly ?string $column = null,
        private readonly ?Closure $using = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var Model $instance */
        $instance = new $this->model;

        $query = $instance->newQuery();
        $column = $this->column ?? $instance->getKeyName();

        $query->where($column, $value);

        if ($this->using !== null) {
            ($this->using)($query);
        }

        if (! $query->exists()) {
            $fail('validation.exists')->translate(['attribute' => $attribute]);
        }
    }
}
