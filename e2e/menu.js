// @ts-check

/*
 * An item of an open Filament dropdown — a list's *Bulk actions* — by its exact words.
 *
 * ⚠️ BY EITHER ROLE, BECAUSE FILAMENT CHANGED IT. From 5.10.1 every dropdown item is rendered `role="menuitem"` (the ARIA
 * menu pattern; `CanGenerateDropdownItemHtml` and `dropdown/list/item.blade.php`), where 5.9 left it the `<button>` it
 * is, and `packages/core` admits both. On either version exactly one of the two matches, so strict mode holds; and a
 * disabled item reads as disabled on both — `disabled` before, `aria-disabled` after. A check that an item is NOT offered
 * has to ask through this too: asked for a `button` on 5.10.1 it passes whatever the menu holds.
 *
 * Drop the button half once the floor is ^5.10.1.
 */
const menuItem = (page, name) => page.getByRole('menuitem', { name, exact: true }).or(page.getByRole('button', { name, exact: true }));

module.exports = { menuItem };
