{{--
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@php($admission = $this->liveAdmission())
<x-filament::section :heading="__('commerce::admission.title')">
    <div style="display:grid;gap:1rem" data-live-admission>
        <p>{{ __('commerce::admission.help') }}</p>
        <p role="status">{{ __('commerce::admission.'.$admission['state']) }}</p>
        @if ($admission['account'] !== null)<p>{{ __('commerce::admission.account') }}: <span dir="ltr">{{ $admission['account'] }}</span></p>@endif
        <p>{{ __('commerce::admission.secrets') }}</p>
        @if ($admission['state'] !== 'unavailable')
            <form action="{{ \Kitsune\Commerce\Filament\OrderResource::getUrl('live-admission') }}" method="post" autocomplete="off" style="display:grid;gap:1rem">
                @csrf
                <input type="hidden" name="version" value="{{ $admission['version'] }}">
                <div><label for="live-admission-password" style="display:block;margin-bottom:.375rem">{{ __('commerce::admission.password') }}</label>
                    <x-filament::input.wrapper><x-filament::input id="live-admission-password" name="current_password" type="password" required maxlength="4096" autocomplete="current-password" /></x-filament::input.wrapper></div>
                <div style="display:flex;flex-wrap:wrap;gap:1rem">
                    <x-filament::button type="submit" name="action" value="admit">{{ __('commerce::admission.admit') }}</x-filament::button>
                    <x-filament::button type="submit" name="action" value="revoke" color="warning">{{ __('commerce::admission.revoke') }}</x-filament::button>
                </div>
            </form>
        @endif
    </div>
</x-filament::section>
