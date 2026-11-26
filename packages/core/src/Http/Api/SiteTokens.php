<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use DateTimeInterface;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Tenancy\Context;
use Laravel\Sanctum\PersonalAccessToken;

/** @internal Bearer access for first-party product API routes. */
final class SiteTokens
{
    public const GUARD = 'kitsune-site-api';

    public static function register(): void
    {
        $provider = self::provider();

        if ($provider === null) {
            return;
        }

        config(['auth.guards.'.self::GUARD => ['driver' => self::GUARD, 'provider' => $provider]]);
        Auth::viaRequest(self::GUARD, self::authenticate(...));
    }

    public static function enabled(): bool
    {
        return self::staffModel() !== null && class_exists(PersonalAccessToken::class);
    }

    public static function actor(string $identifier): ?Authenticatable
    {
        $model = self::staffModel();
        $key = $model === null ? null : Permissions::userKey($identifier, $model);

        return $key === null ? null : Auth::createUserProvider(self::provider())?->retrieveById($key);
    }

    /** @param list<string> $abilities */
    public static function issue(Authenticatable $actor, string $name, array $abilities, DateTimeInterface $expires): string
    {
        $context = app(Context::class);
        $actorModel = self::staffModel();
        $query = self::query($context, $actorModel);

        if (! $actor instanceof Model || $actorModel === null || $query === null
            || ! self::admitted($actor, $actorModel, $context) || ! self::hasPersistedMemberships($actor, $context)) {
            throw new InvalidArgumentException('The staff account must belong to the selected Site and Org.');
        }

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120 || $abilities === [] || $expires <= now()) {
            throw new InvalidArgumentException('Provide a name, at least one ability, and a future expiry.');
        }

        $types = EntryType::visibleFor($context->site(), $context->orgId())->keyBy('handle');
        foreach ($abilities as $ability) {
            $handle = explode('.', $ability)[1] ?? '';
            if (! $types->has($handle) || ! Permissions::allows($actor, $ability)) {
                throw new InvalidArgumentException('Every requested token ability must be held for an enabled entry type.');
            }
        }

        $secret = bin2hex(random_bytes(32));
        $token = $query->newModelInstance();
        $token->forceFill([
            'site_id' => $context->siteId(),
            'org_id' => $context->orgId(),
            'actor_id' => $actor->getAuthIdentifier(),
            'actor_model' => $actorModel,
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => array_values(array_unique($abilities)),
            'expires_at' => $expires,
        ])->save();

        return $token->getKey().'|'.$secret;
    }

    public static function revoke(int $id): bool
    {
        return self::query(app(Context::class), self::staffModel())?->whereKey($id)->delete() === 1;
    }

    public static function allows(Request $request, string $type, string $action): bool
    {
        $actor = $request->user(self::GUARD);
        $token = $request->attributes->get(SiteAccessToken::class);
        $context = app(Context::class);

        if (! $token instanceof SiteAccessToken || ! $actor instanceof Authenticatable
            || $token->site_id !== $context->siteId() || $token->org_id !== $context->orgId()
            || (string) $token->actor_id !== (string) $actor->getAuthIdentifier()) {
            return false;
        }

        $ability = Permissions::forEntryType($type, $action);

        return in_array($ability, $token->abilities, true)
            && EntryType::visibleFor($context->site(), $context->orgId())->contains('handle', $type)
            && Permissions::allows($actor, $ability);
    }

    private static function authenticate(Request $request): ?Authenticatable
    {
        $credential = $request->bearerToken();
        if ($credential === null || preg_match('/\A([1-9][0-9]{0,18})\|([a-f0-9]{64})\z/', $credential, $parts) !== 1
            || filter_var($parts[1], FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $context = app(Context::class);
        $actorModel = self::staffModel();
        $token = self::query($context, $actorModel)?->find((int) $parts[1]);
        if ($token === null || $actorModel === null || $token->actor_model !== $actorModel
            || $token->expires_at <= now() || ! hash_equals($token->token, hash('sha256', $parts[2]))) {
            return null;
        }

        $actor = Auth::createUserProvider(self::provider())?->retrieveById($token->actor_id);
        if ($actor === null || ! self::admitted($actor, $actorModel, $context)) {
            return null;
        }

        $request->attributes->set(SiteAccessToken::class, $token);

        return $actor;
    }

    /** @param class-string<Model> $actorModel */
    private static function admitted(Authenticatable $actor, string $actorModel, Context $context): bool
    {
        $site = $context->site();

        return $site !== null
            && $actor::class === $actorModel
            && $actor instanceof HasTenants
            && Permissions::isCurrentOrgMember($actor)
            && $actor->canAccessTenant($site);
    }

    private static function hasPersistedMemberships(Model&Authenticatable $actor, Context $context): bool
    {
        $db = $actor->getConnection();
        $key = $actor->getAuthIdentifier();

        return $db->table('site_user')->where('site_id', $context->siteId())->where('user_id', $key)->exists()
            && $db->table('org_user')->where('org_id', $context->orgId())->where('user_id', $key)->exists();
    }

    private static function provider(): ?string
    {
        $provider = config('kitsune.api.staff_provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /** @return class-string<Model>|null */
    private static function staffModel(): ?string
    {
        $provider = self::provider();
        $model = $provider === null ? null : config("auth.providers.{$provider}.model");

        return is_string($model) && is_subclass_of($model, Model::class) && is_subclass_of($model, Authenticatable::class)
            && is_subclass_of($model, HasTenants::class)
            ? $model
            : null;
    }

    /**
     * @param  class-string<Model>|null  $staffModel
     * @return Builder<SiteAccessToken>|null
     */
    private static function query(Context $context, ?string $staffModel): ?Builder
    {
        if ($staffModel === null || ! $context->hasSite() || ! $context->hasOrg()
            || ! class_exists(PersonalAccessToken::class)) {
            return null;
        }

        $staff = new $staffModel;

        return SiteAccessToken::on($staff->getConnectionName())
            ->where('site_id', $context->siteId())
            ->where('org_id', $context->orgId());
    }
}
