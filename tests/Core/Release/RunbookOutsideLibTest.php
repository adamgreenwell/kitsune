<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * The one copy of the helpers the outside families share — deploy/runbook/outside/lib.php (issue #111).
 *
 * ⚠️ WHAT THIS FILE IS ACTUALLY FOR. CLAUDE.md's rule is one file, one source of truth, and this project
 * has already spent time reconciling exactly the drift that two copies produce. Every outside family needs
 * the same verdict protocol, the same bounded `run()`, the same way of driving an instrument and the same
 * reading of `nginx -T`; a second copy that diverged would make two families disagree about what a refusal
 * or a sentinel is, and the completeness gate would report the difference as a host problem.
 *
 * ⚠️ AND WHY ONE FAMILY CARRIES THE BYTES RATHER THAN REQUIRING THEM. RunbookTunnelLogTest mutates
 * tunnel-log.php's source and runs the copy from outside the runbook tree — from the fixture root, and
 * from a fixture runbook with no outside/lib.php beside it — which is how it proves that family's refusal
 * guards. A sibling `require` cannot resolve there, and that test is not this change's to edit. So
 * tunnel-log.php carries the block inline, throttle.php loads it, and the cases below make the two
 * impossible to drift apart: change a byte in either and this says which file has to follow.
 *
 * Needs only PHP, so it holds invariant 11: no services, no network, no Docker.
 */

const RUNBOOK_LIB_OPEN = '// --- the shared outside helpers ---';
const RUNBOOK_LIB_CLOSE = '// --- end of the shared outside helpers ---';

beforeEach(function (): void {
    $this->runbook = dirname(__DIR__, 3).'/deploy/runbook';
    $this->dir = realpath(sys_get_temp_dir()).'/kitsune-lib-'.bin2hex(random_bytes(6));

    File::makeDirectory($this->dir, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * The shared block a file carries, between its markers — or an empty string when it carries none.
 *
 * The markers are matched at the start of a line, so the sentence in the block that names them cannot be
 * mistaken for one.
 */
function runbookSharedBlock(string $source): string
{
    $open = strpos($source, "\n".RUNBOOK_LIB_OPEN);
    $close = strpos($source, "\n".RUNBOOK_LIB_CLOSE);

    if ($open === false || $close === false || $close < $open) {
        return '';
    }

    $end = strpos($source, "\n", $close + 1);

    return substr($source, $open + 1, ($end === false ? strlen($source) : $end) - $open - 1);
}

/**
 * Every outside script that is a family: all of them but the library itself.
 *
 * ⚠️ FOUND HERE RATHER THAN BORROWED FROM RunbookManifestTest, whose helpers exist only when that file is
 * the one being run — a case that passes in the full suite and fails on its own is worse than a second
 * two-line list. What matters is that this one is derived from the directory, so a family added to it is
 * held to the block without anybody remembering to add it here.
 *
 * @return array<string, string>
 */
function runbookOutsideFamilies(): array
{
    $families = [];

    foreach (glob(dirname(__DIR__, 3).'/deploy/runbook/outside/*.php') ?: [] as $script) {
        if (basename($script) !== 'lib.php') {
            $families[pathinfo($script, PATHINFO_FILENAME)] = $script;
        }
    }

    return $families;
}

/**
 * What the one shared reading makes of one dump, through a harness shaped like a family: it loads lib.php,
 * asks `nginx -T` through the stubbed ssh, and prints the answer.
 *
 * @return array{named: array<string, list<string>>, served: list<string>, rootless: list<string>, patterns: list<string>}
 */
function runbookSiteReading(string $dir, string $dump): array
{
    File::put($dir.'/dump', $dump);
    File::put($dir.'/ssh', "#!/bin/sh\ncat ".escapeshellarg($dir.'/dump')."\n");
    chmod($dir.'/ssh', 0755);

    $harness = $dir.'/reading.php';
    File::put($harness, <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once getenv('KITSUNE_LIB');

    const FAMILY = 'harness';
    const CHECKS = ['HRN-1'];

    [$dump, $unreadable] = nginxDump('forge@fixture');
    [$named, $patterns] = siteRoots($dump);
    [$served, $rootless] = servedSites($named);

    echo json_encode([
        'named' => $named,
        'served' => array_keys($served),
        'rootless' => array_values($rootless),
        'patterns' => $patterns,
        'unreadable' => $unreadable,
    ]);
    PHP);

    $run = new Process(['php', $harness], $dir, [
        'HOME' => (string) getenv('HOME'),
        'PATH' => $dir.':'.getenv('PATH'),
        'KITSUNE_LIB' => dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php',
    ]);
    $run->run();

    $read = json_decode($run->getOutput(), true);

    expect($read)->toBeArray($run->getOutput().$run->getErrorOutput());

    return $read;
}

it('is valid PHP', function (): void {
    foreach (['outside/lib.php', 'outside/throttle.php', 'host/throttle-store.php'] as $script) {
        $check = new Process(['php', '-l', dirname(__DIR__, 3).'/deploy/runbook/'.$script]);
        $check->run();

        expect($check->getExitCode())->toBe(0, $script.': '.$check->getOutput().$check->getErrorOutput());
    }
});

it('holds every family that carries the shared helpers byte-identical to the one copy', function (): void {
    /*
     * ⚠️ BYTE FOR BYTE, NOT "ROUGHLY THE SAME". The point of the block is that a family's idea of a
     * refusal, a sentinel and a bounded command is the runbook's idea of them. A copy that had drifted by a
     * word in a reason, or by a guard, would still look like a copy to any looser comparison — and the two
     * families would then disagree about the protocol run.sh judges them on.
     */
    $lib = File::get(dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php');
    $block = runbookSharedBlock($lib);

    expect($block)->not->toBe('', 'outside/lib.php carries no marked shared block')
        ->and($block)->toContain('function verdict(')
        ->and($block)->toContain('function sentinel(')
        ->and($block)->toContain('function run(')
        // What counts as a site is shared too: two families that answered that differently measured
        // different hosts through the same hostnames and reported the difference as the host's doing.
        ->and($block)->toContain('function siteRoots(')
        ->and($block)->toContain('function servedSites(');

    $loaders = [];
    $carriers = [];

    foreach (runbookOutsideFamilies() as $family => $script) {
        $source = File::get($script);
        $carried = runbookSharedBlock($source);

        if (str_contains($source, "require_once __DIR__.'/lib.php';")) {
            $loaders[] = $family;

            // A family that loads the library must not also carry a copy of it: that copy is the drift.
            expect($carried)->toBe('', "[{$family}] loads lib.php and also carries a copy of the shared block");

            continue;
        }

        $carriers[] = $family;

        expect($carried)->toBe($block, "[{$family}] carries a shared block that is not lib.php's, so the two have drifted");
    }

    // Neither list may be empty, or this passes by checking nothing.
    expect($loaders)->not->toBe([])
        ->and($carriers)->not->toBe([]);
});

it('cannot run a family that loads the shared helpers without them', function (): void {
    /*
     * ⚠️ THE LOAD IS REAL, NOT DECORATIVE. Without this, "throttle.php requires lib.php" is a line of source
     * nobody has ever seen matter: the family could carry its own copies of every helper and the require
     * would be dead. Run from a directory with no lib.php beside it, the family must not run at all.
     */
    File::copy(dirname(__DIR__, 3).'/deploy/runbook/outside/throttle.php', $this->dir.'/throttle.php');

    $run = new Process(
        ['php', $this->dir.'/throttle.php', '--host', 'forge@fixture', '--expect', 'tunnel'],
        $this->dir,
        ['HOME' => (string) getenv('HOME'), 'TMPDIR' => $this->dir],
    );
    $run->setTimeout(60);
    $run->run();

    expect($run->isSuccessful())->toBeFalse()
        ->and($run->getErrorOutput())->toContain('lib.php')
        ->and($run->getOutput())->not->toContain('VERDICT ')
        ->and($run->getOutput())->not->toContain('SENTINEL ');
});

it('gives both outside families one answer to which of a host\'s hostnames a run is about', function (): void {
    /*
     * ⚠️ server_name IS NOT A LIST OF HOSTNAMES, and both outside families request every name this returns.
     * `_` is the catch-all's own name; `*.x`, `.x` and `~^…$` are patterns. None of them resolves — real
     * curl answers `curl: (3) URL rejected: Bad hostname` — and a wildcard sorts before every letter, so
     * one wildcard vhost made the first request of every run a failure and voided the family while naming
     * the operator's own nginx as the reason. A wildcard subdomain is part of the planned alpha bring-up.
     *
     * The patterns come back rather than being dropped, so a family can say what it skipped instead of
     * reporting that a host naming a wildcard names no site at all — a true verdict with a false reason.
     *
     * ⚠️ AND A HOSTNAME THAT ROOTS NO APPLICATION IS THE SAME KIND OF ENTRY. A `www`→apex redirect vhost —
     * which Forge writes from its own UI, so the alpha host will have one — has no root ending in `/public`
     * in the block that would answer an https request for it. The two families used to meet it separately and
     * get it wrong in two different ways: the throttle family refused to name the release at all, and the
     * tunnel family failed it over the 301 it answers with. Split here, in the one copy, they cannot disagree
     * about which hostnames root an application; what to do about one that does not — sign in to it or not,
     * request it or not — is the caller's business, and the two answer that differently.
     *
     * The roots come back per hostname because a caller needs them — the throttle family names the release
     * from them — and a `root` inside a `location` is that location's wherever the server declares its own.
     */
    File::put($this->dir.'/dump', <<<'CONF'
    server {
        server_name _;
        root /var/www/html;
    }
    server {
        listen 80;
        server_name stage.kitsune.test alias.kitsune.test;
        return 301 https://$host$request_uri;
    }
    server {
        listen 443 ssl;
        server_name stage.kitsune.test alias.kitsune.test;
        root /home/kitsune/site/current/public;
        location /assets {
            root /var/www/shared-assets;
        }
    }
    server {
        server_name www.stage.kitsune.test;
        return 301 https://stage.kitsune.test$request_uri;
    }
    server {
        server_name *.kitsune.test .kitsune.test ~^(?<sub>.+)\.kitsune\.test$;
    }
    CONF);

    // `nginx -T` is whatever this answers with; the helper's own parsing is what is under test.
    File::put($this->dir.'/ssh', "#!/bin/sh\ncat ".escapeshellarg($this->dir.'/dump')."\n");
    chmod($this->dir.'/ssh', 0755);

    $harness = $this->dir.'/sites.php';
    File::put($harness, <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once getenv('KITSUNE_LIB');

    const FAMILY = 'harness';
    const CHECKS = ['HRN-1'];

    [$dump, $unreadable] = nginxDump('forge@fixture');
    [$named, $patterns] = siteRoots($dump);
    [$served, $rootless] = servedSites($named);

    echo 'NAMED '.implode(' ', array_keys($named))."\n";
    echo 'SERVED '.implode(' ', array_keys($served))."\n";
    echo 'ROOTS '.implode(' ', $served['stage.kitsune.test'] ?? [])."\n";
    echo 'ROOTLESS '.implode('; ', $rootless)."\n";
    echo 'PATTERNS '.implode(' ', $patterns)."\n";
    echo 'UNREADABLE ['.$unreadable."]\n";
    PHP);

    $run = new Process(['php', $harness], $this->dir, [
        'HOME' => (string) getenv('HOME'),
        'PATH' => $this->dir.':'.getenv('PATH'),
        'KITSUNE_LIB' => dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php',
    ]);
    $run->run();

    expect($run->getOutput())->toBe(implode("\n", [
        'NAMED alias.kitsune.test stage.kitsune.test www.stage.kitsune.test',
        'SERVED alias.kitsune.test stage.kitsune.test',
        'ROOTS /home/kitsune/site/current/public',
        'ROOTLESS [www.stage.kitsune.test] has no root ending in /public that an https request for it '
            .'would use (it would use none)',
        'PATTERNS *.kitsune.test .kitsune.test ~^(?<sub>.+)\.kitsune\.test$',
        'UNREADABLE []',
        '',
    ]), $run->getErrorOutput());
});

it('reads a directive whose value carries a brace, rather than dropping the whole directive', function (): void {
    /*
     * ⚠️ A DROPPED DIRECTIVE IS A HOSTNAME NOBODY EVER HEARS ABOUT. `{` and `}` were punctuation wherever they
     * appeared, so a value carrying one — `root /sites/${host}/public;`, which nginx accepts, or a quoted
     * regex with a `{1,3}` quantifier beside a literal name — put its words into a `{` token siteRoots() does
     * not read. The root vanished and the host was called rootless with "it declares none"; the literal
     * hostname vanished with no RECORD naming it, so it was never requested and the check passed on whatever
     * was left. Both shapes are one case here because one rule answers both: the punctuation is read where
     * nginx reads it, at the start of a word.
     *
     * The regex is quoted because nginx requires it — one carrying `}` or `;` unquoted is a configuration it
     * refuses to load, and so one `nginx -T` cannot dump — and it must still come back as a pattern, since a
     * request can no more be made to it than to a wildcard.
     */
    $read = runbookSiteReading($this->dir, <<<'CONF'
    server {
        listen 443 ssl;
        server_name real.test "~^www\d{1,3}\.kitsune\.test$";
        root /sites/${host}/public;
    }
    CONF);

    expect($read['named'])->toBe(['real.test' => ['/sites/${host}/public']])
        ->and($read['served'])->toBe(['real.test'])
        ->and($read['rootless'])->toBe([])
        ->and($read['patterns'])->toBe(['~^www\d{1,3}\.kitsune\.test$']);
});

it('reads a block whose brace abuts its own name', function (): void {
    // `server{` is the one shape with no whitespace before the brace, because `server` takes no argument —
    // and reading the brace only at the start of a word would have stopped seeing the block at all.
    $read = runbookSiteReading($this->dir, "server{\n listen 443 ssl;\n server_name a.test;\n root /srv/a/public;\n}\n");

    expect($read['served'])->toBe(['a.test']);
});

it('reads a block whose brace abuts an argument, and not only one that abuts a block name', function (): void {
    /*
     * ⚠️ A SWALLOWED `{` LEAVES ITS `}` TO CLOSE SOMETHING ELSE, AND THE DAMAGE LANDS ON A LATER HOST.
     * Allowing an abutting brace only after a block directive that takes no argument — `server{` — is close
     * to nginx but not nginx: `location /assets{` and `upstream app{` open blocks too. Read that way, this
     * dump's location never opens, its `}` closes the server instead, and `root` then lands outside every
     * server block, where it is read as the root they all inherit. The redirect vhost below inherits it, is
     * called an application, and TUN-1 asks it what answered — the exact verdict this branch exists to
     * remove, reintroduced through the tokenizer rather than through the rule.
     */
    $read = runbookSiteReading($this->dir, <<<'CONF'
    server {
        listen 443 ssl;
        server_name a.test;
        location /assets{
            alias /srv/assets;
        }
        root /srv/a/public;
    }
    server {
        listen 443 ssl;
        server_name redirect.test;
        return 301 https://a.test$request_uri;
    }
    CONF);

    expect($read['served'])->toBe(['a.test'])
        ->and($read['named'])->toBe(['a.test' => ['/srv/a/public'], 'redirect.test' => []])
        ->and($read['rootless'])->toBe(['[redirect.test] has no root ending in /public that an https request for it would use (it would use none)']);
});

it('takes a root the block inherits when it declares none of its own', function (string $dump): void {
    /*
     * ⚠️ nginx RESOLVES `root` FROM THE LOCATION, THEN THE SERVER, THEN http — so a site that declares it in
     * `location / { … }`, or once at the top for every server, is rooted exactly as one that declares it at
     * the server's own level. Reading only the server's own level called both of those hosts rootless, which
     * stopped the application being measured there at all and said, in the report, that the host declares no
     * root — which its operator can see is false.
     */
    $read = runbookSiteReading($this->dir, $dump);

    expect($read['named'])->toBe(['a.test' => ['/home/kitsune/site/current/public']])
        ->and($read['served'])->toBe(['a.test'])
        ->and($read['rootless'])->toBe([]);
})->with([
    'declared inside location /' => [<<<'CONF'
    server {
        listen 443 ssl;
        server_name a.test;
        location / {
            root /home/kitsune/site/current/public;
        }
    }
    CONF],
    'inherited from the http block' => [<<<'CONF'
    http {
        root /home/kitsune/site/current/public;

        server {
            listen 443 ssl;
            server_name a.test;
        }
    }
    CONF],
]);

it('reads the roots of the block that would answer, not of every block that names the hostname', function (): void {
    /*
     * ⚠️ THE APEX+WWW SHAPE, WHICH IS WHAT A CERTBOT OR FORGE HOST LOOKS LIKE. One `:80` block carries both
     * names, the site's root and the ACME challenge and redirects everything; the apex has a `:443` block with
     * the root; `www` has a `:443` block that does nothing but `return 301`. Merging the roots of every block
     * naming a hostname made `www` look like a second application — the very hostname this split exists to
     * tell apart — and both outside families then measured it as one: the throttle family signed in at it, and
     * the tunnel family asked what answered a request that is answered by a redirect.
     *
     * Both families request `https://<hostname>/…`, so the block that answers one is the block that decides.
     */
    $read = runbookSiteReading($this->dir, <<<'CONF'
    server {
        listen 80;
        server_name site.test www.site.test;
        root /home/forge/site.test/current/public;
        location /.well-known/acme-challenge {
        }
        return 301 https://$host$request_uri;
    }
    server {
        listen 443 ssl;
        server_name site.test;
        root /home/forge/site.test/current/public;
    }
    server {
        listen [::]:443 ssl;
        server_name www.site.test;
        return 301 https://site.test$request_uri;
    }
    CONF);

    expect($read['served'])->toBe(['site.test'])
        ->and($read['named']['www.site.test'])->toBe([])
        ->and($read['rootless'])->toBe(['[www.site.test] has no root ending in /public that an https request '
            .'for it would use (it would use none)']);
});

it('keeps the protocol the shared helpers print exactly as the gate reads it', function (): void {
    /*
     * The four lines run.sh and common.sh agree on, printed by the one copy: a verdict starts its line, a
     * refusal names its family, a sentinel names its family and counts its own ids, and a record is never
     * a verdict. A family is judged on these bytes, so they are asserted as bytes rather than as behaviour
     * seen through a whole family.
     */
    $harness = $this->dir.'/harness.php';
    File::put($harness, <<<'PHP'
    <?php

    declare(strict_types=1);

    require_once getenv('KITSUNE_LIB');

    const FAMILY = 'harness';
    const CHECKS = ['HRN-1', 'HRN-2'];

    $verdicts = [];
    record('HRN-1', "a fact\nover two lines");
    verdict('HRN-1', 'PASS', "a reason\nover two lines", $verdicts);
    verdict('HRN-2', 'FAIL', 'a plain reason', $verdicts);
    sentinel(FAMILY, $verdicts);

    try {
        verdict('HRN-2', 'VOID', 'a second verdict', $verdicts);
    } catch (LogicException) {
        echo "held\n";
    }

    try {
        verdict('HRN-9', 'PASS', 'an undeclared id', $verdicts);
    } catch (LogicException) {
        echo "held\n";
    }
    PHP);

    $run = new Process(['php', $harness], $this->dir, [
        'HOME' => (string) getenv('HOME'),
        'KITSUNE_LIB' => dirname(__DIR__, 3).'/deploy/runbook/outside/lib.php',
    ]);
    $run->run();

    expect($run->getOutput())->toBe(implode("\n", [
        'RECORD HRN-1 a fact over two lines',
        'VERDICT HRN-1 PASS a reason over two lines',
        'VERDICT HRN-2 FAIL a plain reason',
        'SENTINEL harness 2 HRN-1 HRN-2',
        'REFUSED harness verdict HRN-2 VOID [a second verdict]: emitted twice',
        'held',
        'REFUSED harness verdict HRN-9 PASS [an undeclared id]: this family did not declare that id',
        'held',
        '',
    ]), $run->getErrorOutput());
});
