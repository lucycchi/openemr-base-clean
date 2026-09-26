import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// Guards BM-029: OpenEMR answers `_include` on CareTeam with an empty Bundle, which would read as
// "no care team". The proxy strips it, so both searches must return TP-TYPICAL's one team.
test('CareTeam search for TP-TYPICAL returns exactly 1 CareTeam', async ({ page }) => {
    await logInThroughOpenEmr(page);
    const patientId = fixture('TP-TYPICAL').fhirId;

    for (const query of [`patient=${patientId}`, `patient=${patientId}&_include=CareTeam:participant`]) {
        const res = await page.request.get(`/api/fhir/CareTeam?${query}`);
        expect(res.status(), query).toBe(200);
        const bundle = (await res.json()) as { resourceType: string; entry?: { resource: { resourceType: string } }[] };
        expect(bundle.resourceType).toBe('Bundle');
        expect(
            bundle.entry?.map((e) => e.resource.resourceType),
            query,
        ).toEqual(['CareTeam']);
    }
});
