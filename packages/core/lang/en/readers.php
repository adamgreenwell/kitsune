<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

/*
 * A reader's pages and mails — ADR-037, as built. `:site` is the site's name. On a page every replacement is escaped by
 * the view; a mail is plain text, sent as written, so nothing in it is markup (`mail.*`).
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
        'forgot' => 'Forgotten your password?',
        'register' => 'Create an account',
    ],
    'register' => [
        'title' => 'Create an account',
        'intro' => 'Enter your email address and we\'ll send you a link. Your account is made when you use the link and choose a password.',
        'closed' => 'Accounts can\'t be created here right now, because this site can\'t send email yet. Signing in still works.',
    ],
    'recover' => [
        'title' => 'Forgotten your password?',
        'intro' => 'Enter your account\'s email address and we\'ll send it a link to choose a new password.',
        'closed' => 'Password recovery isn\'t available here right now, because this site can\'t send email yet.',
    ],
    'link' => [
        'button' => 'Send me a link',
        'again' => 'Ask for a new link',
        'dead' => 'This link doesn\'t work any more. A link works once, for :minutes minutes, and a newer link replaces an older one.',
        'sign_in' => 'Go to sign in',
    ],
    'sent' => [
        'title' => 'Check your email',
        // Neutral across every branch: the page cannot say which mail went, or whether one did.
        'register' => 'If that address can receive email, we\'ve sent it a message. If it can be used for a new account here, the message has a link that works once, for :minutes minutes. Nothing arrived? Check your spam folder, or ask again after :gap minutes.',
        'recover' => 'If that address can receive email, we\'ve sent it a message. If it has an account here, the message has a link to choose a new password, which works once, for :minutes minutes. Nothing arrived? Check your spam folder, or ask again after :gap minutes.',
    ],
    'complete' => [
        'title' => 'Choose your password',
        'for' => 'You\'re creating an account for :email.',
        'button' => 'Create my account',
        'done' => 'Your account is ready.',
        'exists' => 'An account with this address already exists here. Sign in, or choose a new password if you\'ve forgotten it. Nothing was changed.',
    ],
    'reset' => [
        'title' => 'Choose a new password',
        'for' => 'You\'re choosing a new password for :email.',
        'button' => 'Change my password',
        'done' => 'Your password has been changed, and you\'ve been signed out everywhere else.',
    ],
    'password' => [
        'account' => 'Account',
        'new' => 'New password',
        'confirm' => 'Type the same password again',
        'hint' => 'At least :min characters. A few words in a row is easy to remember and hard to guess.',
        'missing' => 'Choose a password.',
        'short' => 'Your password must be at least :min characters. A few words in a row is easy to remember and hard to guess.',
        'long' => 'Your password is longer than :max bytes, and only the first :max would count. Please choose a shorter one.',
        'edges' => 'Your password can\'t begin or end with a space or an invisible character.',
        'unsendable' => 'Your password contains a character this form can\'t send. Please choose another.',
        'is_email' => 'Your password can\'t be your email address.',
        'confirm_missing' => 'Type your new password again.',
        'mismatch' => 'The two passwords don\'t match.',
    ],
    'mail' => [
        'finish' => [
            'subject' => 'Finish creating your :site account',
            'body' => "Hello,\n\nSomeone, we hope you, asked to create an account on :site with this email address.\n\nTo finish, open this link and choose a password. It works once, for :minutes minutes:\n\n:link\n\nIf you didn't ask, you can ignore this email. No account is made unless the link is used.\n",
        ],
        'already' => [
            'subject' => 'You already have a :site account',
            'body' => "Hello,\n\nSomeone, we hope you, asked to create an account on :site with this email address, but it already has one.\n\nSign in here:\n:sign_in\n\nForgotten your password? Choose a new one here:\n:recover\n\nIf you didn't ask, you can ignore this email. Nothing has changed.\n",
        ],
        'reset' => [
            'subject' => 'Choose a new password for :site',
            'body' => "Hello,\n\nSomeone, we hope you, asked to choose a new password for your :site account.\n\nTo choose one, open this link. It works once, for :minutes minutes:\n\n:link\n\nIf you didn't ask, you can ignore this email. Your password stays as it is.\n",
        ],
        'none' => [
            'subject' => 'No :site account uses this address',
            'body' => "Hello,\n\nSomeone, we hope you, asked to choose a new password for a :site account with this email address, but no account there uses it.\n\nMaybe you signed up with a different address?\n\nIf you didn't ask, you can ignore this email. Nothing has changed.\n",
        ],
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
        'email' => 'Enter an email address, like name@example.com.',
    ],
    'throttle' => [
        'ip' => 'Too many attempts from your connection. Please wait a minute, then try again. Nothing was checked or sent.',
        // Counted: the whole hours still to wait.
        'ip_day' => '{1} Too many requests from your connection today. Please try again in an hour. Nothing was sent.|[2,*] Too many requests from your connection today. Please try again in :count hours. Nothing was sent.',
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
