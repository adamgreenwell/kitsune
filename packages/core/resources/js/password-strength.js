/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
(() => {
    'use strict';
    const feedback = document.querySelector('[data-password-feedback]');
    const password = document.getElementById('password');
    const account = document.getElementById('username');
    if (!feedback || !password || !account || typeof TextEncoder === 'undefined') return;
    const visual = document.getElementById('password-strength');
    const announcement = document.getElementById('password-strength-announcement');
    const minimum = Number(feedback.dataset.min);
    const maximum = Number(feedback.dataset.maxBytes);
    let timer;
    let announced = '';

    // Advisory only. No password leaves this call, including into a timer or an HTML attribute.
    function estimate() {
        const value = password.value;
        if (!value) return 'empty';
        // Bound work before allocating a code-point array or UTF-8 buffer for an unusually large paste.
        if (value.length > maximum) return 'long';
        const length = Array.from(value).length;
        if (new TextEncoder().encode(value).length > maximum) return 'long';
        if (/[\u0000-\u001f\u007f]/u.test(value)) return 'control';
        if (/^[\s\p{Z}\p{Cf}]|[\s\p{Z}\p{Cf}]$/u.test(value)) return 'edges';
        if (length < minimum) return 'short';
        if (value.toLowerCase() === account.value.toLowerCase()) return 'email';
        // A small warning list, not a breach list or an entropy calculation. Never add composition requirements.
        const compact = value.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');
        if (/^(.{1,4})\1+$/u.test(value) || /password|qwerty|letmein|123456|abcdef/u.test(compact)) return 'weak';
        return length >= Math.min(64, minimum + 8) ? 'stronger' : 'fair';
    }

    function announce(state) {
        const message = state === 'empty' ? '' : feedback.dataset[state];
        if (message !== announced) {
            announcement.textContent = message;
            announced = message;
        }
    }

    function update(immediate = false) {
        clearTimeout(timer);
        const state = estimate();
        visual.textContent = feedback.dataset[state];
        if (immediate) announce(state);
        else timer = setTimeout(() => announce(state), 1200);
    }

    password.addEventListener('input', () => update());
    password.addEventListener('change', () => update());
    password.addEventListener('blur', () => update(true));
    window.addEventListener('pageshow', () => update());
    update();
    feedback.hidden = false;
})();
