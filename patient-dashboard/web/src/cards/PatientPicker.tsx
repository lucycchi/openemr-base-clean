import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Patient } from 'fhir/r4';
import type { ApiClient } from '../api/client';

interface Match {
    id: string;
    label: string;
}

type SearchState =
    | { status: 'idle' }
    | { status: 'searching' }
    | { status: 'error' }
    | { status: 'done'; query: string; matches: Match[] };

function describePatient(patient: Patient): Match {
    const name = patient.name?.find((n) => n.use === 'official') ?? patient.name?.[0];
    const display = [...(name?.given ?? []), name?.family ?? ''].filter((part) => part !== '').join(' ');
    const mrn = patient.identifier?.find((identifier) => identifier.type?.coding?.some((c) => c.code === 'PT'))?.value;
    const parts = [display === '' ? 'Name not recorded' : display];
    if (mrn !== undefined) {
        parts.push(`(${mrn})`);
    }
    if (patient.birthDate !== undefined) {
        parts.push(`DOB: ${patient.birthDate}`);
    }
    return { id: patient.id ?? '', label: parts.join(' ') };
}

/** Finds a patient by name (FHIR Patient?name=) and hands the chosen id to onSelect. */
export function PatientPicker({ client, onSelect }: { client: ApiClient; onSelect: (patientId: string) => void }) {
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState<SearchState>({ status: 'idle' });

    async function runSearch(event: FormEvent) {
        event.preventDefault();
        const text = query.trim();
        if (text === '') {
            return;
        }
        setSearch({ status: 'searching' });
        const result = await client.getBundle<Patient>(`Patient?name=${encodeURIComponent(text)}`);
        setSearch(
            result.ok
                ? { status: 'done', query: text, matches: result.value.map(describePatient) }
                : { status: 'error' },
        );
    }

    return (
        <form role="search" onSubmit={(event) => void runSearch(event)}>
            <label>
                Find a patient{' '}
                <input value={query} onChange={(event) => setQuery(event.target.value)} autoComplete="off" />
            </label>{' '}
            <button type="submit">Search</button>
            {search.status === 'searching' && <p>Searching…</p>}
            {search.status === 'error' && <p role="alert">Couldn't search for patients</p>}
            {search.status === 'done' && search.matches.length === 0 && <p>No patients match "{search.query}"</p>}
            {search.status === 'done' && search.matches.length > 0 && (
                <ul>
                    {search.matches.map((match) => (
                        <li key={match.id}>
                            <button type="button" onClick={() => onSelect(match.id)}>
                                {match.label}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </form>
    );
}
