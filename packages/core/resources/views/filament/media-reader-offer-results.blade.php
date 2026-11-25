{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
@php
    use Filament\Notifications\View\Components\NotificationComponent;
    use Filament\Notifications\View\Components\NotificationComponent\IconComponent;
    use Filament\Notifications\View\NotificationsIconAlias;
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\ComponentAttributeBag;
    use function Filament\Support\generate_icon_html;

    $closeLabel = __('filament-notifications::notification.actions.close.label');
    $notificationData = ['id' => $getId(), 'duration' => $getDuration()];
@endphp
<div
    {{ $attributes->color(NotificationComponent::class, $getColor() ?? 'gray')->class(['fi-no-notification', 'fi-inline' => $isInline(), 'fi-status-'.$getStatus()]) }}
    wire:key="{{ $getId() }}.notifications.{{ $getId() }}"
    x-data="notificationComponent({ notification: @js($notificationData) })"
    x-transition:enter-start="fi-transition-enter-start"
    x-transition:enter-end="fi-transition-enter-end"
    x-transition:leave-start="fi-transition-leave-start"
    x-transition:leave-end="fi-transition-leave-end"
    x-on:close-notification.window="if ($event.detail.id === @js($getId())) close()"
    @unless ($isInline())
        {{-- Filament 5.6 uses close(); newer versions also manage focus and dismissal. --}}
        x-on:focusin="if (typeof handleFocusIn === 'function') handleFocusIn($event)"
        x-on:focusout="if (typeof handleFocusOut === 'function') handleFocusOut($event)"
        x-on:keydown.escape="
            if (typeof handleEscape === 'function') {
                handleEscape($event)
            } else {
                $event.preventDefault()
                $event.stopImmediatePropagation()
                close()
            }
        "
    @endunless
>
    {{ generate_icon_html($getIcon(), attributes: (new ComponentAttributeBag)->color(IconComponent::class, $getIconColor())->class(['fi-no-notification-icon']), size: $getIconSize()) }}

    <div class="fi-no-notification-main">
        <div class="fi-no-notification-text">
            <h3 class="fi-no-notification-title">{{ $getTitle() }}</h3>
            <div class="fi-no-notification-body">
                <p>{{ $getBody() }}</p>
                <div role="region" aria-label="{{ __('kitsune::media.readers.bulk.per_file_results') }}" tabindex="0"
                     style="max-height:40vh;overflow:auto;overscroll-behavior:contain;outline-offset:-2px">
                    <ul>
                        @foreach (is_array($offerResults ?? null) ? $offerResults : [] as $line)
                            @if (is_string($line))
                                <li>{{ $line }}</li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <button type="button"
            x-on:click="{{ $isInline() ? 'close()' : "typeof dismiss === 'function' ? dismiss() : close()" }}"
            aria-label="{{ $closeLabel }}" title="{{ $closeLabel }}"
            class="fi-icon-btn fi-no-notification-close-btn">
        {{ generate_icon_html(Heroicon::XMark, alias: NotificationsIconAlias::NOTIFICATION_CLOSE_BUTTON) }}
    </button>
</div>
