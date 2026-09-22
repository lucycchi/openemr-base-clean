# Clinical Co-Pilot API collection (Bruno)

Runnable requests for every Co-Pilot endpoint. Works in the
[Bruno](https://www.usebruno.com/) desktop app or its CLI; no source reading
needed.

## Run

Desktop app: open this folder as a collection, pick the `vps` or `local`
environment, run the collection (requests execute in order).

CLI:

```bash
cd clinical_copilot/api-collection
npx --yes @usebruno/cli@2 run --disable-cookies --env vps
# or against the local dev stack from the host:
npx --yes @usebruno/cli@2 run --disable-cookies --env local
```

`--disable-cookies` matters: the collection captures OpenEMR's session
cookie itself (requests 03 and 04) and sends it explicitly, so it behaves the
same in the app and the CLI.

## Requests

| # | Request | What it proves |
|---|---|---|
| 01 | Health | Liveness, no auth |
| 02 | Ready | Real dependency probes: database, OpenAI, Langfuse (degraded only); cached 60 s |
| 03 | Login page | Starts the OpenEMR session |
| 04 | Login | Authenticates as `user`; 302 = success |
| 05 | Open chart | Sets the session patient (`pid`); captures the CSRF token from the panel |
| 06 | Brief | Fact table + verified narration + `facts_hash` + `correlation_id` |
| 07 | Ask | Cited follow-up, every sentence verified |
| 08 | Ask for arithmetic | Withheld: the model declines (`not_in_facts`) or the verifier strips the computed number (eval case 11) |
| 09 | Ask outside the window | `not_in_facts`, nothing invented (eval case 10) |
| 10 | Ask with stale hash | `chart_changed: true`, fresh facts, no answer |
| 11 | Brief again | Cache hit: `from_cache: true`, zero tokens, no model call |
| 12 | Brief without CSRF | 403 |
| 13–15 | Restricted user session | New session as `restrictedUser`, login, open the same chart |
| 16 | Restricted user brief | 403 `You are not authorized to view this chart` — ACL enforced in the tool layer, not the menu |
| 17 | Alert webhook | Simulates a Langfuse alert firing at `alerts.php`; 200, and a `clinical-copilot-alert` audit row (see [ALERTS.md](../ALERTS.md)). Needs `alertToken` |
| 18 | Alert webhook, wrong token | 401; nothing recorded |
| 19 | Alert webhook, token in query string | 401 even with the right secret; header only |

Every workflow in [USING_CLINICAL_COPILOT.md](../USING_CLINICAL_COPILOT.md)
has a request here; nothing needs a manual edit between runs.

## Environments

| File | Target | Notes |
|---|---|---|
| `environments/local.bru` | http://localhost:8300 (dev stack) | `admin`/`pass`, `receptionist`/`receptionist` as shipped with the demo data |
| `environments/vps.bru` | https://146-190-139-37.sslip.io | Set `password` to the deployed admin password before running 03+. 01–02 need no credentials. |

`alertToken` must equal the target's `ALERT_WEBHOOK_SECRET` for request 17;
pass it on the CLI rather than committing it:
`npx --yes @usebruno/cli@2 run --disable-cookies --env local --env-var alertToken=<secret>`.

`pid` defaults to 15, the seed patient with the richest chart (abnormal
labs and deltas since the prior visit). Any of pids 1–30 works.

## Verified

Last full run against the local dev stack: 18/18 requests, 42/42
assertions, ~10 s. Requests 01–02 also verified against the deployed VPS.
