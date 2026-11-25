#!/usr/bin/env python3
# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.
"""Explicit disposable Docker qualification; never part of the bare-clone suite."""
import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--image", required=True, help="locally built profile image")
parser.add_argument("--receipt", required=True, type=Path)
args = parser.parse_args()
diagnostic_names = ("failure.stdout", "failure.stderr", "compose.log")
if args.receipt.name.casefold() in diagnostic_names:
    parser.error("--receipt must use a name distinct from the diagnostic files")
profile = Path(__file__).resolve().parent
project = "kitsune-profile-" + secrets.token_hex(6)
host = "development.kitsunecms.org"
checks = []
result = {
    "project": project, "checks": checks, "provider_calls": False,
    "passed": False, "cleanup_attempted": False, "cleanup_verified": None,
    "cleanup_errors": [], "archive_errors": [],
}


def report():
    summary = {key: value for key, value in result.items() if key in {
        "project", "passed", "failure", "cleanup_attempted", "cleanup_verified",
        "cleanup_errors", "archive_errors", "restore_failure", "interrupted",
    }}
    print(json.dumps({**summary, "checks": len(checks), "receipt": str(args.receipt)}))
    if result.get("interrupted"):
        return 130
    return 0 if (
        result["passed"] and result["cleanup_verified"]
        and not result["cleanup_errors"] and not result["archive_errors"]
    ) else 1


def write_private(path, data):
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "wb") as output:
        output.write(data)


try:
    # A new directory gives the run exclusive names for the receipt and its diagnostics.
    args.receipt.parent.mkdir(mode=0o700)
except OSError as failure:
    result["archive_errors"].append(f"create receipt directory: {failure}")
    raise SystemExit(report())


def command(argv, *, data=None, required=True, env=None):
    process = subprocess.run(argv, input=data, stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, env=env)
    if required and process.returncode:
        # Logs stay in the private receipt directory, never in an exception message.
        for name, data in (("failure.stdout", process.stdout), ("failure.stderr", process.stderr)):
            try:
                (private / name).write_bytes(data)
            except OSError as failure:
                result["archive_errors"].append(f"record {name}: {failure}")
        raise RuntimeError(f"Command failed (exit {process.returncode}); inspect private diagnostic files")
    return process


def check(name, condition):
    checks.append({"name": name, "passed": bool(condition)})
    if not condition:
        raise AssertionError(name)


with tempfile.TemporaryDirectory(prefix=project + "-") as directory:
    private = Path(directory)
    os.chmod(private, 0o700)
    result["diagnostics"] = str(args.receipt.resolve().parent)
    env_file = private / "fixture.env"
    env_file.write_text(
        "SERVICE_PASSWORD_64_DATABASE=" + secrets.token_hex(32) + "\n"
        "SERVICE_REALBASE64_KITSUNE=" + base64.b64encode(secrets.token_bytes(32)).decode() + "\n"
        "CLOUDFLARE_TUNNEL_TOKEN=fixture-never-start-the-connector\n"
        "KITSUNE_MAIL_MAILER=smtp\n"
        "KITSUNE_MAIL_PASSWORD=fixture-no-provider-key-" + secrets.token_hex(16) + "\n"
        "KITSUNE_MAIL_FROM_ADDRESS=profile-test@kitsunecms.org\n")
    os.chmod(env_file, 0o600)
    override = private / "override.json"
    override.write_text(json.dumps({"services": {
        "app": {"image": args.image},
        "origin-certificates": {"image": args.image},
    }}))
    compose = ["docker", "compose", "--project-name", project,
               "--env-file", str(env_file), "--file", str(profile / "compose.yaml"),
               "--file", str(override)]

    def dc(*argv, **kwargs):
        return command([*compose, *argv], **kwargs)

    def app(*argv, **kwargs):
        return dc("exec", "-T", "app", *argv, **kwargs)

    def healthy():
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            identity = dc("ps", "-q", "app").stdout.decode().strip()
            if identity:
                inspected = command(["docker", "inspect", identity], required=False)
                if inspected.returncode == 0:
                    state = json.loads(inspected.stdout)[0]
                    if state["State"].get("Health", {}).get("Status") == "healthy":
                        return state
            time.sleep(1)
        raise RuntimeError("Profile did not become healthy within 180 seconds")

    def request(path="/", headers=(), plain=False, trusted=True):
        argv = ["curl", "--silent", "--show-error", "--max-time", "10",
                "--write-out", "\n%{http_code}"]
        if plain:
            argv += ["-H", "Host: " + host, "http://127.0.0.1:8443" + path]
        else:
            if trusted:
                argv += ["--cacert", "/run/kitsune-tls/ca.crt"]
            argv += ["--resolve", host + ":8443:127.0.0.1"]
            if not any(header.lower().startswith("host:") for header in headers):
                argv += ["-H", "Host: " + host]
            for header in headers:
                argv += ["-H", header]
            argv += ["https://" + host + ":8443" + path]
        process = app(*argv, required=False)
        body, _, status = process.stdout.rpartition(b"\n")
        return process.returncode, status.decode(), body

    label = "label=com.docker.compose.project=" + project
    containers = ["docker", "ps", "--all", "--quiet", "--filter", label]
    volumes = ["docker", "volume", "ls", "--quiet", "--filter", label]
    networks = ["docker", "network", "ls", "--quiet", "--filter", label]
    cleanup_required = False

    def cleanup_command(name, run):
        try:
            process = run()
        except OSError as failure:
            result["cleanup_errors"].append(f"{name}: {failure}")
            return None
        if process.returncode:
            result["cleanup_errors"].append(f"{name}: exit {process.returncode}")
            return None
        return process.stdout.split()

    try:
        existing = command(containers).stdout.strip()
        existing_volumes = command(volumes).stdout.strip()
        existing_networks = command(networks).stdout.strip()
        check("disposable project namespace is unused", not (existing or existing_volumes or existing_networks))
        build_env = private / "build.env"
        build_env.write_text("")
        clean_environment = {key: value for key, value in os.environ.items() if key not in {
            "SERVICE_PASSWORD_64_DATABASE", "SERVICE_REALBASE64_KITSUNE", "CLOUDFLARE_TUNNEL_TOKEN",
            "KITSUNE_MAIL_MAILER", "KITSUNE_MAIL_SCHEME", "KITSUNE_MAIL_HOST", "KITSUNE_MAIL_PORT",
            "KITSUNE_MAIL_USERNAME", "KITSUNE_MAIL_PASSWORD", "KITSUNE_MAIL_FROM_ADDRESS"}}
        build_parse = command(["docker", "compose", "--project-name", project,
                               "--env-file", str(build_env), "--file", str(profile / "compose.yaml"),
                               "config", "--quiet"], env=clean_environment, required=False)
        check("build can parse Compose without runtime secrets", build_parse.returncode == 0)
        defaults = json.loads(command(["docker", "compose", "--project-name", project,
                                      "--env-file", str(build_env), "--file", str(profile / "compose.yaml"),
                                      "config", "--format", "json"], env=clean_environment).stdout)
        check("sending mail stays opt-in by default", defaults["services"]["app"]["environment"]["MAIL_MAILER"] == "log")
        dc("config", "--quiet")
        rendered = json.loads(dc("config", "--format", "json").stdout)
        smtp_password = rendered["services"]["app"]["environment"]["MAIL_PASSWORD"]
        check("SMTP credential reaches only the app service", all(
            not any(key.startswith("MAIL_") or key.startswith("KITSUNE_MAIL_") for key in service.get("environment", {}))
            for name, service in rendered["services"].items() if name != "app"))
        check("SMTP credential is absent from every build argument", all(
            smtp_password not in json.dumps(service.get("build", {}))
            for service in rendered["services"].values()))
        cleanup_required = True
        dc("up", "--detach", "--no-build", "app")
        state = healthy()
        initializer = app("cat", "/usr/local/bin/kitsune-tls-init").stdout
        check("image carries the exact certificate initializer", initializer == (profile / "tls-init.sh").read_bytes())

        def certificate_command(script):
            return dc("run", "--rm", "--no-deps", "--entrypoint", "/bin/sh",
                      "origin-certificates", "-c", script)

        def certificate_witness():
            return certificate_command("find /private /public -type f -exec sha256sum {} \\; | sort").stdout

        complete_custody = certificate_witness()
        dc("run", "--rm", "--no-deps", "origin-certificates")
        check("complete certificate custody is reused unchanged", certificate_witness() == complete_custody)
        for name, key, mutation in [
            ("missing CA private key", "ca", "mv /private/ca.key /private/ca.key.proof"),
            ("mismatched CA private key", "ca", "mv /private/ca.key /private/ca.key.proof; "
             "openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /private/ca.key >/dev/null 2>&1"),
            ("mismatched server private key", "server", "mv /private/server.key /private/server.key.proof; "
             "openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /private/server.key >/dev/null 2>&1"),
        ]:
            certificate_command(mutation)
            damaged_custody = certificate_witness()
            refused = dc("run", "--rm", "--no-deps", "origin-certificates", required=False)
            check(name + " refuses without rewriting custody", refused.returncode != 0
                  and certificate_witness() == damaged_custody)
            certificate_command("mv /private/" + key + ".key.proof /private/" + key + ".key")
        for retained in ["ca.key", "ca.crt"]:
            certificate_command("mkdir /private/.proof; for file in /private/*; do "
                                "if test \"$file\" != /private/" + retained + "; then mv \"$file\" /private/.proof/; fi; "
                                "done; mv /public/ca.crt /public/ca.crt.proof")
            damaged_custody = certificate_witness()
            refused = dc("run", "--rm", "--no-deps", "origin-certificates", required=False)
            check("custody containing only " + retained + " refuses without regeneration", refused.returncode != 0
                  and certificate_witness() == damaged_custody)
            certificate_command("mv /private/.proof/* /private/; rmdir /private/.proof; "
                                "mv /public/ca.crt.proof /public/ca.crt")
        check("certificate regression fixtures restore exact custody", certificate_witness() == complete_custody)
        mail_probe = app("php", "-r", "require 'skeleton/vendor/autoload.php'; $a=require 'skeleton/bootstrap/app.php'; "
                         "$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "
                         "echo json_encode([config('mail.default') === 'smtp', "
                         "config('mail.mailers.smtp.scheme') === 'smtps' && config('mail.mailers.smtp.port') == 465, "
                         "config('mail.mailers.smtp.host') === 'smtp.resend.com' && config('mail.mailers.smtp.username') === 'resend', "
                         "hash_equals(getenv('MAIL_PASSWORD'), config('mail.mailers.smtp.password')), "
                         "config('mail.from.address') === 'profile-test@kitsunecms.org']);").stdout
        check("cached SMTP configuration preserves TLS and private credential", json.loads(mail_probe) == [True] * 5)
        image_environment = command(["docker", "image", "inspect", args.image,
                                     "--format", "{{json .Config.Env}}"]).stdout
        check("runtime SMTP credential is absent from image environment", smtp_password.encode() not in image_environment)
        check("no published application ports", not state["HostConfig"].get("PortBindings"))
        check("real trusted origin TLS serves the app", request()[1] == "200")
        check("untrusted origin certificate is refused", request(trusted=False)[0] == 60)
        check("plaintext cannot masquerade as HTTPS", request(plain=True)[1] == "400")
        check("foreign actual Host is refused", request(headers=["Host: foreign.example"])[1] == "421")
        check("no public connector was started", not dc("ps", "-q", "connector").stdout.strip())
        check("FPM user cannot read origin private keys", app("su", "-s", "/bin/sh", "www-data", "-c",
              "test ! -r /run/kitsune-tls/server.key", required=False).returncode == 0)
        check("application source is pinned", app("git", "rev-parse", "HEAD").stdout.strip()
              == b"8c4bf66b22dcc6a9152af1b53a936c9eae2d742f")
        check("first-party packages match pinned source", app("php", "deploy/release-check.php", "packages", "1").returncode == 0)

        index = "/srv/kitsune/skeleton/public/index.php"
        original = app("cat", index).stdout
        nonce = "/__profile_probe_" + secrets.token_hex(12)
        probe = """<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(\\Illuminate\\Contracts\\Http\\Kernel::class);
$kernel->bootstrap();
$app['router']->get('PROBE_ROUTE', fn () => response()->json([
 'host' => request()->getHost(), 'secure' => request()->isSecure(),
 'port' => request()->getPort(), 'ip' => request()->ip(),
 'path' => request()->getPathInfo(), 'admin' => url('/admin'),
]));
$kernel->handle(\\Illuminate\\Http\\Request::capture())->send();
""".replace("PROBE_ROUTE", nonce).encode()
        try:
            app("tee", index, data=probe)
            response = request(nonce, headers=[
                "X-Forwarded-For: 6.6.6.6, 203.0.113.25",
                "X-Forwarded-Host: foreign.example",
                "X-Forwarded-Proto: http", "X-Forwarded-Port: 80",
                "X-Forwarded-Prefix: /foreign",
            ])
            check("kernel probe returned JSON", response[1] == "200")
            observed = json.loads(response[2])
            result["forwarding_probe"] = observed
            check("forwarded Host cannot choose the Site", observed["host"] == host)
            check("real TLS remains secure despite forged Proto", observed["secure"] is True)
            check("public port ignores forged forwarded port", observed["port"] == 443)
            check("rightmost untrusted client address wins", observed["ip"] == "203.0.113.25")
            check("forwarded prefix does not rewrite URLs", observed["admin"] == "https://" + host + "/admin")
        finally:
            probe_failed = sys.exc_info()[0] is not None
            try:
                restored = app("tee", index, data=original, required=False)
                if restored.returncode:
                    result["restore_failure"] = f"Restore command failed (exit {restored.returncode})"
            except OSError as failure:
                result["restore_failure"] = str(failure)
            if not probe_failed:
                check("front controller restore command succeeded", "restore_failure" not in result)
        check("original front controller restored exactly", app("cat", index).stdout == original)
        check("original application serves after probe", request()[1] == "200")

        headers = app("curl", "--silent", "--show-error", "--cacert", "/run/kitsune-tls/ca.crt",
                      "--resolve", host + ":8443:127.0.0.1", "-H", "Host: " + host,
                      "--dump-header", "-", "--output", "/dev/null",
                      "https://" + host + ":8443/admin/login").stdout.decode()
        cookies = [line.lower() for line in headers.splitlines() if line.lower().startswith("set-cookie:")]
        check("native admin login serves successfully", " 200 " in headers.splitlines()[0])
        check("native session cookies are secure", bool(cookies) and all("secure" in line for line in cookies))
        check("development responses are noindex", "x-robots-tag: noindex, nofollow" in headers.lower())

        marker = secrets.token_hex(16).encode()
        app("tee", "/data/storage/app/private/profile-proof", data=marker)
        key_witness = app("php", "-r", "echo hash('sha256', getenv('APP_KEY'));").stdout
        certificate_witness = app("sha256sum", "/run/kitsune-tls/server.crt").stdout
        dc("exec", "-T", "database", "psql", "-U", "kitsune", "-d", "kitsune", "-v", "ON_ERROR_STOP=1",
           data=b"CREATE TABLE profile_persistence_proof (marker text NOT NULL);\nINSERT INTO profile_persistence_proof VALUES ('" + marker + b"');\n")
        old_database = dc("ps", "-q", "database").stdout.strip()
        dc("up", "--detach", "--no-build", "--force-recreate", "--no-deps", "database")
        old_identity = state["Id"]
        dc("up", "--detach", "--no-build", "--force-recreate", "--no-deps", "app")
        state = healthy()
        check("application was genuinely recreated", state["Id"] != old_identity)
        check("database was genuinely recreated", dc("ps", "-q", "database").stdout.strip() != old_database)
        check("database data survives recreation", dc("exec", "-T", "database", "psql", "-U", "kitsune",
              "-d", "kitsune", "-Atc", "SELECT marker FROM profile_persistence_proof").stdout.strip() == marker)
        check("application key survives recreation", app("php", "-r", "echo hash('sha256', getenv('APP_KEY'));").stdout == key_witness)
        check("origin certificate survives recreation", app("sha256sum", "/run/kitsune-tls/server.crt").stdout == certificate_witness)
        check("private storage survives recreation", app("cat", "/data/storage/app/private/profile-proof").stdout == marker)
        app("rm", "/data/storage/app/private/profile-proof")
        dc("exec", "-T", "database", "psql", "-U", "kitsune", "-d", "kitsune", "-Atc",
           "SELECT COUNT(*) FROM users")
        check("no administrator was silently provisioned", dc("exec", "-T", "database", "psql", "-U", "kitsune",
              "-d", "kitsune", "-Atc", "SELECT COUNT(*) FROM users").stdout.strip() == b"0")
        private_volume = next((m["Name"] for m in state["Mounts"] if m["Destination"] == "/run/kitsune-tls"), None)
        check("origin TLS volume is mounted", private_volume is not None)
        refused = command(["docker", "run", "--rm", "--network", "none", "--volume",
                           private_volume + ":/run/kitsune-tls:ro", args.image], required=False)
        check("missing application key refuses before startup", refused.returncode != 0)
        result["php"] = app("php", "-r", "echo PHP_VERSION;").stdout.decode()
        result["dependency_lock_sha256"] = app("sha256sum", "skeleton/composer.lock").stdout.decode().split()[0]
        result["image"] = state["Image"]
        result["architecture"] = command(["docker", "image", "inspect", args.image,
                                           "--format", "{{.Architecture}}"]).stdout.decode().strip()
        result["passed"] = True
    except (Exception, KeyboardInterrupt) as failure:
        result["failure"] = f"{type(failure).__name__}: {failure}" if str(failure) else type(failure).__name__
        if isinstance(failure, KeyboardInterrupt):
            result["interrupted"] = True
        if cleanup_required:
            try:
                logs = dc("logs", "--no-color", required=False)
                (private / "compose.log").write_bytes(logs.stdout + logs.stderr)
                if logs.returncode:
                    result["archive_errors"].append(f"collect Compose logs: exit {logs.returncode}")
            except OSError as log_failure:
                result["archive_errors"].append(f"collect Compose logs: {log_failure}")
    finally:
        if cleanup_required:
            result["cleanup_attempted"] = True
            identities = cleanup_command("inventory owned containers", lambda: command(containers, required=False))
            result["owned_container_count"] = None if identities is None else len(identities)
            cleanup_command("Compose down", lambda: dc("down", "--volumes", "--remove-orphans", required=False))
            remaining = cleanup_command("verify containers", lambda: command(containers, required=False))
            remaining_volumes = cleanup_command("verify volumes", lambda: command(volumes, required=False))
            remaining_networks = cleanup_command("verify networks", lambda: command(networks, required=False))
            result["cleanup_verified"] = remaining == remaining_volumes == remaining_networks == []

        for name in diagnostic_names:
            diagnostic = private / name
            try:
                if diagnostic.is_file():
                    write_private(args.receipt.parent / name, diagnostic.read_bytes())
            except OSError as failure:
                result["archive_errors"].append(f"copy {name}: {failure}")
        try:
            write_private(args.receipt, (json.dumps(result, indent=2) + "\n").encode())
        except OSError as failure:
            result["archive_errors"].append(f"write receipt: {failure}")

raise SystemExit(report())
