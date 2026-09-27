import { readFileSync } from 'node:fs';

export type FixtureKey =
    'TP-TYPICAL' | 'TP-EMPTY' | 'TP-NKA' | 'TP-HISTORY' | 'TP-DECEASED' | 'TP-LONG' | 'TP-ESCAPING' | 'TP-RXEDIT';

interface FixturePatient {
    pid: number;
    puuid: string;
    fhirId: string;
}

const FIXTURE_FILE = new URL('../../../clinical_copilot_week2/migration/fixtures/fixture-ids.json', import.meta.url);

/** The synthetic test patients seeded on the dev stack (see TEST-PATIENTS.md). */
export function fixture(key: FixtureKey): FixturePatient {
    const data = JSON.parse(readFileSync(FIXTURE_FILE, 'utf8')) as { patients: Record<string, FixturePatient> };
    const patient = data.patients[key];
    if (patient === undefined) {
        throw new Error(`Fixture ${key} is missing from fixture-ids.json`);
    }
    return patient;
}
