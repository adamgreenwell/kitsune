# Isolated migration rehearsal on Coolify

Use this profile to bring up a disposable Kitsune destination for import tests.
It starts with the Kitsune skeleton on SQLite. Choose the destination blueprint
after startup. The app uses one CPU and 1 GiB of memory, accepts HTTPS on its
container loopback interface, and runs with its network disabled. Use a terminal
attached to the app container for administration and fixture requests.

## Provision

1. Create a dedicated Git Compose resource from the profile branch in the
   rehearsal environment. Use `/deploy/coolify/rehearsal` as the base directory
   and `/compose.yaml` as the Compose location. Select **Raw (deploy file as-is)**
   and manage build arguments in the Dockerfile. Disable automatic deployments
   and leave the Domains and port mapping fields empty.
2. Keep the four named volumes dedicated to this resource. The first boot
   generates its application key and origin certificates. Retain these volumes
   when replacing a container. Each state volume is bound to the origin recorded
   on its first boot.
3. Set `KITSUNE_REHEARSAL_HOST` to a hostname reserved for rehearsals, or use
   the default `migration.example.test`. The host needs no DNS record. Leave
   `KITSUNE_REHEARSAL_SITE_API` unset for a standard installation. Set it to
   `true` only for a Site API rehearsal. This registers the Site API routes and
   applies the optional token migration. Disabling the flag removes the routes
   at startup and keeps previously created token data in SQLite. Revoke any test
   tokens when the API rehearsal is done.
4. Confirm the app is healthy, then check the resolved commit and image in the
   deployment record. The Dockerfile pins the Kitsune commit and base images.
   The fixture Composer lock adds Sanctum to the skeleton's dependency graph.

From the app terminal, with the default host, verify the origin before
provisioning a fixture owner and Site:

```sh
curl --fail --silent --show-error --noproxy '*' \
  --cacert /run/kitsune-rehearsal-ca/ca.crt \
  --resolve migration.example.test:8443:127.0.0.1 \
  -H 'Host: migration.example.test' https://migration.example.test:8443/up
php artisan kitsune:blueprint apply marketing-site --org=migration --owner=owner@example.test
php artisan kitsune:site address https://migration.example.test --org=migration --site=migration
```

Use fixture or approved offline source data. Use your chosen private transfer
method to place an offline fixture file in the app's dedicated storage before
running a rehearsal. Import plans and approval records belong with the operator.

## Qualify a profile change

When refreshing the application pin, update the Compose image tag and
fixture lock together. Resolve the lock against the pinned checkout's skeleton,
local Core and Person packages, and the explicit Sanctum requirement.

Run both variants on a Docker host with Compose v2, OpenSSL and jq:

```sh
docker compose build app
bash qualify.sh
bash qualify.sh true
```

Each command uses a fresh Compose project and removes its own containers and
volumes. The checks inspect actual isolation and memory limits, use verified HTTPS,
and compare persisted state after replacing the app container. The default check
verifies that Site API routes and token storage are absent. The opt-in check
verifies draft creation, publication refusal, and token revocation.
