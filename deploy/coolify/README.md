# Kitsune development on Coolify

This is the dedicated `development.kitsunecms.org` sandbox profile, not a change
to the default installation or the Forge deployment. The Dockerfile installs the
explicitly qualified application commit `69d9872ade2d7c9c221f72a64c52ee4cfab6b207`
from the public repository, mirrors that checkout's first-party packages, verifies
their bytes with `deploy/release-check.php`, and audits runtime dependencies.
The infrastructure branch and application commit are recorded independently.

The 2026-10-21 application pin includes the bounded financial status-read correction
(#307), the paid-checkout wording correction (#305), the commerce metadata repair
(#303) and the merged #299/#302 fixes. Local image qualification, exact-head CI/review and
installed-source/HTTP verification remain separate gates. The rollout stays in
Stripe test mode with the existing private transport and persistent volumes.

## Transport and custody

The application listens only on **127.0.0.1:8443 inside its container**. Its
dedicated cloudflared connector shares that network namespace. There are no
published application/database ports, generated public domains or Traefik routes.
nginx terminates real TLS before FastCGI. The skeleton's existing ADR-034 loopback
trust of **X-Forwarded-For only** remains unchanged. Forwarded Host/Proto/Port/Prefix
are not made trusted, and unknown actual Host values return 421.

A separate one-shot origin-certificate service provisions a private CA and a
90-day server certificate into dedicated volumes. Only the public CA certificate
is mounted into the connector. It verifies the origin's certificate and hostname;
no `noTLSVerify` or certificate-warning bypass is used. Public browser/Stripe TLS
is provided by Cloudflare's edge. This profile proves neither public delivery nor
the server's origin topology until the actual deployed checks run.

The CA and server key are not regenerated on restarts. Partial or expired custody
refuses. Renew the origin leaf before its 90-day expiry using the existing CA,
back up the private CA/server material, and rerun the transport checks. This
development profile does not implement automatic renewal. Never remove the
certificate volumes to hide a failed check. There is no production readiness claim.

## Provision, then deploy

1. In the **Kitsune / development** environment, create a Git Compose application
   from the infrastructure branch. Use `/deploy/coolify` as the base directory and
   `/compose.yaml` as the Compose location: Coolify's Compose project directory
   controls relative build contexts. Turn automatic deployments off and select
   **Raw (deploy file as-is)** with build arguments **Managed manually in Dockerfile**.
   Managed Compose injects its complete environment into every service; raw mode
   preserves this profile's per-service credential boundaries. Do not use the root
   `compose.yaml`: that is the disposable test matrix.
2. Review the rendered services. Leave all Coolify Domains/public port mappings
   empty. Keep its app/database/storage/certificate volumes separate from every
   existing project. Coolify must provision and persist `SERVICE_REALBASE64_KITSUNE`
   (32 random bytes, Base64) and `SERVICE_PASSWORD_64_DATABASE` before deployment.
   The former becomes `APP_KEY=base64:<value>`. Never print these values, replace
   them during a redeploy, commit them, or use a shared project's database password.
   Keep these variables runtime-only. Empty interpolation lets Compose parse the
   build without secrets; startup still refuses missing/invalid application keys,
   and the database and connector refuse absent credentials. The build context
   allowlist excludes environment files.
3. Create a **dedicated** Cloudflare Tunnel named `kitsune-development`. Its
   connector token belongs only in this resource's hidden
   `CLOUDFLARE_TUNNEL_TOKEN` variable. Enter it privately. Do not paste it into
   chat, a ticket, a deployment command or a build argument.
4. Add the tunnel's published hostname `development.kitsunecms.org`, service
   **HTTPS** `127.0.0.1:8443`, with:
   - Origin Server Name: `development.kitsunecms.org`;
   - CA Pool: `/etc/kitsune-origin/ca.crt`;
   - Disable TLS verification: **off**;
   - HTTP Host Header override: **unset**.
   This creates the proxied DNS record for this tunnel. Do not modify `stage`,
   `stage-fr`, `stage-he`, the existing `kitsune-stage` tunnel or shared proxy rules.
5. The Access applications must already be present: the root hostname permits
   only the two operator-approved exact email identities, with 24-hour sessions;
   `/cart/stripe/webhook` has its separately approved Bypass policy. Access path
   precedence is checked with real requests after DNS exists. The native webhook
   still requires valid Stripe signatures. No wildcard root bypass is needed.
6. Deploy the configured commit and record its resolved application/profile
   source, image digest, architecture, generated dependency inventory, actual
   PHP/PostgreSQL versions and health results. Startup runs the existing checked
   environment/serving gates, explicit caches, migrations, credential readability
   and pattern audit. It does not run seeds, generate keys, create an owner,
   install/enable module receipts, admit live checkout, or contact Stripe/Shippo.
7. First-owner provisioning is a separate native operator step with hidden
   password entry. Install/enable commerce through its ordinary lifecycle and
   configure the Storefront, Site origin and test credentials explicitly. Mail
   defaults to `log`: email sign-up/recovery and order preparation are unavailable
   until a suitable sending transport is configured. Do not claim guest-email
   journeys passed in this state.

### Optional Resend SMTP

The operator can supply these **runtime-only** Coolify variables. They are mapped
only into the app service; never add them as Docker build arguments or share them
with the connector or database:

- `KITSUNE_MAIL_MAILER=smtp`
- `KITSUNE_MAIL_SCHEME=smtps`, `KITSUNE_MAIL_HOST=smtp.resend.com`,
  `KITSUNE_MAIL_PORT=465`, `KITSUNE_MAIL_USERNAME=resend` (the profile defaults)
- `KITSUNE_MAIL_FROM_ADDRESS`: an explicitly chosen address on a Resend-verified domain
- `KITSUNE_MAIL_PASSWORD`: the Resend sending API key, entered privately into Coolify

The display name is `Kitsune Development`. Implicit TLS retains ordinary certificate
verification; no local trust or verification bypass is added. Saving a variable
does not change a running container: deploy the reviewed profile revision and
verify actual delivery to an approved test recipient. Do not send to real buyers
or use production order data. This SMTP setup does not change Stripe credential
mode or grant live admission. See [Resend SMTP](https://resend.com/docs/send-with-smtp).

Existing certificate custody must include the CA private key as well as its
certificate and the server key/certificate. The initializer verifies both key
pairs before reuse. A missing/mismatched key or a lone CA artifact refuses without
regenerating or overwriting the remaining custody; restore its complete backup.

## Checks and rollback

Before publication, qualify an isolated local stack: real trusted TLS, rejection of
plain HTTP and foreign Hosts, generated HTTPS links, secure cookies, hostile
forwarding headers through the actual PHP kernel, private database/storage,
restart persistence, required-key refusal and exact first-party package copies.
The default bare-clone test suite does not run Docker or require these services.

Run the disposable qualification explicitly after building the image:

```sh
docker build -t kitsune-development-profile:local deploy/coolify
python3 deploy/coolify/qualify.py --image kitsune-development-profile:local \
    --receipt /tmp/kitsune-profile-proof/result.json
```

It starts only its random Compose project's app, certificate initializer and
database, never the connector. Generated fixture credentials remain in a private
temporary file. It replaces the front controller only inside that disposable
container to measure actual FastCGI/kernel forwarding behavior, restores its bytes,
recreates the app and database to check data, key and certificate persistence,
and removes only its own labeled
containers/volumes. The receipt distinguishes a failure from a cleanup failure.

The local ARM64 qualification on 2026-10-19 passed all 30 transport, custody,
restart and secret-free Compose parsing checks on PHP 8.5.10, with cleanup verified.
That result is local evidence only; the AMD64 Coolify host and public route still
require their own deployment readback. No connector or real provider was called.

After deployment, independently check container health, certificate verification,
Access login at the root, webhook ingress without an Access redirect, unknown and
encoded adjacent routes, actual source identity and application-level rejection of
unsigned events. A green Coolify deployment is not this readback. Do not log raw
signatures, payloads, secrets, guest proofs or private Checkout URLs.

For an initial rollout failure, stop the **Kitsune development resource only** and
remove its new hostname route/DNS publication through the normal authorized
Cloudflare flow. Keep persistent storage, database, APP_KEY and certificate
volumes for diagnosis; do not delete them or weaken verification. After sandbox
purchases exist, resolve unknown payment/refund operations before teardown.
Redeploy the prior qualified image/profile for a later rollback; migrations need
their own reviewed recovery plan and are never reversed by guessing.
