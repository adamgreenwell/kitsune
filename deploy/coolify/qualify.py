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
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--image", required=True, help="locally built profile image")
parser.add_argument("--receipt", required=True, type=Path)
args = parser.parse_args()
profile = Path(__file__).resolve().parent
project = "kitsune-profile-" + secrets.token_hex(6)
host = "development.kitsunecms.org"
checks = []
result = {"project": project, "checks": checks, "provider_calls": False}


def command(argv, *, data=None, required=True, env=None):
    process = subprocess.run(argv, input=data, stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, env=env)
    if required and process.returncode:
        # Logs stay in the private receipt directory, never in an exception message.
        (private / "failure.stdout").write_bytes(process.stdout)
        (private / "failure.stderr").write_bytes(process.stderr)
        raise RuntimeError("Command failed; inspect private diagnostic files")
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
        "CLOUDFLARE_TUNNEL_TOKEN=fixture-never-start-the-connector\n")
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
                state = json.loads(command(["docker", "inspect", identity]).stdout)[0]
                if state["State"].get("Health", {}).get("Status") == "healthy":
                    return state
            time.sleep(1)
        dc("logs", "--no-color", "app", "database", "origin-certificates")
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

    existing = command(["docker", "ps", "--all", "--quiet", "--filter",
                        "label=com.docker.compose.project=" + project]).stdout.strip()
    existing_volumes = command(["docker", "volume", "ls", "--quiet", "--filter",
                                "label=com.docker.compose.project=" + project]).stdout.strip()
    check("disposable project namespace is unused", not existing and not existing_volumes)

    try:
        build_env = private / "build.env"
        build_env.write_text("")
        clean_environment = {key: value for key, value in os.environ.items() if key not in {
            "SERVICE_PASSWORD_64_DATABASE", "SERVICE_REALBASE64_KITSUNE", "CLOUDFLARE_TUNNEL_TOKEN"}}
        build_parse = command(["docker", "compose", "--project-name", project,
                               "--env-file", str(build_env), "--file", str(profile / "compose.yaml"),
                               "config", "--quiet"], env=clean_environment, required=False)
        check("build can parse Compose without runtime secrets", build_parse.returncode == 0)
        dc("config", "--quiet")
        dc("up", "--detach", "--no-build", "app")
        state = healthy()
        check("no published application ports", not state["HostConfig"].get("PortBindings"))
        check("real trusted origin TLS serves the app", request()[1] == "200")
        check("untrusted origin certificate is refused", request(trusted=False)[0] == 60)
        check("plaintext cannot masquerade as HTTPS", request(plain=True)[1] == "400")
        check("foreign actual Host is refused", request(headers=["Host: foreign.example"])[1] == "421")
        check("no public connector was started", not dc("ps", "-q", "connector").stdout.strip())
        check("FPM user cannot read origin private keys", app("su", "-s", "/bin/sh", "www-data", "-c",
              "test ! -r /run/kitsune-tls/server.key", required=False).returncode == 0)
        check("application source is pinned", app("git", "rev-parse", "HEAD").stdout.strip()
              == b"a8602823cd0e1272f9f74766e3985b50617a51e3")
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
            app("tee", index, data=original)
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
        private_volume = next(m["Name"] for m in state["Mounts"] if m["Destination"] == "/run/kitsune-tls")
        refused = command(["docker", "run", "--rm", "--network", "none", "--volume",
                           private_volume + ":/run/kitsune-tls:ro", args.image], required=False)
        check("missing application key refuses before startup", refused.returncode != 0)
        result["php"] = app("php", "-r", "echo PHP_VERSION;").stdout.decode()
        result["dependency_lock_sha256"] = app("sha256sum", "skeleton/composer.lock").stdout.decode().split()[0]
        result["image"] = state["Image"]
        result["architecture"] = command(["docker", "image", "inspect", args.image,
                                           "--format", "{{.Architecture}}"]).stdout.decode().strip()
        result["passed"] = True
    except Exception as failure:
        result["passed"] = False
        result["failure"] = str(failure)
        logs = dc("logs", "--no-color", required=False)
        (private / "compose.log").write_bytes(logs.stdout + logs.stderr)
    finally:
        args.receipt.parent.mkdir(parents=True, exist_ok=True)
        os.chmod(args.receipt.parent, 0o700)
        for file in private.iterdir():
            if file.name not in {"fixture.env", "override.json"} and file.is_file():
                target = args.receipt.parent / file.name
                target.write_bytes(file.read_bytes())
                os.chmod(target, 0o600)
        # Only this random disposable project's resources are eligible for cleanup.
        identities = command(["docker", "ps", "--all", "--quiet", "--filter",
                              "label=com.docker.compose.project=" + project]).stdout.split()
        result["owned_container_count"] = len(identities)
        dc("down", "--volumes", "--remove-orphans", required=False)
        remaining = command(["docker", "ps", "--all", "--quiet", "--filter",
                             "label=com.docker.compose.project=" + project]).stdout.strip()
        volumes = command(["docker", "volume", "ls", "--quiet", "--filter",
                           "label=com.docker.compose.project=" + project]).stdout.strip()
        result["cleanup_verified"] = not remaining and not volumes
        args.receipt.write_text(json.dumps(result, indent=2) + "\n")
        os.chmod(args.receipt, 0o600)
print(json.dumps({"passed": result.get("passed"), "checks": len(checks),
                  "cleanup_verified": result["cleanup_verified"], "receipt": str(args.receipt)}))
raise SystemExit(0 if result.get("passed") and result["cleanup_verified"] else 1)
