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
 * ⚠️ AND THAT OBJECT IS WHY CORE REQUIRES `filament/filament ^5.6`. `filament/forms` began exposing
 * `window.FilamentRichEditor.tiptap` in 5.6.0. On 5.4.0–5.5.2 the destructuring below throws, Filament's
 * loader logs "Failed to load rich editor custom extension" and carries on, and every rich text field
 * silently loses per-block `dir` while editing — issue #67 reopened, with no error on the server. A
 * dependency floor below the release that ships the contract this file relies on installs a broken editor.
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

    /*
     * ⚠️ THE NODE TYPES A NEW BLOCK MAY BE GIVEN `auto` ON, which is NOT the list the attribute is declared
     * on: the two list containers are absent, because a direction on a list is inherited by every item and
     * each item must resolve its own. It is a transcription of `BlockDirectionPlugin::automaticNodes()`,
     * and `RichEditorDirectionAssetTest` fails if the two disagree.
     *
     * ⚠️ `listItem` IS HERE AND USED NOT TO BE, which is the correction #77 carries. The item is the block
     * that renders the marker and the indent, so the item is what has to resolve — and `dir="auto"` reads
     * an element's text EXCLUDING descendants that carry their own direction, so a direction on the
     * paragraph inside takes that text out of the item's reach. The earlier round had the right measurement
     * and drew the wrong conclusion from it: it left BOTH undirected while authoring, so a new bullet
     * showed the chrome's direction, and the save then stored `<li dir="auto"><p dir="auto">` anyway. The
     * rule below is the one that holds in both languages — the item takes it, the first block inside
     * yields.
     */
    const AUTOMATIC = ['paragraph', 'listItem', 'heading', 'blockquote', 'codeBlock']

    /**
     * Non-whitespace text this node holds that no block inside it has taken — `Entry::carriesLooseText()`.
     *
     * Block children are skipped rather than descended into, because their text is theirs: each resolves
     * its own direction. Everything else is descended into, since a mark or an inline node carries no
     * direction and its text belongs to the nearest block.
     */
    const carriesLooseText = (node) => {
        let found = false

        node.forEach((child) => {
            if (found) {
                return
            }

            found = child.isText
                ? (child.text ?? '').trim() !== ''
                : !AUTOMATIC.includes(child.type.name) && carriesLooseText(child)
        })

        return found
    }

    /**
     * Is this the first block inside a block that has nothing else to read? — `Entry::yieldsToOuterBlock()`.
     *
     * The outer block takes the direction and this one yields, so the outer block resolves from this
     * node's text. The two languages must agree on this or a save changes what the author was shown.
     *
     * ⚠️ ONLY WHEN THE BLOCK AROUND IT HAS NOTHING ELSE TO READ. A block yields so an ancestor with no
     * text of its own can reach some; an ancestor that already has text needs no donation, and taking one
     * changes which run decides its direction.
     */
    const yieldsToOuterBlock = (parent, index) => {
        if (!parent || !AUTOMATIC.includes(parent.type.name) || carriesLooseText(parent)) {
            return false
        }

        for (let i = 0; i < parent.childCount; i++) {
            if (AUTOMATIC.includes(parent.child(i).type.name)) {
                return i === index
            }
        }

        return false
    }

    /**
     * Does a child take its own direction, so its text is out of this node's reach?
     *
     * ⚠️ A YIELDING BLOCK'S `auto` DOES NOT COUNT, and reading it as if it did cost the item its direction.
     * Everything here happens inside ONE transaction: when a list toggle wraps the paragraph the author was
     * typing in, the new item is judged while that paragraph still carries the `auto` the same transaction
     * is about to take off it — so the item looked like it had nothing to read, and got nothing. Measured:
     * `UL[-] LI[-] P[-]`, where the item should have carried it.
     *
     * ⚠️ A FIXED DIRECTION STILL COUNTS, because that one is never removed. `ltr` and `rtl` are decisions,
     * and a block under one is genuinely out of reach.
     */
    const takesItsOwn = (parent, child, index) => {
        const dir = child.attrs.dir

        if (dir === 'ltr' || dir === 'rtl') {
            return true
        }

        if (yieldsToOuterBlock(parent, index)) {
            return false
        }

        return Boolean(dir) || AUTOMATIC.includes(child.type.name)
    }

    /** Is there any text here that nothing inside has taken? — `Entry::availableText()`. */
    const availableText = (node) => {
        let found = false

        node.forEach((child, offset, index) => {
            if (found) {
                return
            }

            if (child.isText) {
                found = (child.text ?? '').trim() !== ''

                return
            }

            found = !takesItsOwn(node, child, index) && availableText(child)
        })

        return found
    }

    /** Is there a block inside this one that will carry a direction of its own? — `Entry`'s twin. */
    const holdsASpokenForBlock = (node) => {
        let found = false

        node.forEach((child, offset, index) => {
            if (found || child.isText) {
                return
            }

            found = takesItsOwn(node, child, index) || holdsASpokenForBlock(child)
        })

        return found
    }

    /**
     * Would `auto` here read no text at all, because everything inside has taken its own?
     *
     * ⚠️ AN EMPTY BLOCK IS NOT THIS CASE, and the distinction is the whole of issue #76. A paragraph the
     * author has just created holds no text either — but nothing inside it has taken any, so `auto` on it
     * is exactly what it needs.
     */
    const resolvesFromNothing = (node) => !availableText(node) && holdsASpokenForBlock(node)

    /** Does an ancestor STATE a direction, rather than asking for one to be resolved? */
    const hasFixedAncestor = (doc, pos) => {
        const $pos = doc.resolve(pos)

        for (let depth = $pos.depth; depth > 0; depth--) {
            const dir = $pos.node(depth).attrs.dir

            if (dir === 'ltr' || dir === 'rtl') {
                return true
            }
        }

        return false
    }

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

        /*
         * ⚠️ THE HALF A STATIC DEFAULT CANNOT DO — issue #76. A block the author has just created has no
         * stored direction to preserve: `Entry` stamps `auto` on the way INTO storage, which is too late to
         * help while typing, so Arabic typed into a new paragraph rendered in the CHROME's direction until
         * the value was saved. That is the complaint #39 opens with, surviving in the one place #67 was
         * meant to fix it.
         *
         * A default of `auto` on the attribute closes it and breaks list rendering — see `AUTOMATIC` above.
         * A transaction can do what a default cannot: look at the block's PARENT, and leave a list item's
         * paragraph alone.
         *
         * ⚠️ IT ONLY EVER FILLS A GAP. A block that already carries a direction is untouched, including an
         * author's explicit `rtl`; a block inside a list item is untouched; and nothing is written when the
         * document has not changed. So this cannot override a choice — it supplies one where the editor
         * would otherwise have shown the chrome's.
         */
        addProseMirrorPlugins() {
            const { Plugin, PluginKey } = window.FilamentRichEditor.tiptap.pmState

            return [
                new Plugin({
                    key: new PluginKey('kitsuneBlockDirectionForNewBlocks'),

                    appendTransaction: (transactions, oldState, newState) => {
                        if (!transactions.some((transaction) => transaction.docChanged)) {
                            return null
                        }

                        const tr = newState.tr
                        let filled = false

                        newState.doc.descendants((node, pos, parent, index) => {
                            if (!AUTOMATIC.includes(node.type.name)) {
                                return
                            }

                            /*
                             * ⚠️ THE FIRST BLOCK INSIDE A BLOCK IS THE ONE TO LEAVE ALONE, and it is why
                             * this is a transaction: only a handler that can see a block's PARENT can tell
                             * a top-level paragraph from the one inside a list item, since they are the
                             * same node type. The item renders the marker, so the item is what resolves —
                             * and `auto` on the paragraph would take the item's text out of its reach.
                             */
                            const yielding = yieldsToOuterBlock(parent, index) || hasFixedAncestor(newState.doc, pos)

                            /*
                             * ⚠️ AND A GENERATED `auto` IS TAKEN BACK OFF, which the first round of this
                             * did not do and review found. Toggling a list wraps the paragraph the author
                             * was already typing in, attributes and all, so an `auto` written a keystroke
                             * earlier arrived inside a list item and stayed there — `<li><p dir="auto">`,
                             * the bullet on the wrong side, and the next save stored it. The same is true
                             * of an author declaring `rtl` on a blockquote around a paragraph this handler
                             * had already filled.
                             *
                             * ⚠️ ONLY `auto`, NEVER `ltr` OR `rtl`. Those are decisions, and this cannot
                             * tell an author's `auto` from its own — the same cost `Entry` states for the
                             * same reason, and paid the same way.
                             */
                            if (node.attrs.dir) {
                                if (node.attrs.dir === 'auto' && yielding) {
                                    tr.setNodeAttribute(pos, 'dir', null)
                                    filled = true
                                }

                                return
                            }

                            if (yielding || resolvesFromNothing(node)) {
                                return
                            }

                            tr.setNodeAttribute(pos, 'dir', 'auto')
                            filled = true
                        })

                        return filled ? tr : null
                    },
                }),
            ]
        },
    })
}
