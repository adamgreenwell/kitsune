<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * A reader's pages — ADR-037, as built. `:site` is the site's name, and every replacement is escaped by the view.
 *
 * ⚠️ NO PROMISE IN 0.x. A host may override these through `lang/vendor/kitsune/{locale}/readers.php`, and a locale that
 * has this file is the locale the page's copy is rendered in (`ReaderPage`); the keys may still change before v1.1.
 *
 * ⚠️ EVERY KEY IS A STRING OR AN ARRAY, NEVER BOTH: a key holding an array cannot also be read as a sentence.
 */
return [
    'back' => 'Back to :site',
    'sign_in' => [
        'title' => 'Sign in',
        'button' => 'Sign in',
        'email' => 'Email address',
        'password' => 'Password',
        'failed' => 'That email address and password don\'t match an account here. Check both and try again.',
    ],
    'sign_out' => [
        'button' => 'Sign out',
        'done' => 'You\'ve signed out.',
    ],
    'home' => [
        'title' => 'Your account',
        'signed_in_as' => 'Signed in as :email.',
    ],
    'field' => [
        'password_missing' => 'Enter your password.',
    ],
    'throttle' => [
        'ip' => 'Too many attempts from your connection. Please wait a minute, then try again. Nothing was checked or sent.',
        // Counted: `ReaderPage` picks the form by `:count`, the whole minutes still to wait.
        'account' => '{1} Too many attempts to sign in to this account. Please wait a minute, or choose a new password.|[2,*] Too many attempts to sign in to this account. Please wait :count minutes, or choose a new password.',
    ],
    'errors' => [
        'summary' => 'There is a problem',
    ],
    'title' => [
        'error_prefix' => 'Error:',
    ],
];
