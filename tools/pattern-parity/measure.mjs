/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

// The CONSUMER side. A JSON Schema `pattern` is ECMA-262, and an API client compiles the
// pattern Kitsune published — the raw authored source, with no rewriting and no `D`.
//
// ⚠️ The `u` flag is deliberate. Annex B makes the unflagged dialect MORE permissive, so a
// pattern that only compiles without `u` is one that breaks for any consumer who sets it.
import { readFileSync } from 'node:fs';

const path = process.argv[2] ?? new URL('./cases.json', import.meta.url).pathname;
const cases = JSON.parse(readFileSync(path, 'utf8'));
const out = {};

for (const c of cases) {
    let compiles = true;
    let re;

    try {
        re = new RegExp(c.pattern, 'u');
    } catch {
        compiles = false;
    }

    out[c.id] = { compiles, matches: compiles ? re.test(c.subject) : null };
}

console.log(JSON.stringify(out, null, 2));
