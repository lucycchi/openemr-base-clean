import type { LoadState } from '../hooks/loadState';
import type { ProblemView } from '../mappers/problems';

/** The problem list ("Medical Problems" on the old dashboard). */
export function ProblemListCard({ patientId, state }: { patientId: string; state: LoadState<ProblemView[]> }) {
    if (state.status !== 'ready') {
        return (
            <section data-card="problems" data-state={state.status} aria-label="Medical Problems">
                <h3>Medical Problems</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading medical problems…</p>
                ) : (
                    <p role="alert">Couldn't load medical problems</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="problems" data-state="ready" data-patient-id={patientId} aria-label="Medical Problems">
            <h3>Medical Problems</h3>
            {state.data.length === 0 ? (
                <p data-empty>None recorded</p>
            ) : (
                <ul>
                    {state.data.map((problem) => (
                        <li key={problem.id} data-item="problem">
                            {problem.label === '' ? problem.name : `${problem.name} (${problem.label})`}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
