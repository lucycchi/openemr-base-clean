# VPS deployment (flex image, single box)

Deploys this fork, including its custom modules, on one VPS. This is a
dev-flavored deploy chosen for the Wednesday gate; a custom-built image is
tracked in `TODOS.md`.

Why not `docker/production/docker-compose.yml`: it pulls the upstream
`openemr/openemr:latest` image, which does not contain this fork. The flex
image clones `FLEX_REPOSITORY` at container start instead.

## Prerequisites

- A VPS with 4 GB RAM, Docker Engine, and the compose plugin
  (DigitalOcean, Hetzner, Lightsail all work).
- A DNS A record for `DOMAIN` pointing at the VPS.
- The fork repo reachable by the VPS (public, or set `FLEX_REPOSITORY` to a
  tokenized HTTPS URL).

## Steps

```bash
mkdir -p ~/openemr && cd ~/openemr
curl -O https://raw.githubusercontent.com/lucycchi/openemr-base-clean/audit/docker/vps/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/lucycchi/openemr-base-clean/audit/docker/vps/.env.example
$EDITOR .env          # DOMAIN, passwords, OPENAI_API_KEY, LANGFUSE_* keys
docker compose up -d
docker compose logs -f openemr   # first boot clones, composer installs, npm builds: ~10 min
```

The image serves HTTPS on 443 with a self-signed certificate. For a real
certificate, change the port mappings to `8080:80` / `8443:443`, install
Caddy on the host, and run `caddy reverse-proxy --from $DOMAIN --to
localhost:8080`. Caddy obtains and renews the Let's Encrypt certificate.
The flex entrypoint itself has no ACME support.

## Seed the same patients as local

The local dev stack's seeded database can be moved as a capsule so the 10
named eval patients are identical on both boxes:

```bash
# on your laptop
openemr-cmd backup-snapshot baseline
openemr-cmd get-capsule baseline.tgz
scp baseline.tgz vps:~/openemr/

# on the VPS
docker compose cp baseline.tgz openemr:/root/
docker compose exec openemr /root/devtools put-capsule /root/baseline.tgz
docker compose exec openemr /root/devtools restore-snapshot baseline
```

## Enable the module

Either Administration → Modules → Manage Modules → Unregistered → register
and enable `oe-module-clinical-copilot`, or from SQL (the same rows the
Module Manager writes):

```bash
M=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot/sql
docker compose exec openemr sh -c "grep -v '^#' $M/install.sql | mariadb -h mysql -uopenemr -p\$MYSQL_PASS openemr && mariadb -h mysql -uopenemr -p\$MYSQL_PASS openemr < $M/register.sql"
```

## Redeploy after a push

```bash
docker compose up -d --force-recreate openemr
```

The container re-clones the branch on recreate. Database and `sites/` live
on named volumes and survive.

## Health

- `https://$DOMAIN/meta/health/readyz` — OpenEMR's own readiness.
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php`
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php`
