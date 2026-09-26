import { describe, expect, it } from 'vitest';
import type { Bundle, CareTeam, Practitioner, RelatedPerson } from 'fhir/r4';
import { mapCareTeams, memberReferences } from '../../../web/src/mappers/careTeam';
import { personName } from '../../../web/src/mappers/people';
import { loadFixture } from '../fixtures/load';

// CareTeam bundles recorded from the dev stack with spike/fhir-get.mjs. TP-TYPICAL has one team,
// "practitioner", with Fred Stone (Practitioner, Nurse Practitioner, since 2026-09-26) and martha mom
// (RelatedPerson, bare SNOMED 407542009, no period). Both member reads are HTTP 404 (BM-028).
function recorded(key: string): CareTeam[] {
    const bundle = loadFixture<Bundle<CareTeam>>(`careteam-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

const PROVIDER = 'Practitioner/a2c6137a-eaba-455c-8e8b-16927c8a7a13';
const RELATED = 'RelatedPerson/a2d68932-2306-480a-8a60-b96fb5f5acb4';

describe('mapCareTeams', () => {
    it('unresolved member shows "Name unavailable" and is kept (BM-028)', () => {
        const [team] = mapCareTeams(recorded('TP-TYPICAL'), new Map());

        expect(team?.name).toBe('practitioner');
        expect(team?.status).toBe('Active');
        expect(team?.members.map((m) => [m.type, m.name])).toEqual([
            ['Provider', 'Name unavailable'],
            ['Related Person', 'Name unavailable'],
        ]);
    });

    it('uses a resolved name when the read succeeded', () => {
        const [team] = mapCareTeams(recorded('TP-TYPICAL'), new Map([[PROVIDER, 'Stone, Fred']]));

        expect(team?.members.map((m) => m.name)).toEqual(['Stone, Fred', 'Name unavailable']);
    });

    it('bare 407542009 role shows blank (BM-031)', () => {
        const [team] = mapCareTeams(recorded('TP-TYPICAL'), new Map());

        expect(team?.members.map((m) => m.role)).toEqual(['Nurse Practitioner', '']);
    });

    it('since is shown only when participant.period.start exists (BM-037)', () => {
        const [team] = mapCareTeams(recorded('TP-TYPICAL'), new Map());

        expect(team?.members.map((m) => m.since)).toEqual(['2026-09-26', '']);
    });

    it('a participant with no member reference is still shown', () => {
        const team: CareTeam = { resourceType: 'CareTeam', id: 't', participant: [{ role: [{ text: 'Chaplain' }] }] };

        expect(mapCareTeams([team], new Map())[0]?.members).toEqual([
            { key: '0', type: 'Unknown', name: 'Name unavailable', role: 'Chaplain', facility: '', since: '' },
        ]);
    });

    it('TP-EMPTY has no teams', () => {
        expect(mapCareTeams(recorded('TP-EMPTY'), new Map())).toEqual([]);
    });
});

describe('memberReferences', () => {
    it('lists each readable member once', () => {
        const teams = [...recorded('TP-TYPICAL'), ...recorded('TP-TYPICAL')];

        expect(memberReferences(teams)).toEqual([PROVIDER, RELATED]);
    });

    it('never returns a reference the proxy should not be asked for', () => {
        const team: CareTeam = {
            resourceType: 'CareTeam',
            participant: [
                { member: { reference: 'https://elsewhere.example/Practitioner/1' } },
                { member: { reference: 'Practitioner/../Patient/x' } },
                { member: { reference: 'Practitioner/..' } },
                { member: { reference: 'Organization/1' } },
                { member: { reference: 'Practitioner/ok-1' } },
            ],
        };

        expect(memberReferences([team])).toEqual(['Practitioner/ok-1']);
    });
});

describe('personName', () => {
    it('formats a provider as "Last, First" like the old card', () => {
        const practitioner: Practitioner = {
            resourceType: 'Practitioner',
            name: [{ family: 'Stone', given: ['Fred'] }],
        };
        expect(personName(practitioner)).toBe('Stone, Fred');
    });

    it('formats a related person as written, preferring name.text', () => {
        const person = (name: RelatedPerson['name']): RelatedPerson => ({
            resourceType: 'RelatedPerson',
            patient: { reference: 'Patient/p1' },
            name,
        });
        expect(personName(person([{ text: 'martha mom', family: 'mom', given: ['martha'] }]))).toBe('martha mom');
        expect(personName(person([{ family: 'mom', given: ['martha'] }]))).toBe('martha mom');
        expect(personName(person([]))).toBe('Name unavailable');
    });
});
