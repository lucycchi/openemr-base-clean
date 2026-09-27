/**
 * The Medical Problems card on screen. In: the patient's id and the problem rows prepared by
 * mappers/problems.ts (current problems only, oldest first), or a note that they are still loading
 * or failed. Out: a list with one line per problem name. The markup looks like HTML; see
 * AllergiesCard.tsx for a short reading guide.
 */
import type { LoadState } from '../hooks/loadState';
import type { ProblemView } from '../mappers/problems';

/** The problem list ("Medical Problems" on the old dashboard). */
export function ProblemListCard({ patientId, state }: { patientId: string; state: LoadState<ProblemView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: the heading and a loading or error message (`a ? b : c` chooses).
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
            {/* No problems: say none are recorded. Otherwise one list item per problem, in the given order. */}
            {state.data.length === 0 ? (
                <p data-empty>None recorded</p>
            ) : (
                <ul>
                    {state.data.map((problem) => (
                        <li key={problem.id} data-item="problem">
                            {problem.name}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
