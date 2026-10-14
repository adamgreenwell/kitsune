<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers;

use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Kitsune\Core\Credentials\CredentialMode;
use Kitsune\Core\Credentials\CredentialRefusal;
use Kitsune\Core\Credentials\CredentialRefused;
use Kitsune\Core\Credentials\CredentialSlot;
use Kitsune\Core\Credentials\CredentialSlots;
use Kitsune\Core\Filament\Pages\Credentials;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The native credential forms' request boundary; no plaintext survives in a request or session. @internal
 *
 * @phpstan-type CredentialInput array{value: ?string, password: ?string, inAddress: bool, tokenMatches: bool, slot: mixed, mode: mixed}
 */
final class CredentialMutationRequest
{
    public const PASSWORD = 'current_password';

    /** Alternate URL inputs kept by IIS, reverse proxies and Apache internal redirects. */
    private const URL_ALIASES = [
        'QUERY_STRING', 'UNENCODED_URL', 'IIS_WasUrlRewritten', 'ORIG_PATH_INFO', 'RAW_URI',
        'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_X_ORIGINAL_URI',
        'REDIRECT_URL', 'REDIRECT_QUERY_STRING', 'REDIRECT_REQUEST_URI',
    ];

    /**
     * Empty every parsed bag, the cached raw body, URL query and PHP globals before authentication or application work.
     * A URL-carried secret is refused even when a body carries another value. Only native form bodies authorize a write.
     *
     * @return CredentialInput
     */
    public static function take(#[\SensitiveParameter] Request $request): array
    {
        $query = $request->query->all();
        $body = $request->request->all();
        $native = ! $request->isJson();
        $source = $native ? $body : $request->json()->all();
        $value = $source[CredentialSetController::FIELD] ?? null;
        $password = $source[self::PASSWORD] ?? null;
        $inAddress = array_key_exists(CredentialSetController::FIELD, $query) || array_key_exists(self::PASSWORD, $query);

        // Remove first: even an absent session or a malformed token must find no secret when it throws.
        foreach ([CredentialSetController::FIELD, self::PASSWORD] as $field) {
            $request->request->remove($field);
            $request->query->remove($field);
            unset($_POST[$field], $_GET[$field], $_REQUEST[$field]);
        }

        $token = $body['_token'] ?? null;
        $server = $request->server->all();
        // Keep the original base path/front controller: getPathInfo() would remove both from generated redirects.
        $server['REQUEST_URI'] = explode('?', $request->getRequestUri(), 2)[0];
        foreach (self::URL_ALIASES as $field) {
            unset($server[$field], $_SERVER[$field]);
        }
        $request->initialize([], [], $request->attributes->all(), $request->cookies->all(), [], $server, '');
        $request->setJson(new InputBag);
        $_POST = $_GET = $_REQUEST = [];

        if (isset($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = $server['REQUEST_URI'];
        }

        $matches = $native && $request->hasSession() && is_string($token) && $token !== ''
            && hash_equals((string) $request->session()->token(), $token);

        return [
            'value' => is_string($value) ? $value : null,
            'password' => is_string($password) ? $password : null,
            'inAddress' => $inAddress,
            'tokenMatches' => $matches,
            'slot' => $body['slot'] ?? null,
            'mode' => $body['mode'] ?? null,
        ];
    }

    /**
     * Body-only CSRF remains mandatory when the host skips or replaces its panel middleware.
     *
     * @param  CredentialInput  $input
     */
    public static function authorize(#[\SensitiveParameter] array $input, string $title): bool
    {
        abort_unless($input['tokenMatches'], 419);
        abort_unless(Credentials::canAccess(), 403);

        if ($input['inAddress']) {
            Notification::make()->danger()->persistent()->title(e($title))
                ->body(e(__('kitsune::credentials.authentication.in_address')))->send();

            return false;
        }

        return true;
    }

    /**
     * A declared line with its exact supported mode; client input is never an authorization boundary.
     *
     * @param  CredentialInput  $input
     * @return array{CredentialSlot, ?CredentialMode}
     */
    public static function line(#[\SensitiveParameter] array $input): array
    {
        $declared = is_string($input['slot']) ? app(CredentialSlots::class)->find($input['slot']) : null;
        $mode = is_string($input['mode']) ? CredentialMode::tryFrom($input['mode']) : null;
        abort_unless($declared !== null && ($declared->moded ? $mode !== null : $input['mode'] === null), 404);

        return [$declared, $mode];
    }

    public static function message(CredentialRefused $refused): string
    {
        return $refused->reason === CredentialRefusal::FreshAuthentication
            ? __('kitsune::credentials.authentication.refused')
            : $refused->getMessage();
    }

    public static function refused(string $title, ?string $body = null): void
    {
        Notification::make()->danger()->persistent()->title(e($title))
            ->body(e($body ?? __('kitsune::credentials.authentication.unavailable')))->send();
    }
}
