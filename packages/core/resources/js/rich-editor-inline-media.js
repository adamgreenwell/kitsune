/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

export default () => window.FilamentRichEditor.tiptap.core.Node.create({
    name: 'kitsuneInlineMedia',
    inline: true,
    group: 'inline',
    atom: true,
    addAttributes() {
        return {
            mediaId: {
                default: null,
                parseHTML: element => element.getAttribute('data-kitsune-media'),
                renderHTML: attributes => ({ 'data-kitsune-media': attributes.mediaId }),
            },
            alt: { default: '' },
        };
    },
    addNodeView() {
        return ({ editor, node }) => {
            const image = document.createElement('img');
            image.alt = node.attrs.alt;
            image.dataset.kitsuneMedia = node.attrs.mediaId;
            const field = editor.options.element.closest('[data-kitsune-inline-preview]');
            if (field) {
                // The attribute holds base64 JSON so Blade and Livewire preserve its quotes.
                const urls = JSON.parse(atob(field.dataset.kitsuneInlinePreview));
                if (Object.hasOwn(urls, node.attrs.mediaId)) {
                    image.src = urls[node.attrs.mediaId];
                }
            }

            return { dom: image };
        };
    },
    parseHTML() {
        return [{ tag: 'img[data-kitsune-media]' }];
    },
    renderHTML({ HTMLAttributes }) {
        return ['img', HTMLAttributes];
    },
});
