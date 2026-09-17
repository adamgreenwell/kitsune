#!/usr/bin/env bash
#
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
#
# Build one release of the skeleton, in place, from the root of a fresh checkout, before that release is activated.
# Stage runs it from deploy/stage-deploy.sh and alpha runs it from Forge's deploy script, unchanged, so what stage
# rehearses is what alpha runs (issue #111). Activation is not its job: it ends by saying the release is ready.
#
# Every input arrives in the environment, and every one is required, so nothing here guesses which server it is on:
#
#   DEPLOY_SHA    the full 40-character commit being released; the checkout must be exactly that commit
#   SITE_ROOT     the site's root: the shared .env and storage/ live there, and this release is under releases/
#   PHP_BIN       the PHP binary, e.g. php8.5 on stage and $FORGE_PHP on Forge
#   COMPOSER_BIN  Composer's file, always run as "$PHP_BIN" "$COMPOSER_BIN" so it resolves on that PHP
#
# ⚠️ WHAT IT DOES, in the order it does it:
#
#   1. Refuses before writing anything: a missing input, LARAVEL_CLOUD in any form, root, a site this user does not
#      own, a directory that is not a fresh release under SITE_ROOT/releases, a checkout that is not DEPLOY_SHA or
#      whose tracked files differ from it, a skeleton/.env.<name> Laravel would load instead of .env, and a missing
#      shared .env. A public deploy never creates an .env or generates a key (ADR-026).
#   2. Prints the PHP and Composer versions, so stage's deployment log and alpha's can be compared.
#   3. Refuses a PHP without the extensions nothing declares: dom (core builds DOMDocuments) and pdo_pgsql.
#   4. Links skeleton/.env and skeleton/storage to the shared ones. The app's base path is skeleton/, so the release
#      root's own paths, which Forge's shared paths link, are never read.
#   5. Resolves dependencies fresh. No composer.lock is restored or saved outside the release.
#   6. Points the skeleton at this checkout's packages/core, mirrored rather than symlinked. NOT because the package is
#      unpublished — it is, since #8 — but because a release pinned to DEPLOY_SHA must install THAT commit's core
#      rather than whatever Packagist resolves at deploy time (ADR-035, amended 2026-09-17).
#   7. Installs runtime dependencies with --no-scripts, then proves core was copied. ⚠️ Composer's scripts would run
#      package:discover, which boots Laravel before anything has proved the .env parses, and Laravel prints
#      phpdotenv's message for a malformed line to stderr. That message quotes the value: a password, into the log.
#   8. Audits the installed runtime packages for security advisories.
#   9. Parses the shared .env with phpdotenv's own parser, and on failure reports only the line, never the message.
#  10. Boots the app and refuses an unsafe environment, by what Laravel loads rather than by the text of .env. ⚠️ This is
#      the first boot, and the only one that withholds an exception's message: a provider's can quote configuration.
#  11. Runs package:discover itself, which is what Composer's scripts would have done.
#  12. Publishes Filament's assets into this release's public/.
#  13. Links public/storage, and proves the link resolves: storage:link exits 0 when it did nothing.
#  14. Builds config, event, route, view and icon caches, one command at a time, so each failure stops the release.
#      Compiled views go to this release's own skeleton/bootstrap/cache/views, never the shared storage.
#  15. Proves the release serves before the schema changes: the caches exist, no provider registers an optimize task
#      this script does not know, the environment still holds against the cached config, and GET /up through the
#      HTTP kernel answers 200.
#  16. Migrates. Only the two steps after it need the new schema, so only they can fail after it has changed.
#  17. Runs kitsune:audit-patterns --strict, Kitsune's deploy gate.
#  18. Runs kitsune:schema-sync as a report. Without --force it never changes anything.
#
# ⚠️ NEVER: --seed or db:seed, because the skeleton's DatabaseSeeder creates accounts whose password is `password`,
# and ADR-026 forbids a default administrator; migrate:fresh, which drops every table; key:generate, because a new key
# invalidates every session and everything encrypted with the old one; `optimize` or `filament:optimize`, which exit 0
# when one of their tasks fails; kitsune:benchmark-*, which are measurement spikes, not deploy steps; npm, because the
# committed assets are what serves.
#
# ⚠️ NO --isolated ON migrate. Its mutex locks on the default cache store, which is `database` when CACHE_STORE is
# unset, and that table does not exist before the first migration. Stage serialises its deploys with its own lock.
#
# ⚠️ A SITE IN MAINTENANCE MODE STAYS DOWN. The marker is in the shared storage, so the new release activates down too,
# which is what fixing forward needs. /up is exempt from maintenance mode, so the serving check still proves the
# release.
#
# HOW FORGE RUNS THIS. The site's deploy script, reviewed here rather than only in Forge's UI:
#
#   $CREATE_RELEASE()
#
#   cd $FORGE_RELEASE_DIRECTORY
#
#   # The release body, the same file stage runs. DEPLOY_SHA comes from the deployment hook's `&sha=` parameter, which
#   # Forge injects as FORGE_VAR_SHA. release.sh refuses a checkout that is not that commit, so "Deploy Now" and push
#   # to deploy, which carry no sha, deploy nothing. `|| exit 1` because Forge does not document running with errexit.
#   DEPLOY_SHA="${FORGE_VAR_SHA:-}" SITE_ROOT="$FORGE_SITE_ROOT" PHP_BIN="$FORGE_PHP" COMPOSER_BIN="$FORGE_COMPOSER" bash deploy/release.sh || exit 1
#
#   $ACTIVATE_RELEASE()
#
#   # Kitsune runs no queue workers, so this restarts nothing. Kept because Forge's docs say to include all three macros.
#   $RESTART_QUEUES()
#
#   # No PHP-FPM reload: Forge documents it as unnecessary for zero-downtime deployments.
#
# The site settings that script relies on, set by the operator in Forge's UI:
#
#   - Zero-downtime deployments ON, which can only be chosen when the site is created. PHP 8.5. Web directory
#     /skeleton/public. The repository root is the repository's root.
#   - Push to deploy OFF. It is on by default for new sites, and would deploy the branch tip on every push, skipping
#     stage.
#   - Deploy only through the deployment hook URL, adding &sha=<the 40-character commit stage deployed> and
#     &forge_deploy_commit=<the same>. forge_deploy_commit is only the label in Forge's history; the sha parameter is
#     what this script checks. Keep the hook URL private: requesting it triggers a deployment.
#     ⚠️ $CREATE_RELEASE() clones the branch tip (inferred: Forge's docs do not say which commit it checks out), and the
#     sha only names the commit this script insists on. So alpha can deploy a sha only while it is still the tip of the
#     site's branch: promote to alpha before anything else merges, or re-stage the new tip. Unverified until the first
#     alpha deploy, whose log should show it: which commit $CREATE_RELEASE() checked out, that the release is under
#     $FORGE_SITE_ROOT/releases/, that it keeps .git (the pin needs it), and that the site root and .env belong to the
#     deploying user.
#   - "Install Composer dependencies" UNTICKED. The app is in skeleton/, and this script installs it. The repository
#     root's composer.lock is the monorepo's development tooling, and installing it would spend Forge's 10-minute
#     limit on files nothing serves.
#   - Forge's Laravel panel reads that root composer.lock. Leave its scheduler and maintenance toggles off: artisan is
#     at skeleton/artisan, not at the release root.
#   - "Make .env variables available to deployment script" UNTICKED. This script reads .env through Laravel, and
#     injecting it would put a second copy of every secret into the deploy process's environment.
#   - No shared paths besides the default .env. This script links skeleton/.env and skeleton/storage itself, and
#     refuses a checkout whose committed files a shared path replaced.
#   - The .env is written through the environment editor at $FORGE_SITE_ROOT/.env, owned by the deploying user, with
#     APP_ENV=production, APP_DEBUG=false, an https APP_URL, SESSION_SECURE_COOKIE=true, DB_CONNECTION=pgsql and a
#     real APP_KEY. Never LARAVEL_CLOUD.
#
# ⚠️ AFTER AN .env EDIT, on either server: redeploy the same sha. Each release serves the config it cached when it was
# built, so an edit changes nothing until then, and this script's checks then judge the new values. In Forge's
# environment editor, leave the "config:cache" and "queue:restart" options unticked.
set -euo pipefail

refuse() {
  echo "Refusing to release: $*" >&2
  exit 1
}

sha_re='^[0-9a-f]{40}$'

# Run one of the checks below through the application itself. The PHP arrives on stdin, so its first argument is the
# mode. Refusals are collected and printed one per line, and none of them quotes a value that could be a secret.
check_the_app() {
  "$PHP_BIN" -- "$1" <<'PHP'
<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$root = (string) getcwd();
$refusals = [];

function release_refuse(string $reason): void
{
    $GLOBALS['refusals'][] = $reason;
}

function release_finish(): never
{
    foreach ($GLOBALS['refusals'] as $reason) {
        fwrite(STDERR, "Refusing to release: {$reason}\n");
    }

    exit($GLOBALS['refusals'] === [] ? 0 : 1);
}

if (! in_array($mode, ['syntax', 'environment', 'serving'], true)) {
    release_refuse("there is no check named [{$mode}]");
    release_finish();
}

if (! is_file($root.'/skeleton/vendor/autoload.php')) {
    release_refuse('skeleton/vendor/autoload.php is missing, so nothing was installed');
    release_finish();
}

require $root.'/skeleton/vendor/autoload.php';

if ($mode === 'syntax') {
    // skeleton/.env is the link Laravel loads, so it is what gets parsed.
    $contents = is_readable($root.'/skeleton/.env') ? file_get_contents($root.'/skeleton/.env') : false;

    if ($contents === false) {
        release_refuse('skeleton/.env cannot be read');
        release_finish();
    }

    $parser = new Dotenv\Parser\Parser;

    try {
        $parser->parse($contents);
    } catch (Throwable) {
        // ⚠️ THE PARSER'S MESSAGE QUOTES THE VALUE IT CHOKED ON, so it is never printed. The line is found instead:
        // the entry that breaks parsing starts on the line after the longest prefix of lines that still parses, and a
        // quoted value spanning several lines counts as one entry, because a prefix ending inside it does not parse.
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $parsed = 0;

        foreach (array_keys($lines) as $index) {
            try {
                $parser->parse(implode("\n", array_slice($lines, 0, $index + 1)));
                $parsed = $index + 1;
            } catch (Throwable) {
                // Not this prefix.
            }
        }

        release_refuse('the shared .env is not valid dotenv syntax at the entry starting on line '.($parsed + 1)
            .'. The parser\'s own message is withheld, because it quotes the value');
    }

    if ($GLOBALS['refusals'] === []) {
        echo "The shared .env parses.\n";
    }

    release_finish();
}

try {
    $app = require $root.'/skeleton/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
} catch (Throwable $e) {
    release_refuse(sprintf(
        'the application did not boot: %s at %s:%d. Its message is withheld, because it can quote configuration; '
        .'run artisan in %s to read it',
        $e::class,
        $e->getFile(),
        $e->getLine(),
        $root.'/skeleton',
    ));
    release_finish();
}

// What Laravel loaded, not what .env says: duplicate keys, `export` prefixes and quotes resolve as the framework
// resolves them, and a variable in the deploy process's environment wins over .env, as it will when this serves.
$config = $app->make('config');

if ($config->get('app.env') !== 'production') {
    release_refuse('APP_ENV must be production, and the app loads '.json_encode($config->get('app.env')));
}

if ($config->get('app.debug') !== false) {
    release_refuse('APP_DEBUG must be false: debug pages show the environment to anyone who triggers an error');
}

if (! is_string($config->get('app.url')) || ! str_starts_with($config->get('app.url'), 'https://')) {
    release_refuse('APP_URL must start with https://, and the app loads '.json_encode($config->get('app.url')));
}

if ($config->get('session.secure') !== true) {
    release_refuse('SESSION_SECURE_COOKIE must be true, so the session cookie is never sent over plain HTTP');
}

if ($config->get('database.default') !== 'pgsql') {
    release_refuse('DB_CONNECTION must be pgsql (an absent one falls back to SQLite), and the app loads '
        .json_encode($config->get('database.default')));
}

// ⚠️ STRICTER THAN laravel_cloud(), which fires only on '1': any LARAVEL_CLOUD is refused, because nothing on these
// servers has a reason to set it. On '1' Laravel runs its Cloud bootstrappers and changes how scheduled commands handle
// output, and it would trust every proxy if the skeleton's explicit trustProxies() list did not replace that (ADR-034).
if (array_key_exists('LARAVEL_CLOUD', $_ENV) || array_key_exists('LARAVEL_CLOUD', $_SERVER) || getenv('LARAVEL_CLOUD') !== false) {
    release_refuse('LARAVEL_CLOUD is set, and it must not be, in any form: nothing on these servers sets it');
}

try {
    $app->make('encrypter');
} catch (Throwable $e) {
    release_refuse('APP_KEY does not make a working encrypter ('.$e::class.')');
}

if ($mode === 'serving') {
    // Once config is cached, .env is not read again, so the checks above judged the cached config.
    if (! $app->configurationIsCached()) {
        release_refuse('the configuration was not cached ('.$app->getCachedConfigPath().' is missing)');
    }

    if (! $app->routesAreCached()) {
        release_refuse('the route table was not cached ('.$app->getCachedRoutesPath().' is missing)');
    }

    if (! $app->eventsAreCached()) {
        release_refuse('the event map was not cached ('.$app->getCachedEventsPath().' is missing)');
    }

    // ⚠️ THE CACHE STEPS ARE A LIST, AND A VENDOR CAN GROW IT. `optimize` runs every task a provider registers; this
    // script runs them by name instead, so a task it has never heard of is refused rather than silently skipped.
    $known = [
        'filament' => 'filament:optimize', // skipped on purpose: docs/architecture.md, Filament item 7
        'blade-icons' => 'icons:cache',    // run by name
    ];

    foreach (Illuminate\Support\ServiceProvider::$optimizeCommands as $key => $command) {
        if (($known[$key] ?? null) !== $command) {
            release_refuse("a provider registers the optimize task [{$key}] => [{$command}], which deploy/release.sh neither "
                .'runs nor deliberately skips. Decide which it should be, then add it to the cache steps or the known list');
        }
    }

    if ($GLOBALS['refusals'] === []) {
        try {
            $status = $kernel->handle(Illuminate\Http\Request::create('/up'))->getStatusCode();
        } catch (Throwable $e) {
            $status = $e::class;
        }

        if ($status !== 200) {
            release_refuse("/up answered {$status} instead of 200, so this release does not serve");
        }
    }
}

if ($GLOBALS['refusals'] === []) {
    echo "The {$mode} check passed.\n";
}

release_finish();
PHP
}

# 1. Refuse before anything is written.
for input in DEPLOY_SHA SITE_ROOT PHP_BIN COMPOSER_BIN; do
  [[ -n "${!input:-}" ]] || refuse "$input is required"
done

[[ -z "${LARAVEL_CLOUD+set}" ]] || refuse "LARAVEL_CLOUD is set in the deploy environment, and it must not be, in any form"
[[ "$(id -u)" != 0 ]] || refuse "never run as root: what it creates in the shared storage would be unwritable by PHP-FPM"
[[ "$SITE_ROOT" == /* && -d "$SITE_ROOT" ]] || refuse "SITE_ROOT must be an absolute, existing directory"

site_root=$(cd "$SITE_ROOT" && pwd -P)
[[ -O "$site_root" ]] || refuse "run as the user that owns $site_root (PHP-FPM's user)"

release=$(pwd -P)
[[ "$release" == "$site_root/releases/"?* ]] || refuse "run from a release directory under $site_root/releases, not $release"
[[ -f skeleton/artisan && -f skeleton/composer.json && -f packages/core/composer.json ]] \
  || refuse "$release is not the root of a Kitsune checkout"

[[ "$DEPLOY_SHA" =~ $sha_re ]] || refuse "DEPLOY_SHA must be a full 40-character lowercase commit hash"
head=$(git rev-parse HEAD 2>/dev/null || true)
[[ "$head" == "$DEPLOY_SHA" ]] || refuse "this checkout is not $DEPLOY_SHA"
git diff --quiet HEAD -- || refuse "tracked files differ from $DEPLOY_SHA (a shared path replacing a committed one?)"

[[ ! -e skeleton/vendor ]] || refuse "skeleton/vendor exists, so this is not a fresh checkout"
[[ ! -e skeleton/.env || -L skeleton/.env ]] || refuse "skeleton/.env is a real file (a development checkout?)"

for file in skeleton/.env.*; do
  [[ "$file" == skeleton/.env.example || ! -e "$file" ]] \
    || refuse "$file exists, and Laravel loads it instead of .env when APP_ENV is set in the environment"
done

command -v "$PHP_BIN" >/dev/null || refuse "PHP_BIN is not a command: $PHP_BIN"

# A path is taken as it is, because an installed Composer need not be executable when PHP runs it; a bare name is found.
if [[ "$COMPOSER_BIN" == */* ]]; then
  composer_bin=$COMPOSER_BIN
else
  composer_bin=$(command -v "$COMPOSER_BIN" || true)
fi

[[ -n "$composer_bin" && -f "$composer_bin" ]] || refuse "COMPOSER_BIN must name Composer's file, and $COMPOSER_BIN does not"

[[ -f "$site_root/.env" && -r "$site_root/.env" && -O "$site_root/.env" ]] \
  || refuse "the shared .env at $site_root/.env must exist and belong to this user: the operator writes it, and this script never creates one or generates a key"
[[ ! -e "$site_root/storage" || -O "$site_root/storage" ]] || refuse "$site_root/storage belongs to another user"

# 2. The versions, so a resolution difference between stage and alpha can be traced afterwards.
"$PHP_BIN" -r 'echo "PHP ", PHP_VERSION, " at ", PHP_BINARY, PHP_EOL;'
"$PHP_BIN" "$composer_bin" --version --no-interaction

# 3. Only the extensions nothing declares. Composer checks every ext-* a runtime package declares while it resolves.
# exit(1), because exit("a message") exits 0.
"$PHP_BIN" -r 'foreach (["dom", "pdo_pgsql"] as $e) { if (! extension_loaded($e)) { fwrite(STDERR, "Refusing to release: PHP is missing the $e extension.\n"); exit(1); } }'

# 4. The shared state. The committed skeleton/storage is a directory of placeholders, and `ln -s` onto an existing
# directory would put the link inside it, so it goes first. Storage is shared whole, except compiled views (step 14).
mkdir -p "$site_root/storage/app/private" "$site_root/storage/app/public" "$site_root/storage/framework/cache/data" \
  "$site_root/storage/framework/sessions" "$site_root/storage/framework/views" "$site_root/storage/logs"
rm -f skeleton/.env
ln -s "$site_root/.env" skeleton/.env
rm -rf skeleton/storage
ln -s "$site_root/storage" skeleton/storage
[[ -f skeleton/.env && -d skeleton/storage/framework/views ]] || refuse "the shared .env or storage did not link"

# 5. No lock to restore: the skeleton commits none, and a lock saved per server would freeze each at its first resolve.

# 6. kitsune/core from this checkout, as real files, the way a Packagist install will put them. The edit exists only in
# this release's working tree, after step 1 proved it clean. The package is on Packagist since #8; this stays a path
# repository so the release installs the core of the commit it is pinned to, which step 7 then proves.
"$PHP_BIN" "$composer_bin" config -d skeleton repositories.kitsune-core '{"type":"path","url":"../packages/core","options":{"symlink":false}}'

# 7. Runtime dependencies, with Forge's documented flags plus --no-scripts (see the header).
"$PHP_BIN" "$composer_bin" install -d skeleton --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
[[ -f skeleton/vendor/kitsune/core/composer.json && ! -L skeleton/vendor/kitsune/core ]] \
  || refuse "kitsune/core was not installed as a copy of packages/core"

# 8. Known advisories block the release. --abandoned is explicit, so the policy does not depend on Composer's version.
"$PHP_BIN" "$composer_bin" audit -d skeleton --no-dev --abandoned=report

# 9. The .env parses, before anything boots Laravel.
check_the_app syntax

# 10. The environment, as the booted app loads it, before config:cache bakes it in.
#
# ⚠️ NOTHING BOOTS LARAVEL BEFORE THIS. Artisan prints an uncaught exception's message, and a provider that throws while
# booting can quote configuration in it, so an artisan command here would put that message into the deployment log. The
# boot builds the missing package manifest itself, so package providers boot here too, under the same redaction.
check_the_app environment

# 11. The package manifest, which Composer's post-autoload-dump would have built.
"$PHP_BIN" skeleton/artisan package:discover --no-interaction

# 12. The committed Filament assets can lag the freshly resolved Filament.
"$PHP_BIN" skeleton/artisan filament:assets --no-interaction

# 13. storage:link reports an existing link as an error and still exits 0, so the test after it is the gate.
"$PHP_BIN" skeleton/artisan storage:link --no-interaction
[[ -L skeleton/public/storage && -d skeleton/public/storage ]] || refuse "skeleton/public/storage does not resolve"

# 14. What `optimize` would run, one command at a time: its four core tasks, plus Blade Icons' registered one. Filament's
# task is skipped on purpose (docs/architecture.md), and step 15 refuses any task this list does not know.
#
# ⚠️ COMPILED VIEWS STAY IN THIS RELEASE. view:cache runs view:clear first, which empties the compiled-view directory,
# so in the shared storage it would delete the live release's compiled views while that release still serves them.
# config:cache bakes VIEW_COMPILED_PATH into this release's cached config, because a variable in the environment wins
# over .env, and the directory goes when the release is pruned.
mkdir -p skeleton/bootstrap/cache/views
export VIEW_COMPILED_PATH="$release/skeleton/bootstrap/cache/views"
"$PHP_BIN" skeleton/artisan config:cache --no-interaction
"$PHP_BIN" skeleton/artisan event:cache --no-interaction
"$PHP_BIN" skeleton/artisan route:cache --no-interaction
"$PHP_BIN" skeleton/artisan view:cache --no-interaction
"$PHP_BIN" skeleton/artisan icons:cache --no-interaction

# 15. The release serves, proven before the schema changes, so a refusal here leaves the live release untouched.
check_the_app serving

# 16. The schema, with exactly the cached config that will serve. --force because APP_ENV is production.
"$PHP_BIN" skeleton/artisan migrate --force --no-interaction

# 17. Kitsune's deploy gate. It reads field_storage, which does not exist before the first migration.
"$PHP_BIN" skeleton/artisan kitsune:audit-patterns --strict --no-interaction

# 18. A report. Applying it can rewrite and lock a large entries table, so --force stays an operator's decision.
"$PHP_BIN" skeleton/artisan kitsune:schema-sync --no-interaction

echo "Release $release ($DEPLOY_SHA) is ready to activate."
