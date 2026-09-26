// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FhirResource, Patient } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { PatientPicker } from '../../../web/src/cards/PatientPicker';

afterEach(cleanup);

const HUGO: Patient = {
    resourceType: 'Patient',
    id: 'hugo-id',
    name: [{ use: 'official', given: ['Hugo'], family: 'History' }],
    birthDate: '1949-02-02',
    identifier: [{ type: { coding: [{ code: 'PT' }] }, value: '39' }],
};

function searchingClient(paths: string[], result: Result<Patient[]>): ApiClient {
    return {
        getResource: async () => ({ ok: false, error: { kind: 'network' } }),
        getJson: async () => ({ ok: false, error: { kind: 'network' } }),
        getBundle: async <T extends FhirResource>(path: string) => {
            paths.push(path);
            return result as Result<T[]>;
        },
    };
}

describe('PatientPicker', () => {
    it('searches FHIR Patient by name and lists matches with MRN and DOB', async () => {
        const paths: string[] = [];
        const selected: string[] = [];
        render(
            <PatientPicker
                client={searchingClient(paths, { ok: true, value: [HUGO] })}
                onSelect={(id) => selected.push(id)}
            />,
        );

        fireEvent.change(screen.getByLabelText('Find a patient'), { target: { value: 'Hugo' } });
        fireEvent.submit(screen.getByRole('search'));

        const match = await screen.findByRole('button', { name: 'Hugo History (39) DOB: 1949-02-02' });
        expect(paths).toEqual(['Patient?name=Hugo']);
        fireEvent.click(match);
        expect(selected).toEqual(['hugo-id']);
    });

    it('says when nothing matches, and says so differently when the search fails', async () => {
        render(<PatientPicker client={searchingClient([], { ok: true, value: [] })} onSelect={() => undefined} />);
        fireEvent.change(screen.getByLabelText('Find a patient'), { target: { value: 'Nobody' } });
        fireEvent.submit(screen.getByRole('search'));
        expect(await screen.findByText('No patients match "Nobody"')).toBeTruthy();
        cleanup();

        render(
            <PatientPicker
                client={searchingClient([], { ok: false, error: { kind: 'http', status: 502 } })}
                onSelect={() => undefined}
            />,
        );
        fireEvent.change(screen.getByLabelText('Find a patient'), { target: { value: 'Hugo' } });
        fireEvent.submit(screen.getByRole('search'));
        expect(await screen.findByText("Couldn't search for patients")).toBeTruthy();
    });
});
