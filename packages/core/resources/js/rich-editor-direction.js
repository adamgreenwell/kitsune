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
 * ⚠️ AND IT DECLARES `dir` WITHOUT DEFAULTING IT. Filament ships a `textDirection` extension that would
 * also keep the attribute — but only when given a `direction` option, which then becomes the DEFAULT for
 * every node type including the containers `Entry` deliberately leaves undirected. `dir="auto"` on a
 * `ul` is not harmless bookkeeping: the stored shape is asserted, and a container that carries a
 * direction is the per-field failure this issue exists to undo, one level down. So this declares the
 * attribute and invents nothing: what the author wrote round-trips, and what was never there stays
 * absent.
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
                    // equality rather than set-equality — this list is a transcription of that one, and a
                    // transcription that has been reordered is one nobody can check at a glance.
                    types: ['paragraph', 'listItem', 'heading', 'blockquote', 'codeBlock'],
                    attributes: {
                        dir: {
                            default: null,
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
