/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

// Before Filament 5.10.2, a pending result can remain current until the next debounce fires.
document.addEventListener('input', ({ target }) => {
    if (!(target instanceof HTMLInputElement)) return;

    const owner = target.closest('[x-load-src][x-data]');
    const select = owner && window.Alpine?.$data(owner).select;

    if (select?.searchInput !== target || !select.isOpen || select.isDisabled || !select.hasDynamicSearchResults) return;

    // Invalidate the old request before Filament handles this input at the target.
    select.activeSearchId++;
    select.isSearching = target.value.trim() !== '';
    if (select.isSearching) {
        select.showLoadingState(true);
    } else {
        select.hideLoadingState();
    }
}, { capture: true });
