# Internal CSV and JSON rehearsal

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
it releases the reservation after accounting for the staging copy. A managed write whose entry
never committed requires operator review. On
S3, cleanup uses the version captured in the staging receipt. The approved media report uses
private files scoped to the selected Site. A later approved report reuses an unchanged mapped asset
without uploading it again. Changed descriptors and independently edited media entries appear as
conflicts in preview.
