// Each name's membership under ECMAScript, as ranges. The mirror of measure.php, skipping surrogates
// for the same reason and hashing the same shape so the two dumps are comparable by identity.
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const names = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const out = {};

for (const name of names) {
    let expression;

    try {
        expression = new RegExp('^\\p{' + name + '}$', 'u');
    } catch {
        out[name] = { error: 'does not compile' };
        continue;
    }

    const ranges = [];
    let start = null;
    let count = 0;

    for (let codepoint = 0; codepoint <= 0x10FFFF; codepoint++) {
        if (codepoint === 0xD800) {
            codepoint = 0xDFFF;
            continue;
        }

        if (expression.test(String.fromCodePoint(codepoint))) {
            count++;
            if (start === null) start = codepoint;
        } else if (start !== null) {
            ranges.push([start, codepoint - 1]);
            start = null;
        }
    }

    if (start !== null) ranges.push([start, 0x10FFFF]);

    out[name] = { count, sha: createHash('sha1').update(JSON.stringify(ranges)).digest('hex'), ranges };
}

process.stdout.write(JSON.stringify(out) + '\n');
