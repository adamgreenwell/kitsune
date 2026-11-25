/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

const root = new URL('../../', import.meta.url);
const sourceMap = JSON.parse(readFileSync(new URL('./source-map.json', import.meta.url), 'utf8'));

function sha256(bytes) {
    return createHash('sha256').update(bytes).digest('hex');
}

export function parityDump(suite, corpus, rows, settings = {}) {
    const sources = Object.fromEntries(
        [...sourceMap.common, ...sourceMap[suite]].map((path) => [path, sha256(readFileSync(new URL(path, root)))]),
    );

    return {
        format: 'kitsune-parity-v1',
        suite,
        corpus_sha256: sha256(corpus),
        sources,
        engine: {
            name: 'v8',
            node: process.versions.node,
            version: process.versions.v8,
            unicode: process.versions.unicode,
            ...settings,
        },
        rows,
    };
}
