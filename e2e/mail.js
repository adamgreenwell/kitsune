// @ts-check
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

/*
 * A reader's mail, read from where the floor puts it: `storage/logs/laravel.log`, written by the `log` mailer once the
 * response has gone (ADR-037's second part, as built). What a developer does by hand, the specs do here.
 *
 * ⚠️ IT REFUSES TO GUESS. The application is asked for its mailer, its environment, its log file and its `APP_URL`, and
 * anything but `log` in `local` stops the spec: a test that read an empty log would only time out, saying nothing.
 *
 * ⚠️ THE LINK'S ORIGIN IS THE APPLICATION'S OWN `APP_URL`, NEVER THE BROWSER'S `baseURL`. A host-less site mails links
 * from `APP_URL` in development; the spec checks that, then opens the link's path on the server it is talking to.
 */

const SKELETON = path.join(__dirname, '..', 'skeleton');

/** @type {{ mailer: string, env: string, log: string, url: string } | null} */
let facts = null;

function mailFacts() {
    if (facts !== null) {
        return facts;
    }

    const printed = execFileSync('php', ['artisan', 'tinker', '--execute', [
        '$stack = config(\'logging.default\') === \'stack\' ? config(\'logging.channels.stack.channels\') : [config(\'logging.default\')];',
        'echo json_encode([',
        '\'mailer\' => Kitsune\\Core\\Readers\\ReaderMail::mailerName(),',
        '\'env\' => app()->environment(),',
        '\'log\' => $stack === [\'single\'] ? config(\'logging.channels.single.path\') : null,',
        '\'url\' => config(\'app.url\'),',
        ']);',
    ].join(' ')], { cwd: SKELETON, encoding: 'utf8' }).trim();

    const read = JSON.parse(printed.split('\n').pop() || '{}');

    if (read.mailer !== 'log' || read.env !== 'local' || typeof read.log !== 'string') {
        throw new Error(`e2e/mail.js reads reader mail from the log mailer in local, into one log file; this application has ${printed}`);
    }

    facts = read;

    return read;
}

/** Where the log ends now: a spec reads only what is written after it. */
function mailMark() {
    const { log } = mailFacts();

    return fs.existsSync(log) ? fs.statSync(log).size : 0;
}

/**
 * The last mail to this address written after the mark, as the log holds it — headers and plain text. Polled: the mail
 * is sent after the response, so it may land a moment after the page does.
 */
async function mailTo(address, mark, timeout = 15_000) {
    const { log } = mailFacts();
    const to = new RegExp(`^To: ${address.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\r?$`, 'm');
    const deadline = Date.now() + timeout;

    while (Date.now() < deadline) {
        const written = fs.existsSync(log) ? fs.readFileSync(log).subarray(mark).toString('utf8') : '';
        const records = written.split(/(?=^\[\d{4}-\d{2}-\d{2}[ T][^\]]*\] \w+\.[A-Z]+:)/m).filter((record) => to.test(record));

        if (records.length > 0) {
            return records[records.length - 1];
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
    }

    throw new Error(`No mail to ${address} reached ${log} within ${timeout} ms.`);
}

/** The mail's subject line. */
function mailSubject(record) {
    return (record.match(/^Subject: (.*?)\r?$/m) || [])[1] || null;
}

/** The last reader link in a mail, as the path and query to open on this server — or null when it carries none. */
function mailLink(record) {
    const found = [...record.matchAll(/https?:\/\/\S+\/account\/(?:register\/complete|reset)\?token=[A-Za-z0-9]{43}(?=\s)/g)];

    if (found.length === 0) {
        return null;
    }

    const link = new URL(found[found.length - 1][0]);
    const own = new URL(mailFacts().url);

    if (link.origin !== own.origin) {
        throw new Error(`A reader link names ${link.origin}, and this application's APP_URL is ${own.origin}.`);
    }

    return link.pathname + link.search;
}

module.exports = { mailMark, mailTo, mailSubject, mailLink, mailFacts };
