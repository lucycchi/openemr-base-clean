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

## Prescription editing (ARC-06, set up 2026-09-27)

- **Scope:** the app client asks for `user/prescription.crds` as well as the read scopes. It was re-registered on the droplet with `register-client.mjs app --replace`, the new client was enabled (`UPDATE oauth_clients SET is_enabled = 1 ...`), the old one disabled, and the new id, secret and scope copied into `~/patient-dashboard/.env`.
- **Write-test patient:** Rita RxEdit (TP-RXEDIT, pid 38 on the droplet) is the only patient the smoke test writes to. Create her with `OEMR_BASE=https://146-190-139-37.sslip.io SPIKE_ENV_FILE=.env.droplet FIXTURE_IDS_FILE=fixture-ids.droplet.json node ../fixtures/seed-rxedit.mjs`, from `clinical_copilot_week2/migration/spike/`. It is safe to re-run.
- **Known effect:** each smoke run adds and discontinues one prescription for her. OpenEMR's own screens show API-discontinued prescriptions as active (BM-067), so her old-dashboard prescription list grows. She is a test-only patient.
- **"Edit in OpenEMR":** the link goes to `/openemr/patient/<uuid>`, which redirects to OpenEMR's chart page (`OEMR_PUBLIC_URL`, default `OEMR_BASE`). OpenEMR's session cookie is `SameSite=Strict`, so the link finds an existing OpenEMR sign-in only when the dashboard and OpenEMR share a site, as `dashboard.146-190-139-37.sslip.io` and `146-190-139-37.sslip.io` do. Elsewhere the user signs in to OpenEMR first.

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
