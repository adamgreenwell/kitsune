<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Middleware;

use Closure;
use Filament\Panel;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\AuthenticateSession;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Filament\Panels\KitsunePanel;
use Kitsune\Core\Media\MediaStaging;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Only a user who may upload media may stage a file — ADR-042 decision 4.
 *
 * @internal
 *
 * ⚠️ ON LIVEWIRE'S UPLOAD ENDPOINT, WHICH SERVES THE WHOLE INSTALLATION. Its signature can be minted by any component
 * using `WithFileUploads` — in the panel that is every page, plus Filament's topbar, sidebar and widgets — so without
 * this any signed-in user, a reader or a viewer, could stage 64 MiB files the handler would later refuse, abandon
 * them, and fill the disk. Refused here, before the controller checks the signature, validates or writes anything.
 *
 * ⚠️ AND THE ONLY PLACE THE OPPORTUNISTIC SWEEP RUNS, after the endpoint has accepted and staged a file, so no
 * scheduler is needed to bound the intake directory. A refused request walks no disk.
 */
final class GuardUploadStaging
{
    /** Fixed, so a refusal here can be told apart from CSRF (419), the signature (401) and the throttle (429). */
    public const REFUSAL = 'Refusing this upload: staging a file takes create and publish on a media type, at a site '
        .'you can reach — the same trust as uploading one (ADR-042).';

    public const MALFORMED = 'Refusing this upload: it must arrive as a list of files.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Permissions::mayStageUploads(self::user($request))) {
            return response()->json(['message' => self::REFUSAL], 403);
        }

        /*
         * ⚠️ A LIST OF FILES, OR NOTHING RUNS. Livewire's rules are keyed `files.*`, and a single `files` part matches
         * no key, so not one rule would run on it — only an incidental error stops the write today.
         */
        if (! self::isListOfFiles($request->allFiles()['files'] ?? null)) {
            return response()->json(['message' => self::MALFORMED, 'errors' => ['files' => [self::MALFORMED]]], 422);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            // A sweep that fails is reported, never thrown: the upload it follows has already been accepted.
            try {
                MediaStaging::sweep();
            } catch (Throwable $failed) {
                report($failed);
            }
        }

        return $response;
    }

    /**
     * Judged as what arrived rather than as the request's declared types promise: `files[0][x]` nests an array where
     * the framework's signatures say uploads.
     */
    private static function isListOfFiles(mixed $files): bool
    {
        if (! is_array($files) || $files === [] || ! array_is_list($files)) {
            return false;
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                return false;
            }
        }

        return true;
    }

    /**
     * The classes a middleware list names, as the router would run them.
     *
     * ⚠️ ALIASES AND GROUPS RESOLVED — Codex, #152. A panel may name the session check as `auth.session`, the alias
     * Laravel registers for it, or inside a middleware group; the router runs the class either way, and a check on the
     * written name alone would miss it. Parameters are dropped, and a group that names itself is not followed twice.
     *
     * @param  array<mixed>  $middleware
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private static function middlewareClasses(array $middleware, array $seen = []): array
    {
        $router = app('router');
        $aliases = $router->getMiddleware();
        $groups = $router->getMiddlewareGroups();
        $classes = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $name = explode(':', $entry)[0];

            if (isset($groups[$name])) {
                if (! isset($seen[$name])) {
                    array_push($classes, ...self::middlewareClasses($groups[$name], [...$seen, $name => true]));
                }

                continue;
            }

            $resolved = $aliases[$name] ?? $name;
            $classes[] = is_string($resolved) ? $resolved : $name;
        }

        return $classes;
    }

    /**
     * Who is asking, by the Kitsune panel's own guard when there is one.
     *
     * ⚠️ THE PANEL'S GUARD, NOT THE DEFAULT ONE. The endpoint runs no `auth` middleware, and a host whose Kitsune panel
     * authenticates through a guard of its own would otherwise be asked about a user nobody signed in as.
     */
    private static function user(Request $request): ?Authenticatable
    {
        $panel = app()->bound(KitsunePanel::PANEL_BINDING) ? app(KitsunePanel::PANEL_BINDING) : null;

        if (! $panel instanceof Panel) {
            return auth()->user();
        }

        $user = $panel->auth()->user();

        return $user !== null && self::sessionStillHolds($request, $panel, $user) ? $user : null;
    }

    /**
     * ⚠️ A SESSION THE PANEL WOULD LOG OUT IS NOBODY. A panel running Laravel's `AuthenticateSession` ends a session
     * whose stored password hash no longer matches — the password changed on another device — at its next page. The
     * endpoint runs no such middleware, so a session kept off the panel's pages would go on staging. The same comparison,
     * read-only, and only when the panel makes it: logging the session out is the panel's to do.
     */
    private static function sessionStillHolds(Request $request, Panel $panel, Authenticatable $user): bool
    {
        // Both lists: Filament runs its auth middleware on every signed-in page too, and a host may put the check there.
        $checksSessions = array_filter(
            self::middlewareClasses([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]),
            static fn (string $middleware): bool => is_a($middleware, AuthenticateSession::class, true),
        ) !== [];

        $password = $user->getAuthPassword();

        if (! $checksSessions || ! $request->hasSession() || $password === '') {
            return true;
        }

        $stored = $request->session()->get('password_hash_'.$panel->getAuthGuard());

        // None stored yet: the panel stores one at the next page it serves, and has ended nothing.
        if (! is_string($stored)) {
            return true;
        }

        $guard = $panel->auth();

        // Laravel's own comparison: its HMAC of the hash, or the raw hash an older session stored.
        return ($guard instanceof SessionGuard && hash_equals($guard->hashPasswordForCookie($password), $stored))
            || hash_equals($password, $stored);
    }
}
