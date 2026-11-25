/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

document.addEventListener('alpine:init', () => {
    Alpine.directive('kitsune-select-direction', (element, _directive, { cleanup }) => {
        const labels = '.fi-select-input-value-label:not([dir]), .fi-badge-label:not([dir])';
        const apply = () => {
            element.querySelectorAll(labels).forEach((label) => label.setAttribute('dir', 'auto'));
        };

        // Filament replaces the visible labels after the control is mounted and when selections change.
        const observer = new MutationObserver(apply);
        observer.observe(element, { childList: true, subtree: true });
        cleanup(() => observer.disconnect());
        apply();
    });
});
