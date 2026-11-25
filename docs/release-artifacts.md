# Release artifact verification

The release trust chain belongs to [#213](https://github.com/adamgreenwell/kitsune/issues/213).
This development implementation supplies a **source-bound builder, offline signing command, pre-PHP
download verifier and pinned instruction generator**. Native and Docker installers integrate their finished
bytes under #215/#216. Host qualification, real key custody and publication remain separate gates.
There is no production public key, supported download URL or usable release one-liner in this document.

## Signature and trust root

The candidate format uses detached **RSA PKCS#1 v1.5 with SHA-256**, with a 3072–8192-bit RSA key.
The signature authenticates the exact received manifest bytes. The manifest's SHA-256 and exact sizes bind
the artifact bytes. This uses the OpenSSL extension Laravel already requires; sodium, Minisign, Node and
a new service are not application requirements. The implementation does not change package constraints.

An earlier local Ed25519/JSON spike demonstrated signature interoperability and redirect refusal. It did
not establish a portable pre-PHP parser or OpenSSL3 availability on supported targets. A narrow ASCII
manifest avoids needing PHP/JSON tooling to read bootstrap metadata; RSA/SHA-256 avoids relying on the
newer Ed25519 command/API path. The shipped Bash/awk verifier uses the OpenSSL CLI before PHP starts;
availability on supported native targets still needs qualification.
The earlier measurements remain evidence for that earlier prototype, not this implementation.

The operator must independently pin the **SHA-256 of the public key's DER SubjectPublicKeyInfo**. A key
downloaded beside a manifest is not a trust root. The versioned bootstrap must itself have a
reviewed, independently obtained checksum and pinned key fingerprint. A signature is not a statement
that a release is the latest, a platform was qualified, an upgrade origin is admitted or support is active.
The operator's exact version and channel must match the signed values.

The intended custody model is offline maintainer-controlled signing, with no production private key in
CI. Actual custodian, protected storage, passphrase entry, backup custody, continuity delegate and recovery
channels must be recorded before a production key is created or used. No such role or ceremony is claimed
here. Private keys/passphrases must never travel in command arguments, environment variables or logs.

Planned rotation requires a reviewed versioned trust update and independently confirmed old/new
fingerprints before separately authorized publication and fetched-byte readback. Do not silently accept
a replacement key from the download host. Compromise or loss stops signing and publication; an old-key
cross-signature alone cannot repair a compromised root. Re-establish trust out of band and record the
compromise boundary and recovery instructions. Preserve historical release/signature evidence.

## Version 1 wire format

This is bounded ASCII with literal tabs between fields and exactly one LF after every row, including
the final row. There are no comments, blank rows, escapes, paths, URLs or optional headers. The limit is
65,536 bytes; at most 16 artifacts and 32 package records. A signed but unsupported schema is refused.

The six header rows, in order, are:

```text
kitsune-release-manifest-v1
version<TAB>exact-v-prefixed-semantic-version
channel<TAB>test-or-stable
source<TAB>40-lowercase-hex-Git-commit
tree<TAB>40-lowercase-hex-Git-tree
signature<TAB>rsa-pkcs1-sha256
```

Then one or more artifacts, sorted by distinct filename:

```text
artifact<TAB>filename<TAB>role<TAB>platform<TAB>byte-count<TAB>sha256
```

Filenames are ASCII basenames, never options or paths, at most 128 characters and without `..`.
Roles are `bootstrap`, `native`, `docker` or `provenance`; platforms are `any`, `linux-amd64` or
`linux-arm64`. Byte counts are canonical positive decimal integers, bounded at 10 GiB. This is a verifier
input bound, not a new disk or runtime requirement for the default installation. Hashes are 64 lowercase
hexadecimal characters. This format carries opaque artifact bytes; it does not authorize archive extraction.

Then one or more first-party package records, sorted by distinct package name:

```text
package<TAB>kitsune/name<TAB>exact-version<TAB>40-hex-reference<TAB>manifest-sha256<TAB>publication
```

Package versions are exact semantic versions or, on the test channel, `dev-` followed by the same commit hash
as that package record's source reference.
Publication intent is `split`, `bundled` or `excluded`; a manifest neither publishes a package nor proves
that its bytes are in an archive. The stable channel refuses prerelease/development versions. Build metadata
is accepted and remains part of the exact requested identity.

The current split workflow names core alone. Core, person, commerce and svg-sanitizer have package manifests,
and the skeleton directly requires person. The builder inventories those committed manifests, including the
skeleton, and requires explicit versions and publication intent for every one. It refuses `split` intent for
a package absent from the source workflow's explicit package matrix. Do not silently add packages to tag publishing
or call a core-only archive a complete installation. Final package resolution/bundle contents need their own
qualification before a release is signed.

## Build and offline signing

The build plan is local JSON with exactly `artifacts` and `packages` members. Each artifact supplies a `path`,
`role` and `platform`; the builder streams the actual local file's digest and size. Package names map to explicit
`version` and `publication` values. The plan must name every first-party package present in the exact source
commit, including the skeleton. It must not name invented packages or silently omit optional ones.

```sh
php bin/release-artifacts.php build \
  --source="$source_checkout" --commit="$exact_source_commit" \
  --version="$candidate_version" --channel="$candidate_channel" --plan="$local_build_plan"
```

The manifest is written to stdout only after all input validation succeeds. Git objects supply package-manifest
bytes, source commit and tree; a dirty working copy or local Git replacement ref cannot substitute different
package declarations or trees under that commit. Artifact
files are explicit local build inputs and may differ from the source archive; their bytes are independently
hashed. This is provenance, not a dependency solver or proof of archive contents. Package constraints, final
lock/resolution inputs and actual bundled bytes still need separate release qualification.

The signing command requires an already authorized **encrypted PEM private key on stdin** and the exact
passphrase bytes on file descriptor 3. It creates no key, chooses no custodian and reads no signing secret
from argv or environment. The public key, independently confirmed fingerprint, version and channel must match;
the command verifies its produced signature before emitting the raw detached signature to stdout.

```sh
php bin/release-artifacts.php sign \
  --manifest="$manifest_file" --key="$public_key_file" \
  --fingerprint="$independently_confirmed_fingerprint" \
  --version="$requested_version" --channel="$requested_channel" \
  < "$encrypted_private_key_file" 3< "$private_passphrase_input"
```

These variables identify protected operator inputs, not checked-in key material. fd3 is an exact byte channel;
a trailing newline is part of a passphrase and is not silently trimmed. In an authorized signing ceremony,
capture stdout into a new, exclusively created file in the operator's protected working directory. Keep
manifest/signature output separate from diagnostic stderr and check exit status before using either file.
No real signing ceremony or publication has been authorized or performed by adding these commands.

## Offline verifier

After obtaining the manifest, detached signature, public key and artifact directory through the separately
reviewed trust path, run the verifier with **all seven explicit inputs**:

```sh
php bin/release-artifacts.php verify \
  --manifest="$manifest_file" --signature="$signature_file" --key="$public_key_file" \
  --fingerprint="$independently_confirmed_fingerprint" \
  --version="$requested_version" --channel="$requested_channel" --directory="$artifact_directory"
```

These variables name operator-supplied local inputs, not published release values. The verifier authenticates
the raw manifest, checks its schema and exact requested identity, then hashes **every** listed artifact through
an open regular-file handle. Missing, symbolic/multiple-link, changed, truncated or mismatched inputs fail.
Control files are bounded before reading; artifact hashing streams rather than loading archives into memory.
Only a successful final result produces a JSON receipt on stdout. Refusal exits nonzero and writes stderr.
It never downloads, extracts, executes, activates code or changes an installation. No `--execute` option exists.

Local files and the verified artifact directory must stay under the operator's control between verification
and any later separately authorized use. This command cannot make a mutable file immutable after it exits.

## Pre-PHP verification and download

`bin/verify-release.sh` is self-contained Bash/awk plus standard file tools and the OpenSSL CLI. It requires
no PHP, JSON parser, Node, application bootstrap, database or daemon. These host-tool capabilities must be
checked on the actual supported native targets; a development machine or utility container is not an
installer qualification. The PHP test suite exercises strict local crypto/transport adapters using its
existing OpenSSL extension, so the bare-clone suite adds no network or service requirement.

Its offline mode accepts the same seven explicit inputs as the PHP verifier:

```sh
bash bin/verify-release.sh verify \
  --manifest="$manifest_file" --signature="$signature_file" --key="$public_key_file" \
  --fingerprint="$independently_confirmed_fingerprint" \
  --version="$requested_version" --channel="$requested_channel" --directory="$artifact_directory"
```

The default checks every artifact. An explicit `--artifact="$selected_basename"` checks one selected record
and reports both the selected count and total manifest count. The whole manifest must still authenticate
and parse, including records for other platforms. Payload formats remain opaque; no archive is extracted
and no installer is executed. Platform selection/admission belongs to the integrating installer.

Fetch mode takes the independently obtained local key and pin, exact version/channel, existing output
directory and selected basename. It does not accept local manifest/signature paths:

```sh
bash bin/verify-release.sh fetch \
  --key="$public_key_file" --fingerprint="$independently_confirmed_fingerprint" \
  --version="$requested_version" --channel="$requested_channel" \
  --directory="$artifact_directory" --artifact="$selected_basename"
```

The only routes are `https://kitsunecms.org/releases/<exact-version>/release.manifest`, its `.sig` companion,
and the selected authenticated artifact basename beneath that same version. This is the distribution route
contract, not a claim that any release exists there. The key is checked before any request; raw signature
verification and complete schema/identity checks precede requesting the payload. Other-platform payloads
are not fetched. Missing signatures, unsupported formats, wrong pins, hashes and sizes all refuse.

curl configuration is disabled explicitly. Transfers require verified TLS and HTTP **200**, refuse every
redirect without following it (even if curl exits zero), and bound time and byte counts. Private download
files live on the output filesystem with restrictive permissions. After exact size/hash checks, exclusive
hard-link promotion refuses an existing or racing destination; there is no overwrite/copy fallback.
Only the invocation's fixed temporary files are removed. Any refusal exits nonzero with no success receipt.

## Generate a concrete distribution record

The maintainer-side `instructions` command first authenticates the manifest and verifies **all** local
artifact bytes, just like `verify`. It then requires the explicitly selected bootstrap to match the shipped
standalone verifier byte for byte, with role `bootstrap` and platform `any`, and a distinct selected payload.
The generator refuses an arbitrary signed script; it never trusts an instruction template downloaded at run
time. Include a copy of the reviewed `bin/verify-release.sh` as a bootstrap artifact in the local build plan.

```sh
php bin/release-artifacts.php instructions \
  --manifest="$manifest_file" --signature="$signature_file" --key="$public_key_file" \
  --fingerprint="$independently_confirmed_fingerprint" \
  --version="$requested_version" --channel="$requested_channel" --directory="$artifact_directory" \
  --bootstrap="$bootstrap_basename" --artifact="$selected_basename"
```

Stdout is a `kitsune-release-instructions-v1` JSON record containing exact release/source/tree identity,
manifest/signature digests, independently confirmed fingerprint, complete first-party package records,
bootstrap/payload sizes and hashes, literal versioned routes, and four operator command fields:

1. `download` obtains the bootstrap into a private directory, checks HTTP200, size and the embedded SHA-256,
   and exclusively promotes it to a checksum-named local file.
2. `inspect` opens that local script with `less`. Inspection is the operator's deliberate step.
3. `run` rechecks the local script's size/hash before invoking its authenticated selected-payload fetch.
4. `one_liner` combines download and run with the same literal pins and checks. It skips inspection only.

The operator obtains this record and its fingerprint through an independently trusted, reviewed channel,
and places the separately trusted public key at `./release-public.pem` in an operator-controlled working
directory. The commands run there. They never download a key/checksum to establish its own trust or pipe an
unverified HTTP response into Bash. They preserve existing files, and refuse a changed inspected bootstrap.
Run generated commands with Bash; both forms leave one verified payload for a later explicit install step.
Neither form executes/extracts that payload or qualifies its host, platform, package resolution or upgrade
origin. Final installer invocation instructions are supplied when #215/#216 integrate their actual formats.

Generation is offline and reports `publication_verified: false`. It makes no request and cannot establish
that a URL exists, redirects are absent on the real host, or publication was authorized. A concrete test
record using ephemeral test keys is component evidence only. Real release records need reviewed final
bytes, custody decisions, separately authorized signing/publication and independent fetched-byte readback.

## Remaining delivery gates

- Complete final source/package resolution and actual bundle-content provenance.
- Qualify the actual native/bootstrap capabilities without raising the resource floor or dependencies;
  provide equivalent Docker verification and preservation acceptance separately.
- Generate final distribution records from reviewed representative artifacts, then integrate final installer
  bytes later. No generated-but-unpublished route becomes a supported download by appearing in a record.
- Run full source/CI/Codex qualification and obtain explicit merge authority. Real signing, publication and
  independent verification of fetched final bytes remain a separate explicit execution gate.

All original #213 acceptance items remain open. See the [versioning and upgrade policy](versioning-and-upgrades.md)
for support/origin admission and [installation](install.md) for the separate first-owner/host boundaries.
