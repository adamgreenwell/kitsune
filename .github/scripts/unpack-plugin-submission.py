"""Unpack the exact submitted artifact for inspection without running package code."""

import argparse
import hashlib
import os
import re
import shutil
import stat
import unicodedata
from contextlib import contextmanager
from pathlib import Path, PurePosixPath
from zipfile import BadZipFile, ZipFile


MAX_BYTES = 128 * 1024 * 1024
MAX_FILES = 25_000
MAX_MEMBER_DEPTH = 64
MAX_TRANSPORT_BYTES = MAX_BYTES + 1024 * 1024


def review_members(archive):
    members = archive.infolist()
    if len(members) > MAX_FILES or sum(item.file_size for item in members) > MAX_BYTES:
        raise ValueError("The submission exceeds the archive inspection budget.")

    entries = set()
    paths = {}
    for item in members:
        name = item.orig_filename
        path = name.removesuffix("/")
        parts = path.split("/")
        mode = stat.S_IFMT(item.external_attr >> 16)
        is_directory = item.is_dir()
        expected_mode = stat.S_IFDIR if is_directory else stat.S_IFREG
        if (
            name != item.filename
            or len(parts) > MAX_MEMBER_DEPTH
            or len(path.encode("utf-8")) > 4096
            or any(part in {"", ".", ".."} for part in parts)
            or any(re.search(r"[\\:\x00-\x1f\x7f]", part) for part in parts)
            or any(unicodedata.category(char).startswith("C") or char in "\u2028\u2029" for char in path)
            or (not path.isascii() and not item.flag_bits & 0x800)
            or mode not in {0, expected_mode}
            or (is_directory and item.file_size != 0)
            or path in entries
        ):
            raise ValueError("The submission has an invalid or repeated archive member.")
        entries.add(path)

        for length in range(1, len(parts) + 1):
            prefix = "/".join(parts[:length])
            directory = length < len(parts) or is_directory
            key = unicodedata.normalize("NFC", prefix).casefold()
            previous = paths.setdefault(key, (prefix, directory))
            if previous != (prefix, directory):
                raise ValueError("The submission has conflicting archive member paths.")

    return members, paths


@contextmanager
def verified_archive(path, expected, byte_limit):
    if not re.fullmatch(r"[0-9a-f]{64}", expected):
        raise ValueError("Supply an expected SHA-256 for each archive.")
    with path.open("rb") as snapshot:
        if os.fstat(snapshot.fileno()).st_size > byte_limit:
            raise ValueError("The submission exceeds the archive inspection budget.")
        if hashlib.file_digest(snapshot, "sha256").hexdigest() != expected:
            raise ValueError("The submitted bytes differ from the expected digest.")
        snapshot.seek(0)
        with ZipFile(snapshot) as archive:
            yield archive


def package_root(paths):
    if paths.get("composer.json") == ("composer.json", False):
        return ""
    roots = [path for path, directory in paths.values() if directory and "/" not in path]
    if len(roots) == 1:
        root = roots[0]
        manifest = root + "/composer.json"
        if (
            paths.get(unicodedata.normalize("NFC", manifest).casefold()) == (manifest, False)
            and all(path == root or path.startswith(root + "/") for path, _ in paths.values())
        ):
            return root + "/"
    raise ValueError("The distribution must contain one package at its root.")


def unpack(artifact, artifact_digest, distribution_digest, output):
    distribution = output.with_name("distribution.zip")
    with verified_archive(artifact, artifact_digest, MAX_TRANSPORT_BYTES) as transport:
        members, _ = review_members(transport)
        if len(members) != 1 or members[0].filename != distribution.name:
            raise ValueError("The artifact must contain only the original distribution.zip.")
        with transport.open(members[0]) as source, distribution.open("xb") as destination:
            shutil.copyfileobj(source, destination)

    with verified_archive(distribution, distribution_digest, MAX_BYTES) as archive:
        members, paths = review_members(archive)
        root = package_root(paths)
        output.mkdir()
        for item in members:
            relative = item.filename[len(root):]
            if not relative:
                continue
            target = output.joinpath(*PurePosixPath(relative).parts)
            if item.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with archive.open(item) as source, target.open("xb") as destination:
                    shutil.copyfileobj(source, destination)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("artifact", type=Path)
    parser.add_argument("artifact_sha256")
    parser.add_argument("distribution_sha256")
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    try:
        unpack(args.artifact, args.artifact_sha256, args.distribution_sha256, args.output)
    except (BadZipFile, OSError, RuntimeError, ValueError) as error:
        parser.exit(2, f"{error}\n")


if __name__ == "__main__":
    main()
