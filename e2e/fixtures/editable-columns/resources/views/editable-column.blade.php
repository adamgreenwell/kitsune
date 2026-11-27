{{--
    This Source Code Form is subject to the terms of the Mozilla Public
    License, v. 2.0. If a copy of the MPL was not distributed with this
    file, You can obtain one at https://mozilla.org/MPL/2.0/.
--}}
<section>
    <div id="editable-cell" x-data="editableColumn(@js([
        'name' => $column,
        'state' => $savedValue,
        'recordKey' => '1',
        'isNative' => $column !== 'custom-select',
        'accessibilityAttributes' => [],
        'ariaLabel' => 'Fixture selection',
        'initialOptionLabel' => ucfirst((string) $savedValue),
        'optionsLimit' => 50,
        'position' => 'bottom',
        'searchableOptionFields' => ['label'],
        'options' => [['value' => 'before', 'label' => 'Before'], ['value' => 'after', 'label' => 'After']],
        'messages' => ['saving' => 'Saving', 'saved' => 'Saved', 'failure' => 'Save failed'],
    ]))">
        <input type="hidden" x-ref="serverState" data-option-label="{{ ucfirst((string) $savedValue) }}"
            data-disabled="false" value="{{ is_bool($savedValue) ? (int) $savedValue : $savedValue }}">
        @if ($column === 'custom-select')
            <div x-ref="select"></div>
        @endif
    </div>
</section>
