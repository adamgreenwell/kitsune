/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/*
 * Keep `dir` on a rich text block while it is being edited — issue #67.
 *
 * `Entry::stampBlockDirections()` writes `dir="auto"` on every text-bearing block on the way into
 * storage, so the value is correct in the database and correct for anything that renders it. Filament's
 * editor is TipTap, which parses the stored HTML into its own document model and re-renders it, and
 * TipTap DROPS any attribute a node's schema does not declare. So the one place an author looks while
 * writing was the one place per-block direction was invisible.
 *
 * ⚠️ NO BUILD STEP, AND THAT IS MEASURED RATHER THAN HOPED. `filament/forms` sets
 * `window.FilamentRichEditor.tiptap = { core, pmState, pmView, pmModel }` on purpose — its own comment
 * says it is "so custom extensions loaded via RichContentPlugin::getTipTapJsExtensions() can share the
 * same ProseMirror instance (required for instanceof checks across bundles)" — and its loader takes a
 * module's `default`, calling it if it is a function. A hand-written ES module is therefore a valid
 * extension: no bundler, no dependency, no lockfile entry.
 *
 * ⚠️ THE NODE LIST IS NOT WRITTEN TWICE. It comes from `BlockDirectionPlugin::EDITOR_NODES`, which maps
 * the tags `Entry` stamps to the node names this editor uses, and `RichEditorDirectionAssetTest` fails
 * if this file and that map disagree. A JS list that drifts from the PHP one is the same defect one
 * layer out.
 *
 * ⚠️ AND IT DECLARES `dir` WITHOUT DEFAULTING IT, which is the whole of the difference between this and
 * the `textDirection` extension Filament already ships. That one keeps the attribute too — but only when
 * given a `direction` option, which then becomes the DEFAULT for every node type. A `dir="auto"` invented
 * on a `ul` is not harmless bookkeeping: a direction on a container is inherited by items that should each
 * resolve their own, which is the per-field failure this issue exists to undo, one level down. Declaring
 * with a null default keeps the two apart — what the author wrote round-trips, and what was never there
 * stays absent.
 */
export default () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core

    /** The three values `Entry` will store; anything else is somebody else's attribute. */
    const KEPT = ['ltr', 'rtl', 'auto']

    return Extension.create({
        name: 'kitsuneBlockDirection',

        addGlobalAttributes() {
            return [
                {
                    // ⚠️ IN THE ORDER `BlockDirectionPlugin::nodes()` DERIVES THEM, so the test can assert
                    // equality rather than set-equality — this list is a transcription of that one.
                    //
                    // ⚠️ THE LIST CONTAINERS ARE HERE TO PRESERVE, NOT TO SET. `Entry` never stamps `ul`
                    // or `ol`, but it KEEPS one an author wrote and then leaves the items unstamped
                    // because they inherit it. Declaring the attribute with a null default is what makes
                    // both facts survive the editor.
                    types: ['paragraph', 'listItem', 'heading', 'blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                    attributes: {
                        dir: {
                            /*
                             * ⚠️ NULL, AND `auto` HERE IS A MEASURED MISTAKE rather than an untried idea.
                             * A default of `auto` would give a block the author has just created its own
                             * direction while typing — which is a real gap, recorded in
                             * `docs/accessibility-inventory.md` — but it also puts `dir="auto"` on the
                             * paragraph INSIDE a list item, and `dir="auto"` resolves from an element's
                             * text EXCLUDING any descendant that has its own direction. Measured in the
                             * browser on the seeded list:
                             *
                             *   LI[auto]=ltr  wrapping  P[auto]=rtl
                             *
                             * The text flowed right-to-left and the item's own direction went
                             * left-to-right, which puts the bullet on the wrong side. Closing the
                             * authoring gap needs a handler that knows a block's parent, not a static
                             * default.
                             */
                            default: null,
                            /*
                             * ⚠️ NOT CARRIED ACROSS A SPLIT, and TipTap's default is the opposite —
                             * `keepOnSplit` defaults to true, and both `splitBlock` and `splitListItem`
                             * honour it. So pressing Enter at the end of a stored `<li dir="rtl">` gave
                             * the NEW item a copied `dir="rtl"`, which renders English text
                             * right-to-left and which `Entry` stores as an explicit choice rather than
                             * replacing with `auto`. A direction is a property of a block's content, so a
                             * block with no content yet has none to inherit.
                             */
                            keepOnSplit: false,
                            parseHTML: (element) => {
                                const dir = element.getAttribute('dir')

                                return KEPT.includes(dir) ? dir : null
                            },
                            renderHTML: (attributes) => (attributes.dir ? { dir: attributes.dir } : {}),
                        },
                    },
                },
            ]
        },
    })
}
