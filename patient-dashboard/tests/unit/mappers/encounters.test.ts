import { describe, expect, it } from 'vitest';
import type { Bundle, Encounter } from 'fhir/r4';
import { mapEncounters, providerReferences } from '../../../web/src/mappers/encounters';
import { loadFixture } from '../fixtures/load';

// Encounter bundles recorded from the dev stack with spike/fhir-get.mjs. TP-TYPICAL has three visits;
// the 2026-03-02 one was with Fred Stone, who has no NPI, so FHIR sends no participant at all (BM-032).
// The other two carry Donna Lee as primary performer. TP-LONG has 30 visits, returned oldest first.
function recorded(key: string): Encounter[] {
    const bundle = loadFixture<Bundle<Encounter>>(`encounters-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

const DONNA = 'Practitioner/a2c6137a-eabd-4143-9baa-b3c606366a5f';

describe('mapEncounters', () => {
    it('newest first (BM-034)', () => {
        const dates = mapEncounters(recorded('TP-LONG'), new Map()).map((e) => e.date);

        expect(dates).toHaveLength(30);
        expect(dates[0]).toBe('2026-09-01');
        expect(dates).toEqual([...dates].sort().reverse());
    });

    it('shows date, reason and provider', () => {
        const rows = mapEncounters(recorded('TP-TYPICAL'), new Map([[DONNA, 'Lee, Donna']]));

        expect(rows.map(({ date, reason, provider }) => [date, reason, provider])).toEqual([
            ['2026-08-14', 'Diabetes review', 'Lee, Donna'],
            ['2026-03-02', 'Blood pressure check', 'Name unavailable'],
            ['2025-11-20', 'Annual physical', 'Lee, Donna'],
        ]);
    });

    it('missing participant shows "Name unavailable" (BM-032)', () => {
        const rows = mapEncounters(recorded('TP-TYPICAL'), new Map([[DONNA, 'Lee, Donna']]));

        expect(rows.find((e) => e.date === '2026-03-02')?.provider).toBe('Name unavailable');
    });

    it('a provider whose read failed also shows "Name unavailable"', () => {
        const rows = mapEncounters(recorded('TP-TYPICAL'), new Map());

        expect(rows.map((e) => e.provider)).toEqual(['Name unavailable', 'Name unavailable', 'Name unavailable']);
    });

    it('keeps the date as written, so a midnight visit never moves to the day before', () => {
        const encounter: Encounter = {
            resourceType: 'Encounter',
            status: 'finished',
            class: { code: 'AMB' },
            period: { start: '2026-08-14T00:00:00+00:00' },
        };
        expect(mapEncounters([encounter], new Map())[0]?.date).toBe('2026-08-14');
    });

    it('an encounter with no start date goes last, and no reason is blank', () => {
        const bare: Encounter = { resourceType: 'Encounter', id: 'x', status: 'finished', class: { code: 'AMB' } };
        const rows = mapEncounters([bare, ...recorded('TP-HISTORY')], new Map());

        expect(rows.map((e) => [e.date, e.reason])).toEqual([
            ['2024-10-26', 'Same-day follow-up'],
            ['2024-10-26', 'Cough and fever'],
            ['', ''],
        ]);
    });

    it("visits with the same date keep the API order, which is the old page's id descending", () => {
        // FHIR sorts by eid descending (EncounterService.php:322); the old page by date, then id descending.
        const reasons = mapEncounters(recorded('TP-HISTORY'), new Map()).map((e) => e.reason);

        expect(reasons).toEqual(['Same-day follow-up', 'Cough and fever']);
    });
});

describe('mapEncounters provider and ordering', () => {
    const visit = (id: string, start: string, participant?: Encounter['participant']): Encounter => ({
        resourceType: 'Encounter',
        id,
        status: 'finished',
        class: { code: 'AMB' },
        period: { start },
        reasonCode: [{ text: id }],
        ...(participant === undefined ? {} : { participant }),
    });

    it('only the primary performer is the provider; a referrer is never shown in its place (BM-032)', () => {
        const referredOnly = visit('referred', '2026-01-01T09:00:00+00:00', [
            {
                type: [{ coding: [{ code: 'REF', display: 'referrer' }] }],
                individual: { reference: 'Practitioner/ref1' },
            },
        ]);
        const names = new Map([['Practitioner/ref1', 'Referrer, Rita']]);

        expect(mapEncounters([referredOnly], names)[0]?.provider).toBe('Name unavailable');
        expect(providerReferences([referredOnly])).toEqual([]);
    });

    it('same-day visits are ordered by time, latest first, as the old page sorts by date and time', () => {
        const morning = visit('morning', '2026-05-05T08:00:00+00:00');
        const evening = visit('evening', '2026-05-05T18:30:00+00:00');

        expect(mapEncounters([evening, morning], new Map()).map((e) => e.reason)).toEqual(['evening', 'morning']);
        expect(mapEncounters([morning, evening], new Map()).map((e) => e.reason)).toEqual(['evening', 'morning']);
    });
});

describe('providerReferences', () => {
    it('lists each provider once, and only readable Practitioner references', () => {
        const odd: Encounter = {
            resourceType: 'Encounter',
            status: 'finished',
            class: { code: 'AMB' },
            participant: [
                { individual: { reference: 'RelatedPerson/r1' } },
                { individual: { reference: 'Practitioner/..' } },
            ],
        };
        expect(providerReferences([...recorded('TP-TYPICAL'), odd])).toEqual([DONNA]);
    });
});
