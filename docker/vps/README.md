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

Move the local seeded database to the droplet so the eval patients match.
**Do not use `/root/devtools restore` inside the flex container**: it assumes
the dev-container layout, and on 2026-09-16 it dropped the database and
truncated `sqlconf.php` before failing. Import the dump by hand instead.

```bash
# on your laptop: snapshot and export
openemr-cmd backup-snapshot baseline
openemr-cmd get-capsule baseline.tgz
scp baseline.tgz do-openemr:~/openemr/

# on the droplet: unpack and import the SQL dump
cd ~/openemr
docker compose cp baseline.tgz openemr:/tmp/baseline.tgz
docker compose exec -T openemr sh -c '
  cd /tmp && rm -rf cap && mkdir cap && tar -xzf baseline.tgz -C cap &&
  mariadb -h mysql -uopenemr -p"$MYSQL_PASS" openemr < cap/baseline/backup.sql &&
  mariadb -h mysql -uopenemr -p"$MYSQL_PASS" openemr -e "SELECT COUNT(*) patients FROM patient_data"'
```

The dump carries the module registration row and the `copilot_briefing_cache`
table too, so "Enable the module" below is already done. It also carries the
local `admin` password (`pass` on the dev stack); change it in the UI
afterwards if you set a different one.

If the database or DB user ever disappears (that is what the broken restore
did), recreate them as root before importing, then write `sqlconf.php` from
the droplet's own `.env` so no password is typed into a terminal:

```bash
docker compose exec -T openemr sh -c 'mariadb -h mysql -uroot -p"$MYSQL_ROOT_PASS" -e "
  CREATE DATABASE IF NOT EXISTS openemr CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS openemr@\"%\" IDENTIFIED BY \"$MYSQL_PASS\";
  GRANT ALL PRIVILEGES ON openemr.* TO openemr@\"%\"; FLUSH PRIVILEGES;"'
set -a; . ./.env; set +a
docker compose exec -T openemr sh -c 'cat > /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php && chown apache:apache /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php' <<EOF
<?php
\$host = "mysql"; \$port = "3306"; \$login = "openemr"; \$pass = "$MYSQL_PASS"; \$dbase = "openemr";
\$sqlconf = array(); global \$sqlconf;
\$sqlconf["host"] = \$host; \$sqlconf["port"] = \$port; \$sqlconf["login"] = \$login; \$sqlconf["pass"] = \$pass; \$sqlconf["dbase"] = \$dbase;
\$config = 1;
EOF
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

The container's code tree is an rsync of the clone with no `.git`, so
`git pull` does not work inside it. Two options:

```bash
# full: re-clone and rebuild (~10 min; composer + npm run again)
docker compose up -d --force-recreate openemr

# quick: copy just the changed files (no build step needed for PHP/JS in the module)
R=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot
docker compose cp path/to/File.php openemr:$R/src/File.php
```

Database and `sites/` live on named volumes and survive a recreate. The
web tree is mounted read-mostly for the `apache` user, so scripts that write
into it (e.g. `tests/evals/run.php`) should be pointed elsewhere:
`EVAL_RESULTS=/tmp/results.json php tests/evals/run.php --live`.

## Health

- `https://$DOMAIN/meta/health/readyz` — OpenEMR's own readiness.
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php`
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php`
