{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.

    Textual, optional feedback. Hidden until its hash-pinned script starts; the requirements remain without scripts.
    The changing visual text is not in aria-describedby, so it does not announce each keystroke. Only a changed
    category is announced after a pause or on blur. No password is rendered into these attributes.
--}}
<div data-password-feedback hidden data-min="{{ $min }}" data-max-bytes="{{ \Kitsune\Core\Auth\PasswordRules::MAX_BYTES }}"
    @foreach (['empty', 'short', 'long', 'control', 'edges', 'email', 'weak', 'fair', 'stronger'] as $state)
        data-{{ $state }}="{{ $t('password.strength.'.$state, ['min' => $min, 'max' => \Kitsune\Core\Auth\PasswordRules::MAX_BYTES]) }}"
    @endforeach>
    <p id="password-strength" class="hint"></p>
    <p class="hint">{{ $t('password.strength.limits') }}</p>
    <p id="password-strength-announcement" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></p>
</div>
