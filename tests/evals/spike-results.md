# Runtime validation spike — 2026-09-15

Script: `tmp/spike.php`, `tmp/spike_b.php`, run in the dev container as `apache`.
Seed: 30 Synthea patients, 1,517 encounters, 5,605 lab results, 234 prescriptions, 47 allergies.

## Timing (all five services, unbounded, per patient)

| pid | encounters | enc ms | rx ms | allergy ms | lab ms/rows | cond ms | total ms |
|---|---|---|---|---|---|---|---|
| 28 | 138 | 198 | 29 | 2 | 21 / 1162 | 29 | 278 |
| 23 | 135 | 208 | 20 | 3 | 2 / 35 | 14 | 246 |
| 4 | 108 | 150 | 19 | 2 | 2 / 91 | 18 | 191 |
| 17 | 106 | 148 | 25 | 2 | 6 / 404 | 17 | 198 |
| 19 | 78 | 108 | 29 | 2 | 13 / 936 | 19 | 171 |

Conclusion: fact assembly is under 0.3 s for the worst seed patient. The 5 s
budget is dominated by the OpenAI call. `EncounterService::search` is the
largest cost (~1.5 ms/encounter); date-bounding it (9A) cuts most of that.

## Authorization

`AclMain::aclCheckCore($section, $value, $user)` with `$user` passed explicitly:

| user | patients/med | encounters/notes | sensitivities/normal | admin/super |
|---|---|---|---|---|
| admin | Y | Y | Y | Y |
| physician | Y | Y | Y | n |
| clinician | Y | Y | Y | n |
| receptionist | n | n | n | n |
| accountant | n | n | n | n |
| portal-user | n | n | n | n |

The tool layer passes the session user explicitly. Negative-test users exist
in the seed (receptionist, accountant).

## Data quality

- Encounter dates: 0 null/zero, 0 future. 27/30 patients have ≥2 encounters.
- Sensitivity: 3 encounters, value `normal` only.
- Lab `abnormal`: 0 of 5,605 set. Lab `range`: empty on all rows. 5,058 results
  are numeric. Decision D27: compute abnormal from a curated reference-range
  table keyed by LOINC; emit delta-vs-prior facts regardless.
- Allergy vs drug overlap: 0 with first-word substring. Allergy titles are
  substances (`penicillin`, `Bee venom`, `Mold`); drugs are RxNorm strings.
  Decision D18: seed one guaranteed hit (e.g. a penicillin-class prescription
  for a penicillin-allergy patient) in `tests/evals/seed/`.

## FactAssembler smoke (2026-09-15, after T3)

`tests/evals/spike/assemble_smoke.php`: real `OpenEmrChartSource` +
`AclAuthorization`, 10 busiest patients, latest encounter as the current visit.

- admin: 6-23 ms per patient; receptionist: refused before any chart read.
- Abnormal labs fire on real seed data via the LOINC reference table (no
  seeding needed): pid 4 Hemoglobin 11.45 g/dL below range; pid 15
  Triglyceride 154.74 mg/dL above range. Deltas fire for repeated tests.
- Data gotchas found: `procedure_result.date` and `prescriptions.start_date`
  are zero dates, not NULL; the adapter uses NULLIF chains
  (`date_report`, `date_collected`, `date_ordered`; `date_added`).
- The service layer is not used by the adapter: its rows lack prescription
  ids/start dates and lab encounter links. Direct patient-scoped queries via
  `QueryUtils` instead; no identifiers selected.
- Between visits, Synthea charts have no events, so with no current
  encounter the diff is empty. The demo flow should open a chart with
  today's encounter created (front-desk check-in), which is the real flow.
- `lab_delta` is noisy (pid 15: 20 deltas, some tiny). Not must-surface; the
  model chooses. A minimum-change threshold is a candidate refinement.
