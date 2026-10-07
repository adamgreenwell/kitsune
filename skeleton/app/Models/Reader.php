<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kitsune\Core\Readers\Contracts\ReaderAccount;
use Kitsune\Core\Tenancy\Attributes\OrgScoped;
use Kitsune\Core\Tenancy\Concerns\EnforcesScope;

/**
 * A reader of this organisation's sites — ADR-037. The host owns a reader's identity, as it owns a staff user's: this
 * model, its table and its guard (`AppServiceProvider::readerConfig()`). Core owns the front door — sign-in, and later
 * sign-up and recovery — and reaches this row only through `ReaderAccount`.
 *
 * ⚠️ NEVER A PANEL USER. Not `FilamentUser`, not `HasTenants`, and not Laravel's `Foundation\Auth\User`, which would
 * bring `CanResetPassword`, `MustVerifyEmail` and `Authorizable` with it: a reader cannot reach a panel, and core's own
 * flows, not Laravel's password broker, recover an account (the broker keys its tokens by address alone, across orgs).
 *
 * ⚠️ ONE ORG, BY ITS OWN `org_id`. `#[OrgScoped]` with `EnforcesScope` — without the trait the attribute is documentation
 * — so under org B's context org A's reader does not exist, and `ReaderGuard` refuses a model without the scope.
 *
 * @property int $id
 * @property int $org_id
 * @property string $email
 * @property string|null $password
 * @property CarbonImmutable|null $email_verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[OrgScoped]
class Reader extends Model implements AuthenticatableContract, ReaderAccount
{
    use Authenticatable;
    use EnforcesScope;

    protected $table = 'readers';

    /** Written through `createReader()` and core's `forceFill()` only. */
    protected $guarded = ['*'];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        // `hashed` keeps a hash it is given, so core's already-hashed password is stored unchanged.
        return ['password' => 'hashed', 'email_verified_at' => 'immutable_datetime'];
    }

    /** Lower-cased on every write, so a write of the host's own — tinker, an import — cannot store a second spelling. */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (string $value): string => strtolower($value));
    }

    public static function findByEmail(#[\SensitiveParameter] string $email): ?static
    {
        return static::query()->where('email', $email)->first();
    }

    public static function createReader(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] ?string $passwordHash,
        ?DateTimeInterface $verifiedAt,
    ): static {
        // `EnforcesScope` stamps the org in context as `org_id`.
        return static::query()->forceCreate([
            'email' => $email,
            'password' => $passwordHash,
            'email_verified_at' => $verifiedAt,
        ]);
    }

    public function readerEmail(): string
    {
        return $this->email;
    }

    /**
     * What this model holds about a reader — its own classification of what is personal (ADR-037). The password hash
     * and the remember token are secrets, never exported; `org_id` is the organisation the export is for.
     *
     * @return array<string, scalar|null>
     */
    public function exportAccount(): array
    {
        return [
            'reader' => (string) $this->getKey(),
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'has_password' => $this->password !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    public function eraseAccount(): void
    {
        $this->delete();
    }
}
