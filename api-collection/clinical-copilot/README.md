# Clinical Co-Pilot API collection (Bruno)

Runnable requests for every Co-Pilot endpoint. Works in the
[Bruno](https://www.usebruno.com/) desktop app or its CLI; no source reading
needed.

## Run

Desktop app: open this folder as a collection, pick the `vps` or `local`
environment, run the collection (requests execute in order).

CLI:

```bash
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
| 04 | Login | Authenticates; 302 = success |
| 05 | Open chart | Sets the session patient (`pid` env var); captures the CSRF token from the panel |
| 06 | Brief | Fact table + verified narration + `facts_hash` + `correlation_id` |
| 07 | Ask | Verified follow-up (`cited` or `not_in_facts`) |
| 08 | Ask with stale hash | `chart_changed: true`, fresh facts, no answer |
| 09 | Brief without CSRF | Refused |

To see the authorization refusal, set `user`/`password` to
`receptionist`/`receptionist` in the environment and run 03-06: request 06
returns 403 before any chart row is read.

Environments: `environments/vps.bru` (deployed), `environments/local.bru`
(dev stack at http://localhost:8300). `pid` defaults to 15, a seed patient
with an abnormal lab since the prior visit.
