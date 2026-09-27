# Deploying the patient dashboard

The dashboard runs on the droplet next to OpenEMR, as its own compose project in `~/patient-dashboard`,
served by the droplet's Caddy at **https://dashboard.146-190-139-37.sslip.io**. It is kept apart from
`~/openemr`, whose compose file every OpenEMR deploy (`docker/vps/deploy.sh`) overwrites.

## Routine deploy

```bash
patient-dashboard/deploy/deploy.sh                          # copy the source, build on the droplet, start, wait for /healthz
npx playwright test -c playwright.deployed.config.ts        # from patient-dashboard/: smoke tests against the droplet
```

The smoke tests log in as the seeded non-admin user `tp-physician`; set `DEPLOYED_USER`, `DEPLOYED_PASS`
and `DEPLOYED_URL` for another site.

## One-time setup (done 2026-09-26)

1. **OpenEMR's OAuth address.** The global `site_addr_oath` must be OpenEMR's public URL, because it sets the
   expected `aud` and the token URL that client assertions are checked against. The droplet's value was a
   leftover `https://localhost:9300`; it was set to `https://146-190-139-37.sslip.io`, matching the compose
   file's `OPENEMR_SETTING_site_addr_oath`. `rest_system_scopes_api` must be 1 (it is).
2. **Clients**, registered from `clinical_copilot_week2/migration/spike/` into the gitignored `.env.droplet`:
    ```bash
    B=https://146-190-139-37.sslip.io
    OEMR_BASE=$B SPIKE_ENV_FILE=.env.droplet APP_REDIRECT=https://dashboard.146-190-139-37.sslip.io/auth/callback node register-client.mjs app
    OEMR_BASE=$B SPIKE_ENV_FILE=.env.droplet NAMES_KEY_FILE=certs/names-client-key.droplet.pem node register-client.mjs names
    OEMR_BASE=$B SPIKE_ENV_FILE=.env.droplet node register-client.mjs seed
    ```
    OpenEMR registers clients disabled; they were enabled with `UPDATE oauth_clients SET is_enabled = 1`
    for those three client ids (or use Admin > System > API Clients).
3. **`~/patient-dashboard/.env`** (mode 600, never committed):
    - `OEMR_BASE`, `OEMR_CLIENT_ID`, `OEMR_CLIENT_SECRET` and `OEMR_SCOPE` (the app client)
    - `PUBLIC_URL=https://dashboard.146-190-139-37.sslip.io`
    - `NAMES_CLIENT_ID`
    - the site settings, matched to the droplet's globals: `IDLE_TIMEOUT_SECONDS=7200`, `DATE_DISPLAY_FORMAT=0`, `AGE_DISPLAY_FORMAT=0`, `AGE_DISPLAY_LIMIT=3`, `ENCOUNTER_PAGE_SIZE=20`
4. **Names key:** `~/patient-dashboard/names-key/names-client-key.pem`, owned by uid 1000 (the image's `node`
   user) with mode 400.
5. **Caddy:** a site block in `/etc/caddy/Caddyfile` (the previous file is kept as `Caddyfile.bak-*`), then
   `systemctl reload caddy`:
    ```
    dashboard.146-190-139-37.sslip.io {
        reverse_proxy http://localhost:5180
    }
    ```

## Saved card layouts

Each user's collapsed cards are kept in `/app/data/card-settings.json` inside the container, on the named
volume `patient-dashboard_card-settings` (see `docker-compose.yml`). Redeploys keep it; only
`docker compose down -v` or `docker volume rm` would delete it. It holds user ids and card names, no patient data.

## Demo data

The synthetic test patients were seeded on the droplet with:

1. `fixtures/seed.mjs`, writing `fixture-ids.droplet.json`
2. `fixtures/seed-encounters.mjs`, writing `encounter-ids.droplet.json`
3. `fixtures/seed-demo.php`: the steps the API cannot do, found by patient name, run as apache inside the openemr container

All three are safe to re-run. See `clinical_copilot_week2/migration/TEST-PATIENTS.md`.
