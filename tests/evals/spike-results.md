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
