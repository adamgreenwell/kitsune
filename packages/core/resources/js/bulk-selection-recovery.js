/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

document.addEventListener('alpine:init', () => {
    const wrapped = new WeakSet();

    Alpine.directive('kitsune-bulk-selection', (_element, _directive, { evaluate }) => {
        const table = evaluate('$data');
        const selectAll = table.selectAllRecords;
        if (typeof selectAll !== 'function' || wrapped.has(selectAll)) return;

        const guarded = async function (...args) {
            if (this.isLoading) return;

            // Filament takes the busy flag synchronously on the path that requests record keys.
            const selection = selectAll.apply(this, args);
            const requestedKeys = this.isLoading;
            try {
                return await selection;
            } finally {
                if (requestedKeys) this.isLoading = false;
            }
        };

        wrapped.add(guarded);
        table.selectAllRecords = guarded;
    });
});
