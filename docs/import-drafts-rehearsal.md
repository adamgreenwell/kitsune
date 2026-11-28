# Internal draft import rehearsal

The internal `kitsune:import-drafts` command is available when the host explicitly registers
`Kitsune\Core\Console\ImportDraftsCommand`. Use it with a disposable destination and existing
entry types. Each row supplies `source_type`, a stable string `source_id`, `entry_type`, `title`
and `slug`. JSON accepts optional `values` and `links` objects. CSV accepts the same objects in
`values_json` and `links_json` columns. Link values are ordered arrays of objects containing
`source_type` and `source_id`. The command maps each input `entry_type` token to a destination
handle supplied through a repeatable `--type` option.

```sh
php artisan kitsune:import-drafts preview drafts.csv --org=demo --site=catalogue \
  --source=demo-catalogue --format=csv --type=device=device --complete > review.json
php artisan kitsune:import-drafts digest review.json --org=demo --site=catalogue
php artisan kitsune:import-drafts apply review.json --org=demo --site=catalogue --approval="$approved_digest"
php artisan kitsune:import-drafts status review.json --org=demo --site=catalogue --approval="$approved_digest"
```

Use `--complete` when the input covers the intended import. Review the planned actions and findings
before approving the digest. Keep the exact report and the approved digest for status checks and a
restart. The status output reports committed draft and relation actions. Pending actions have no
committed receipt. An independently changed destination entry or schema requires a new review.

For media, add `--media-manifest` and `--media-root` to preview. The manifest is a JSON array
whose entries specify `source_type`, `source_id`, `path`, `original_name`, `size` in bytes,
`sha256` and the destination `entry_type_id` for an enabled media type. Each path is relative
to the local source directory. Run `stage-media` with the approved report, digest and media root
before applying. For a completed report, `purge-staged-media` takes the same report and approved
digest and verifies the managed file before removing its staging copy. For an abandoned report,
it releases the reservation after accounting for the staging copy. Cleanup can remove an
uncommitted managed copy with a recorded checksum, using the exact recorded version on S3.
An unconfirmed remote write requires operator review. On S3, staging cleanup uses the version
captured in the staging receipt. The approved media report uses
private files scoped to the selected Site. The journal records the checksum of the prepared file
before writing it. A retry can verify and adopt those exact bytes after an interruption. If
the managed file is absent and preparation produces a different checksum, the report requires
review. A later approved report reuses an unchanged mapped asset
without uploading it again. Changed descriptors and independently edited media entries appear as
conflicts in preview.


## Review a WordPress projection

Run `php bin/inspect-wordpress-acf.php SOURCE_DIR [PHP_FILES...]` from the trusted
development checkout to prepare a schema report from a disposable source copy.
The command reads `acf-json/*.json` and the explicitly selected PHP files or a
top-level `fields.php`. It parses literal ACF registrations without executing
the source. Review the blockers and exact input hashes. Conditional, dynamic, and
unsupported definitions need an explicit mapping decision before import.

The same command can preview a reviewed local projection using
`--format=wordpress-direct` or `--format=wordpress-wxr`. Choose an explicit source key,
map the post types to destination entry types, and provide a private media descriptor
for every attachment in the projection. Attachment IDs, byte sizes and checksums must
match. The command hashes the exact projection and includes its findings and limits
in the report the operator approves. Unknown projection fields and links to attachments
outside the reviewed input are refused. Blockers prevent approval, including with
`--complete`. WXR reports keep their export limits visible in `source_notices`.

The projection modes read reviewed local files. Content
bodies and original publication state stay in source custody while destination records
remain drafts. Check taxonomy, authors, comments, headings, redirects and extension
behavior against the intended migration scope before treating a source as complete.


For a local WXR 1.2 export, use `--format=wxr --wordpress-blog=ADDRESS --acf-schema=REPORT`.
The blog address must match the export. Supply the same media manifest and root for
attachments, plus the same explicit destination type map. The reader snapshots the
local file and streams items. It does not retrieve attachment URLs. The approved digest
binds the export bytes, the reviewed ACF report and the projected records. Only the WXR
path needs PHP XMLReader.


For an offline MySQL or MariaDB copy, configure a separate Laravel connection with a
read-only account limited to the selected prefix's `options`, `posts`, and `postmeta`
tables. Use `--format=wordpress-database --wordpress-connection=NAME` and pass the
confirmed blog URL, exact `--wordpress-prefix`, reviewed ACF report, and type map.
The positional path in database mode is the reviewed ACF report. The reader confirms
the source URL against the prefix's `home` option and requires InnoDB for a read-only
repeatable-read snapshot. Configure source credentials in the host environment.

## Plan former addresses

Once an approved draft import is complete, use `plan-redirects` with the same report,
approval digest, Org and Site. Provide `--redirect-inventory=PATH` with a local JSON
object containing `source_key`, `source_blog`, and an `addresses` list. Each address
contains an asserted `source_type`, `source_id`, and absolute `url`. The source blog
must match the address the raw import reader verified. The report links
that identity to its committed entry and records the current destination state.

The report is read-only. It holds unpublished destinations, duplicate addresses,
host-owned routes and old URLs that the Site's former-address mechanism cannot serve.
Dated URLs, query-string URLs and URLs on another origin need separate routing review.

Use `redirect-digest` with the exact saved redirect report after reviewing its findings.
Run `apply-redirects` with the same approved import report, inventory, Org and Site.
Pass the saved redirect report as `--redirect-report` and its separately approved digest
as `--redirect-approval`. The writer applies candidates in one transaction and checks the
current routes and destinations again. Changes require a fresh report. It reuses an
already-recorded redirect when it still has the same destination.

A held dated URL or an exact WordPress `?p=ID` URL can include a `host_route`
proposal with the old origin, exact request URI, and destination URL. A known old
origin or blog prefix change can also produce a proposal. The plan excludes wrong
source IDs, unrelated origins, duplicate requests and known route conflicts.
The hosting operator reviews ownership and the destination independently before
using these proposals. `apply-redirects` handles only the Site's former addresses.
