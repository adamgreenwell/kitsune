# Rehearse media storage on Amazon S3

Kitsune stores media locally by default. This guide covers the optional direct Amazon S3 profile using
general-purpose buckets and Laravel's `league/flysystem-aws-s3-v3` adapter. The profile has passed
loopback tests and still awaits its first provider qualification. Use a disposable Kitsune instance and
dedicated test buckets for this rehearsal. FTP, SFTP, other S3-compatible providers and CDN delivery each need
their own qualification.
[Issue #229](https://github.com/adamgreenwell/kitsune/issues/229)

## Choose where media lives

| Disk | Delivery |
|---|---|
| Private | Kitsune authorizes the staff member or reader before streaming through its existing routes. Enable all four Block Public Access settings on the private bucket and leave the disk's `url` unset. |
| Public | Kitsune links directly to the HTTPS bucket origin. Allow anonymous `s3:GetObject` only under the public media prefix through the bucket policy. Preserve `Cache-Control: no-store` at the origin. |

This profile uses no presigned media links. Keep upload intake on Kitsune's built-in local disk so the application
still authorizes and validates uploads. A SQLite installation can opt into either S3 disk independently.

## Configure the rehearsal

1. Add the [Laravel S3 adapter](https://laravel.com/framework/docs/13.x/filesystem#s3-driver-configuration) to the
   host application's Composer dependencies and build from the reviewed lockfile.
2. Define named `s3` disks in the host application's `config/filesystems.php` with their own bucket, explicit
   region and write options. Use separate buckets when both media disks use S3. Supply application credentials
   through the host's secure runtime configuration and keep SDK and HTTP debug output disabled.
3. Set Bucket owner enforced and use `'options' => ['ACL' => 'bucket-owner-full-control']` on each S3 disk.
   Add `'CacheControl' => 'no-store'` inside the public disk's options. On the public disk, set `url` to the HTTPS
   bucket origin before the disk root. With a root of `kitsune`, Laravel already adds that root to the URL and
   the media prefix is `kitsune/media/`.
   [AWS Object Ownership](https://docs.aws.amazon.com/AmazonS3/latest/userguide/about-object-ownership.html)
4. Use never-versioned buckets with Object Lock and replication off. Enable an `AbortIncompleteMultipartUpload`
   lifecycle rule on each S3 bucket that covers the entire media prefix. The public bucket's effective Block
   Public Access settings must permit the intended policy. Review the full policy and any account-level block.
   Review all lifecycle rules for expiry or transitions that affect media you intend to retain.
   [AWS multipart cleanup](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpu-abort-incomplete-mpu-lifecycle-config.html)
   · [AWS Block Public Access](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-control-block-public-access.html)
5. Set both disk names under `media.disks` in the host application's `config/kitsune.php`. To use S3 for private
   media only, set `private` to the named S3 disk and keep `public` set to `public`. For public S3 only, keep
   `private` set to `kitsune-private`. Each existing row still identifies its own disk. Keep existing
   disks readable until you have verified a separate media move.

## Check the buckets and actual delivery

Run from the disposable installation. The command uses the same configured principal as each media disk.

```sh
php artisan kitsune:media-s3-diagnose
```

The command checks region, ownership, versioning, Object Lock, replication, lifecycle configuration,
bucket-level public access block and bucket policy status. It also checks the configured ACL and public cache header option.
It reads bucket metadata without changing buckets or objects. If a read is denied or the result is unknown,
resolve it before proceeding. Inspect the effective access policy and read headers from a stored object
separately. Local-only installs exit successfully with a skip and need no adapter. For the rehearsal,
confirm that the output includes the expected private or public bucket check table.
[AWS S3 operation permissions](https://docs.aws.amazon.com/AmazonS3/latest/userguide/using-with-s3-policy-actions.html)

With disposable files and accounts on the intended provider path, record these checks separately from the diagnostic.

1. Upload through Kitsune. Verify the private object by checksum through a permitted staff route and an entitled
   reader route. Verify that anonymous origin requests, unentitled readers, and staff without the grant cannot
   read it. Include a sibling Site and another Org.
2. Publish a new fixture after configuring the disk. Verify the exact bytes and `Cache-Control: no-store` from the
   origin without credentials. Withdraw the fixture and verify the former public URL no longer serves the bytes while authorized private access still works.
3. Trash, restore and dispose of the fixture. Check the retained and removed copies at each step. Verify effective
   access, absence of old object versions and coverage of the incomplete-upload cleanup rule. Retained backups
   or copies outside these buckets follow the operator's documented retention and recovery policy.
4. On disposable storage, test a refused public deletion. Verify that Kitsune reports refusal, the item stays
   published, the same URL still serves the bytes and the audit has no successful withdrawal. Restore the
   permission and verify withdrawal on retry. Keep credentials and raw SDK output out of the rehearsal record.

If you intend to add an intermediary, repeat delivery and withdrawal through the actual visitor URL. The
bucket diagnostic covers the origin configuration it can read. Record visitor-path results separately.
[Back up and restore](backup-restore.md) · [Profile decision](decision-log.md#direct-amazon-s3-profile-under-qualification-issue-229)
