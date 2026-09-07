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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Uniqueness checked through Eloquent, so global scopes apply.
 *
 * Laravel's `unique` rule queries the database directly and does NOT go
 * through Eloquent, so it ignores every global scope. In a multi-org
 * installation that is an information leak, and a subtle one: org B is told
 * a slug is taken because org A holds it. The message reveals the existence
 * of another customer's content, and the user cannot act on it.
 *
 * CONTRIBUTING makes this a rule that fails the build. This is the
 * replacement it points at.
 */
final class ScopedUnique implements ValidationRule
{
    /** @param class-string<Model> $model */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
        private readonly mixed $ignoreId = null,
        private readonly ?Closure $using = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var Model $instance */
        $instance = new $this->model;

        // newQuery(), not the query builder: this is the whole point. Global
        // scopes are applied here and are not by Laravel's `unique`.
        $query = $instance->newQuery();

        // Soft-deleted rows still occupy the database's unique index, which
        // does not include deleted_at. Excluding them here reports the value
        // as free and then the INSERT fails on a constraint violation — a
        // 500 where the user should have seen a validation message. The
        // check has to match what the database will actually enforce.
        if (in_array(SoftDeletes::class, class_uses_recursive($instance), true)) {
            // Drop the soft-delete scope directly: withTrashed() is added by
            // the trait and PHPStan cannot see it on a generic Builder.
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $query->where($this->column, $value);

        if ($this->ignoreId !== null) {
            $query->whereKeyNot($this->ignoreId);
        }

        if ($this->using !== null) {
            ($this->using)($query);
        }

        if ($query->exists()) {
            $fail('validation.unique')->translate(['attribute' => $attribute]);
        }
    }
}
