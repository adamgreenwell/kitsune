// @ts-check
const { expect } = require('@playwright/test');

/**
 * Filament 5.10.1 gives the opening button a combobox role. In 5.10.2 a searchable
 * select keeps a native button and moves combobox to the search input inside its
 * closed dropdown. Keep the field's accessible name and the listbox trigger shape.
 */
function selectTrigger(scope, name = null) {
    const options = name === null ? {} : { name };
    return scope.locator('button[aria-haspopup="listbox"]').and(
        scope.getByRole('button', options).or(scope.getByRole('combobox', options)),
    );
}

/** Multi-value badges are field content; newer Filament places them beside the opening button. */
function selectField(scope, name = null) {
    return selectTrigger(scope, name).locator('xpath=ancestor::*[@data-field-wrapper][1]');
}

/** Displayed values only: hidden dropdown candidates and helper text cannot satisfy a hydration assertion. */
function selectValue(scope, name = null) {
    return selectField(scope, name).locator('.fi-select-input-value-ctn');
}

/** Open the named field and return its visible, labelled search input, never the global search. */
async function openSelect(scope, name = null) {
    const field = selectField(scope, name).first();
    const trigger = selectTrigger(scope, name).first();
    const legacyCombobox = (await trigger.getAttribute('role')) === 'combobox';
    await expect(trigger).toHaveAccessibleName(/\S/);
    await trigger.click();
    const search = field.locator('.fi-select-input-search-ctn input').and(
        field.getByRole(legacyCombobox ? 'textbox' : 'combobox'),
    );
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(search).toBeVisible();
    await expect(search).toHaveAccessibleName(/\S/);
    if (! legacyCombobox) {
        await expect(search).toHaveAttribute('aria-autocomplete', 'list');
    }
    await expect(search).toBeFocused();
    return search;
}

/** Match the requested Livewire action, excluding background notification/initialization responses. */
function callsMethod(response, method, componentMethod = null) {
    if (! response.url().includes('/livewire') || response.request().method() !== 'POST') {
        return false;
    }
    try {
        const data = response.request().postDataJSON();
        return Array.isArray(data?.components) && data.components.some((component) =>
            Array.isArray(component.calls) && component.calls.some((call) => call.method === method
                && (componentMethod === null || call.params?.[1] === componentMethod)),
        );
    } catch {
        return false;
    }
}

module.exports = { selectTrigger, selectField, selectValue, openSelect, callsMethod };
