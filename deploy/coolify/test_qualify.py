# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

from contextlib import redirect_stdout
import io
import json
from pathlib import Path
import runpy
import stat
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch


QUALIFIER = Path(__file__).with_name("qualify.py")


class QualifierCleanupTest(unittest.TestCase):
    def run_qualifier(self, receipt, runner, expected_exit=1):
        output = io.StringIO()
        argv = [str(QUALIFIER), "--image", "fixture", "--receipt", str(receipt)]
        with patch.object(sys, "argv", argv), patch.object(subprocess, "run", runner), redirect_stdout(output):
            with self.assertRaises(SystemExit) as exit_status:
                runpy.run_path(str(QUALIFIER), run_name="__main__")
        self.assertEqual(exit_status.exception.code, expected_exit)
        return json.loads(output.getvalue())

    def test_refuses_an_existing_receipt_directory_before_contacting_docker(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            parent.chmod(0o750)
            receipt = parent / "result.json"
            receipt.write_text("unrelated receipt")

            def unexpected_docker(*args, **kwargs):
                self.fail("The qualifier contacted Docker before securing the receipt directory")

            summary = self.run_qualifier(receipt, unexpected_docker)

            self.assertIn("create receipt directory", summary["archive_errors"][0])
            self.assertFalse(summary["cleanup_attempted"])
            self.assertEqual(receipt.read_text(), "unrelated receipt")
            self.assertEqual(stat.S_IMODE(parent.stat().st_mode), 0o750)

    def test_preserves_independent_outcomes_after_a_partial_compose_start(self):
        for fault in ("none", "copy", "receipt", "down", "inventory", "missing-docker",
                      "container-verification", "volume-verification", "network-remains", "interrupt", "restore"):
            with self.subTest(fault=fault), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                receipt = root / "run" / "result.json"
                sentinel = root / "unrelated.log"
                sentinel.write_text("unrelated")
                events = []
                config_count = 0
                certificate_runs = 0
                started = False
                removed = False

                def docker(argv, **kwargs):
                    nonlocal config_count, certificate_runs, started, removed
                    code, stdout, stderr = 0, b"", b""
                    if argv[:2] == ["docker", "compose"]:
                        command = argv[argv.index("--file") + 2:]
                        if command[:1] == ["--file"]:
                            command = command[2:]
                        operation = command[0]
                    else:
                        operation = argv[1]
                    events.append(operation)
                    if operation == "config" and "--format" in argv:
                        config_count += 1
                        mail = "log" if config_count == 1 else "fixture-smtp"
                        stdout = json.dumps({"services": {"app": {
                            "environment": {"MAIL_MAILER": mail, "MAIL_PASSWORD": mail},
                        }}}).encode()
                    elif operation == "up":
                        started = True
                        if fault not in {"interrupt", "restore"}:
                            code, stdout, stderr = 70, b"partial start", b"original qualification diagnostic"
                        if fault == "copy":
                            (receipt.parent / "failure.stderr").write_text("unrelated diagnostic")
                        if fault == "receipt":
                            receipt.write_text("unrelated receipt")
                    elif operation == "down":
                        self.assertEqual(command, ["down", "--volumes", "--remove-orphans"])
                        self.assertIn("--project-name", argv)
                        removed = fault != "down"
                        code = 0 if removed else 71
                    elif operation == "ps" and argv[:2] == ["docker", "compose"]:
                        if fault == "interrupt":
                            raise KeyboardInterrupt
                        self.assertEqual(fault, "restore")
                        stdout = b"fixture-container" if command[-1] == "app" else b""
                    elif operation == "inspect":
                        stdout = b'[{"State":{"Health":{"Status":"healthy"}},"HostConfig":{}}]'
                    elif operation == "run" and command[1:] == ["--rm", "--no-deps", "origin-certificates"]:
                        certificate_runs += 1
                        code = 1 if certificate_runs > 1 else 0
                    elif operation == "exec":
                        self.assertEqual(fault, "restore")
                        app = command[3:]
                        if app[0] == "cat":
                            stdout = (QUALIFIER.with_name("tls-init.sh").read_bytes()
                                      if app[1].endswith("kitsune-tls-init") else b"original index")
                        elif app[:2] == ["php", "-r"]:
                            stdout = b"[true,true,true,true,true]"
                        elif app[0] == "curl":
                            if "--cacert" not in app:
                                code, stdout = (0, b"\n400") if app[-1].startswith("http:") else (60, b"\n000")
                            else:
                                stdout = b"\n421" if "Host: foreign.example" in app else b"\n200"
                        elif app[0] == "git":
                            stdout = b"8c4bf66b22dcc6a9152af1b53a936c9eae2d742f"
                        elif app[0] == "tee":
                            code = 75 if kwargs["input"] == b"original index" else 70
                            stdout, stderr = b"partial start", b"original qualification diagnostic"
                    elif operation == "logs":
                        stdout = b"Compose diagnostic"
                    elif operation in {"ps", "volume", "network"}:
                        self.assertRegex(argv[-1], r"^label=com\.docker\.compose\.project=kitsune-profile-[0-9a-f]{12}$")
                        if operation == "ps" and started and not removed:
                            code = 72 if fault == "inventory" else 0
                            if fault == "missing-docker":
                                raise FileNotFoundError("docker")
                        if started and not removed:
                            stdout = b"owned fixture\n"
                        if started and removed:
                            if (operation, fault) in {("ps", "container-verification"), ("volume", "volume-verification")}:
                                code = 73
                            if operation == "network" and fault == "network-remains":
                                stdout = b"owned network\n"
                    return subprocess.CompletedProcess(argv, code, stdout, stderr)

                summary = self.run_qualifier(receipt, docker, expected_exit=130 if fault == "interrupt" else 1)

                self.assertEqual(events[-4:], ["down", "ps", "volume", "network"])
                self.assertTrue(summary["cleanup_attempted"])
                self.assertEqual(summary["cleanup_verified"],
                                 fault not in {"down", "container-verification", "volume-verification", "network-remains"})
                self.assertIn("KeyboardInterrupt" if fault == "interrupt" else "Command failed (exit 70)", summary["failure"])
                if fault == "restore":
                    self.assertIn("exit 75", summary["restore_failure"])
                self.assertEqual(sentinel.read_text(), "unrelated")
                if fault == "receipt":
                    self.assertEqual(receipt.read_text(), "unrelated receipt")
                    self.assertIn("write receipt", summary["archive_errors"][0])
                else:
                    archived = json.loads(receipt.read_text())
                    self.assertEqual(archived["failure"], summary["failure"])
                    self.assertEqual(stat.S_IMODE(receipt.stat().st_mode), 0o600)
                diagnostic = receipt.parent / "failure.stderr"
                if fault == "copy":
                    self.assertEqual(diagnostic.read_text(), "unrelated diagnostic")
                    self.assertIn("copy failure.stderr", summary["archive_errors"][0])
                elif fault != "interrupt":
                    self.assertEqual(diagnostic.read_bytes(), b"original qualification diagnostic")
                    self.assertEqual((receipt.parent / "failure.stdout").read_bytes(), b"partial start")
                compose_log = receipt.parent / "compose.log"
                self.assertEqual(compose_log.read_bytes(), b"Compose diagnostic")
                self.assertEqual(stat.S_IMODE(compose_log.stat().st_mode), 0o600)
                self.assertEqual(bool(summary["cleanup_errors"]),
                                 fault in {"down", "inventory", "missing-docker", "container-verification", "volume-verification"})


if __name__ == "__main__":
    unittest.main()
