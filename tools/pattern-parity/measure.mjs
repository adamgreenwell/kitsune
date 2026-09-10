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
//
// ⚠️ EVERY CASE RUNS IN A WORKER WITH A DEADLINE, and that is not defensive style — the harness
// could not reproduce its own published results without it. `backtrack-limit-email-shape` is a
// catastrophically backtracking pattern, and `RegExp.prototype.test` is SYNCHRONOUS: it pins the
// event loop with no way to interrupt it, so a single adversarial case prevented the tool from
// emitting any JSON at all. Found by review of the commit that added the tool.
//
// A timeout is recorded as a THIRD OUTCOME rather than as a failure, which also makes the two
// engines symmetric: PCRE reports `matches: null` when it exhausts its backtrack limit, and this
// reports `matches: null` with `timedOut: true` when it cannot answer inside the deadline. Both
// mean "the engine gave no verdict", and collapsing either into "no match" hides the finding.
import { readFileSync } from 'node:fs';
import { Worker } from 'node:worker_threads';

const DEADLINE_MS = Number(process.env.PARITY_DEADLINE_MS ?? 2000);

const path = process.argv[2] ?? new URL('./cases.json', import.meta.url).pathname;
const cases = JSON.parse(readFileSync(path, 'utf8'));

// The worker compiles and tests one case, then exits. Kept inline so the tool stays two files.
const WORKER = `
import { parentPort, workerData } from 'node:worker_threads';
let compiles = true;
let re;
try {
    re = new RegExp(workerData.pattern, 'u');
} catch {
    compiles = false;
}
parentPort.postMessage({ compiles, matches: compiles ? re.test(workerData.subject) : null });
`;

async function measure(testCase) {
    return new Promise((resolve) => {
        const worker = new Worker(WORKER, {
            eval: true,
            workerData: { pattern: testCase.pattern, subject: testCase.subject },
        });

        const timer = setTimeout(() => {
            // ⚠️ `terminate()` is what makes the deadline real. Without it the worker keeps
            // burning a core after the parent has moved on.
            void worker.terminate();
            resolve({ compiles: true, matches: null, timedOut: true });
        }, DEADLINE_MS);

        worker.once('message', (result) => {
            clearTimeout(timer);
            void worker.terminate();
            resolve(result);
        });

        worker.once('error', () => {
            clearTimeout(timer);
            resolve({ compiles: false, matches: null });
        });
    });
}

const out = {};

for (const testCase of cases) {
    out[testCase.id] = await measure(testCase);
}

console.log(JSON.stringify(out, null, 2));
