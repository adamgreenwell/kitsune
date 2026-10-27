'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { execFileSync, spawn } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
fs.mkdirSync(path.join(root, 'build'), { recursive: true });
const owned = fs.mkdtempSync(path.join(root, 'build/owned-documentation-browser-'));
const output = path.join(owned, 'artifact');
const php = process.env.PHP_BINARY || 'php';
let child;
let stopping = false;

function cleanup() {
    if (path.dirname(owned) !== path.join(root, 'build') || !path.basename(owned).startsWith('owned-documentation-browser-')) {
        throw new Error('refusing unowned documentation test cleanup');
    }
    fs.rmSync(owned, { recursive: true });
}

try {
    for (const args of [
        ['--version=reference-head', '--ref=HEAD'],
        ['--version=development'],
    ]) {
        execFileSync(php, ['bin/docs-build.php', `--output=${output}`, ...args], { cwd: root, stdio: 'pipe' });
    }
    child = spawn(php, ['-S', '127.0.0.1:8346', '-t', output], { cwd: root, stdio: ['ignore', 'pipe', 'pipe'] });
    child.stdout.pipe(process.stdout);
    child.stderr.pipe(process.stderr);
    child.on('error', error => {
        process.stderr.write(`${error.message}\n`);
        cleanup();
        process.exit(1);
    });
    child.on('exit', code => {
        cleanup();
        process.exit(stopping ? 0 : (code || 1));
    });
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => {
            stopping = true;
            // The direct child belongs to this harness. Never adopt a saved PID or reuse a listener.
            child.kill('SIGTERM');
        });
    }
} catch (error) {
    cleanup();
    throw error;
}
