# Demo walkthrough: modernized patient dashboard

A script for a 4–5 minute video. Every patient is synthetic (`TEST-PATIENTS.md`). Run it on the development-easy stack with the seeds applied (`fixtures/seed-parity-gaps.php`), or on the droplet once ARC-05 has deployed it.

**Before recording**
- Start the dashboard: `cd patient-dashboard && npm run build && npm start` (http://localhost:5180).
- Open the old dashboard in a second tab (http://localhost:8300, admin / pass) so the two can be compared on screen.
- Log in as `tp-physician` / `tp-physician-pass`, a non-admin user in OpenEMR's Physicians group, to show what a clinician sees.

## Login

1. Open http://localhost:5180 and click **Log in with OpenEMR**.
2. OpenEMR's own login page opens; sign in as `tp-physician`, then approve the requested access on OpenEMR's consent page.
3. Back on the dashboard, point out what the viewer cannot see: the browser holds only an HttpOnly session cookie. The OAuth tokens stay in the Node BFF, because OpenEMR's API cannot be called from a browser page (its CORS preflight answers 404, BM-001).

## Patient chart (TP-TYPICAL)

1. Search **Typical** and open **Tessa Typical**.
2. **Header:** name, MRN, DOB and age, plus sex and status ("Active"). The old identity bar never showed sex or status.
3. **Allergies:** Penicillin with its reaction and "Low risk"; Peanuts with no empty brackets.
4. **Medical Problems:** three problems in onset order, including Type 2 diabetes, which is linked to two visits. FHIR drops a visit-linked problem from its problem list, so the card reads the old card's own list instead (BM-051).
5. **Medications:** four entries in the old order, including Lisinopril, which ends next year. FHIR calls it "completed"; the old rule keeps it until the end date passes (BM-044).
6. **Prescriptions:** Amlodipine and Omeprazole, newest first. Refills say "Not available" because FHIR always sends 0 (BM-041).
7. **Care Team:** a Nurse Practitioner at Great Clinic and a related person. Both names read "Name unavailable": Fred Stone has no NPI and OpenEMR's API does not serve related persons (BM-028), while the old card reads names straight from the database. Also point out the note that OpenEMR's API does not report removed members (BM-053).
8. **Encounter history:** three visits, newest first. Donna Lee is named even for this non-admin user, through the server-only names client (BM-048). Fred Stone shows "Name unavailable", because OpenEMR's API only serves providers with an NPI (BM-032).
9. Put the old dashboard beside it for the same patient to show the lists match.

## Deceased patient (TP-DECEASED)

1. Open **Dora Deceased**.
2. The header reads **Deceased (2025-11-02)** and "Age at death: 93". FHIR's `Patient.active` is always true, so the status comes from the death date instead (BM-005).

## Long lists (TP-LONG)

1. Open **Lena Quinn LongLists**.
2. Scroll the 60 problems and 60 medications: oldest start date first, as on the old cards.
3. **Allergies:** "Long-list allergen 01" is highlighted as high risk. Allergen 02, marked resolved, is hidden, and allergen 03, which ends in 2027, is shown: the old rule, applied exactly (BM-047, BM-016).
4. **Encounter history:** the 20 most recent visits, then **Show all 30** (BM-034, from the site's encounter page size).

## Patient switch

1. With Lena open, search **History** and open **Hugo History**.
2. Every card clears and reloads for Hugo; nothing of Lena's appears while his data loads. This is checked by a test that samples the page on every animation frame.
3. Point out Hugo's two visits on 2024-10-26, in the same order as the old Visit History page, and the Fee Sheet problem that FHIR leaves out but the card shows (BM-051).

## Exceptions

Explain one approved difference on camera. Suggested: **allergy severity (BM-011).** The old card shows OpenEMR's eight severities ("Moderate"). FHIR reduces them to a risk level, so the new card says "Low risk" or "High risk" and keeps the high-risk highlight. I kept FHIR's value rather than read a second source, because the risk level still drives the warning a clinician needs.

The full list of approved differences, each with its reason, is in `BUGS-MITIGATIONS.md` and in the results table of `PATIENT_DASHBOARD_MIGRATION.md`.

## Close

- **Idle sign-out:** the page signs out after the site's idle timeout, as OpenEMR does.
- **Evidence:** every section passes a parity test against the running old dashboard (9 of 9), with 245 unit tests and 26 end-to-end tests.
