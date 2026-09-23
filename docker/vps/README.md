# VPS deployment (flex image, single box)

Week 2 adds a second service, `copilot-sidecar`, built on the VPS by `deploy.sh` from the module's `sidecar/` directory; see [clinical_copilot_week2/W2_ARCHITECTURE.md](../../clinical_copilot_week2/W2_ARCHITECTURE.md).

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
- A read-only GitLab deploy key so the VPS can clone the private project
  over SSH (HTTPS git auth on this GitLab instance returns 401 for deploy
  tokens and PATs alike, so tokens are not an option). See "Deploy key".

## Steps

```bash
mkdir -p ~/openemr && cd ~/openemr
scp docker/vps/docker-compose.yml docker/vps/.env.example do-openemr:~/openemr/   # from your laptop
mv .env.example .env
$EDITOR .env          # DOMAIN, passwords, OPENAI_API_KEY, LANGFUSE_* keys
# then set up the deploy key (next section) before the first `up`
docker compose up -d
docker compose logs -f openemr   # first boot clones, composer installs, npm builds: ~10 min
```

The deploy job in `.gitlab-ci.yml` refuses to run if `.env` does not point at
GitLab, so a stale GitHub URL cannot be silently redeployed.

## Deploy key

The container has no ssh client of its own; the compose `command` installs
one at start and `GIT_SSH_COMMAND` points it at a key bind-mounted read-only
from `./deploy-ssh`:

```bash
cd ~/openemr && mkdir -p deploy-ssh && chmod 700 deploy-ssh
ssh-keygen -t ed25519 -N "" -C openemr-vps-deploy -f deploy-ssh/id_ed25519
ssh-keyscan -p 22022 labs.gauntletai.com > deploy-ssh/known_hosts
cat deploy-ssh/id_ed25519.pub   # add in GitLab: Settings → Repository → Deploy keys, read-only
# verify before starting the stack:
GIT_SSH_COMMAND="ssh -i deploy-ssh/id_ed25519 -o UserKnownHostsFile=deploy-ssh/known_hosts" \
  git ls-remote ssh://git@labs.gauntletai.com:22022/lucychi/openemr.git refs/heads/audit
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

## Morning pre-warm (available, not turned on)

The Co-Pilot can pre-generate briefings for the day's scheduled patients
before clinic opens, so the first chart open is a cache hit instead of a
3-6 s model call. Design: [`docs/designs/copilot-morning-prewarm.md`](../../docs/designs/copilot-morning-prewarm.md).

**It is switched off on the droplet.** Nothing in `deploy.sh` or the compose
files installs the cron, and the command itself is inert unless enabled:

- `COPILOT_PREWARM_ENABLED` is unset. With it unset, `copilot:prewarm`
  prints "pre-warm disabled on this site" and exits 0 without reading the
  schedule, writing the cache or calling OpenAI.
- No crontab entry exists on the host.

To run it once by hand (bypasses the switch, one run only):

```bash
docker compose exec -u apache openemr php /var/www/localhost/htdocs/openemr/bin/console copilot:prewarm --site=default --date=today --force
```

To turn it on for good: set `COPILOT_PREWARM_ENABLED=1` in the openemr
service environment, set `gbl_time_zone` in Administration → Globals so
"start of today" is clinic-local, then add to the host crontab (runs as the
web user; the CLI refuses root):

```
0 6 * * 1-5 cd /path/to/docker/vps && docker compose exec -T -u apache openemr php /var/www/localhost/htdocs/openemr/bin/console copilot:prewarm --site=default --date=today >> /var/log/copilot-prewarm.log 2>&1
```

Remove the crontab line and unset the variable to turn it off again.

## Redeploy after a push

GitLab CI is not available to student accounts on labs.gauntletai.com (as
of 2026-09-16: pipeline creation is refused for personal projects and the
group forbids Developers' pushes). Until that changes, deploy from your
laptop with the script that mirrors the pipeline's deploy job:

```bash
docker/vps/deploy.sh            # push audit to GitLab, recreate, wait for health
docker/vps/deploy.sh --no-push  # redeploy whatever is already on gitlab/audit
```

When CI is granted, pushing to `audit` on GitLab runs `.gitlab-ci.yml`: the `check` stage lints
the module and this compose file, then `deploy-vps` SSHes to the droplet,
runs `docker compose up -d --force-recreate openemr`, and polls
`/meta/health/readyz` plus the module's `health.php` / `ready.php` for up to
20 minutes. Deploys are serialized (`resource_group: production`). The site
is down for ~10 minutes while composer + npm rebuild.

One-time setup for the pipeline (GitLab → Settings → CI/CD → Variables):

| Variable | Type | Value |
|---|---|---|
| `DEPLOY_HOST` | Variable | droplet IP |
| `DEPLOY_USER` | Variable | `root` |
| `DEPLOY_SSH_KEY` | File | a private key whose public half is in the droplet's `~/.ssh/authorized_keys` (generate a dedicated one: `ssh-keygen -t ed25519 -f gitlab-deploy -N ""`) |
| `DEPLOY_KNOWN_HOSTS` | File | `ssh-keyscan -H <droplet IP>` output |
| `DEPLOY_DOMAIN` | Variable | public hostname (no scheme) |

Mark all of them protected; `audit` must be a protected branch for protected
variables to be exposed. The project also needs a runner (shared runners
enabled under Settings → CI/CD → Runners, or a project runner).

To redeploy by hand instead, the container's code tree is an rsync of the
clone with no `.git`, so `git pull` does not work inside it. Two options:

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

### Schema changes need one more step

A recreate ships new code but never re-runs the module's `sql/install.sql`:
OpenEMR runs it once, on first enable. When a deploy adds a table (the
0.1.1 deploy on 2026-09-18 added `copilot_prewarm` and every chart open
returned 500 until it was created by hand), apply the module's upgrade file
after the container is healthy. Either:

- **Module Manager:** Modules → Manage Modules → Clinical Co-Pilot → *Upgrade*.
  It applies every `sql/<old>-to-<new>_upgrade.sql` whose version is at or
  above the recorded `sql_version`, then records the new version. Or
- **By hand** (no UI round-trip; the same statements, minus OpenEMR's
  `#IfNotTable` directives, which plain SQL does not understand):

  ```bash
  M=/var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-clinical-copilot
  docker compose exec -T openemr grep -v '^#If\|^#EndIf' $M/sql/0_1_0-to-0_1_1_upgrade.sql \
    | docker compose exec -T mysql sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" openemr'
  ```

Every statement is `CREATE TABLE IF NOT EXISTS`, so running it twice is
harmless. The panel itself no longer hard-fails on the missing table (the
warm lookup logs a warning and skips scoring), but the pre-warm command and
`prewarm.php` do need it.

## Health

- `https://$DOMAIN/meta/health/readyz` — OpenEMR's own readiness.
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php`
- `https://$DOMAIN/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php`
