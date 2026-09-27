/**
 * The patient search box. In: the connection used to ask OpenEMR's FHIR API for data, and what to do
 * once a patient is chosen. Out: a search box and button; after a search, a list of matching patients
 * (name, record number, date of birth), each a button that opens that patient's dashboard.
 * The markup looks like HTML; see AllergiesCard.tsx for a short reading guide.
 */
import { useState } from 'react';
import type { FormEvent } from 'react';
import type { Patient } from 'fhir/r4';
import type { ApiClient } from '../api/client';

/** One search result: the patient's id and the text shown on their button. */
interface Match {
    id: string;
    label: string;
}

/**
 * Where the search is up to. The `|` lines list the only possible states: not yet searched,
 * searching, failed, or finished (with the words searched for and the matches found).
 */
type SearchState =
    | { status: 'idle' }
    | { status: 'searching' }
    | { status: 'error' }
    | { status: 'done'; query: string; matches: Match[] };

/** Builds a result's label, e.g. "Jane Doe (36) DOB: 1958-03-14", leaving out any part not recorded. */
function describePatient(patient: Patient): Match {
    // The official name if one is marked, otherwise the first name recorded.
    const name = patient.name?.find((n) => n.use === 'official') ?? patient.name?.[0];
    // Given names then family name, blanks dropped, joined with spaces.
    const display = [...(name?.given ?? []), name?.family ?? ''].filter((part) => part !== '').join(' ');
    // The record number: the identifier whose type code is "PT".
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
    // What is typed in the box, and where the search is up to. Changing either redraws the picker.
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState<SearchState>({ status: 'idle' });

    /** Runs when the form is submitted: searches for the typed name and records the outcome. */
    async function runSearch(event: FormEvent) {
        // Stop the browser's default of reloading the page on submit.
        event.preventDefault();
        const text = query.trim();
        if (text === '') {
            return;
        }
        setSearch({ status: 'searching' });
        // Ask the server for patients whose name matches; `await` waits for the answer.
        const result = await client.getBundle<Patient>(`Patient?name=${encodeURIComponent(text)}`);
        // On success, keep each matching patient as a labelled result; otherwise record the failure.
        setSearch(
            result.ok
                ? { status: 'done', query: text, matches: result.value.map(describePatient) }
                : { status: 'error' },
        );
    }

    return (
        <form role="search" onSubmit={(event) => void runSearch(event)}>
            {/* The labelled text box; every keystroke updates the remembered query. */}
            <label>
                Find a patient{' '}
                <input value={query} onChange={(event) => setQuery(event.target.value)} autoComplete="off" />
            </label>{' '}
            <button type="submit">Search</button>
            {/* Each `cond && …` line draws its message only in the matching state. */}
            {search.status === 'searching' && <p>Searching…</p>}
            {search.status === 'error' && <p role="alert">Couldn't search for patients</p>}
            {search.status === 'done' && search.matches.length === 0 && <p>No patients match "{search.query}"</p>}
            {search.status === 'done' && search.matches.length > 0 && (
                <ul>
                    {/* One button per match; clicking it passes that patient's id on. */}
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
