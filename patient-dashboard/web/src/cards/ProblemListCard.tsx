/**
 * The Medical Problems card on screen. In: the patient's id and the problem rows prepared by
 * mappers/problems.ts (current problems only, oldest first), or a note that they are still loading
 * or failed. Out: a list with one line per problem name. The markup looks like HTML; see
 * AllergiesCard.tsx for a short reading guide.
 */
import type { LoadState } from '../hooks/loadState';
import { CardFrame } from './CardFrame';
import type { ProblemView } from '../mappers/problems';

/** The problem list ("Medical Problems" on the old dashboard). */
export function ProblemListCard({ patientId, state }: { patientId: string; state: LoadState<ProblemView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: the heading and a loading or error message (`a ? b : c` chooses).
        return (
            <CardFrame card="problems" title="Medical Problems" state={state.status} patientId={patientId}>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading medical problems…</p>
                ) : (
                    <p role="alert">Couldn't load medical problems</p>
                )}
            </CardFrame>
        );
    }
    return (
        <CardFrame card="problems" title="Medical Problems" state="ready" patientId={patientId}>
            {/* No problems: say none are recorded. Otherwise one list item per problem, in the given order. */}
            {state.data.length === 0 ? (
                <p data-empty>None recorded</p>
            ) : (
                // The old card's flush list: one thin row per entry.
                <ul className="list-group list-group-flush">
                    {state.data.map((problem) => (
                        <li key={problem.id} data-item="problem" className="list-group-item p-0 pl-1">
                            {problem.name}
                        </li>
                    ))}
                </ul>
            )}
        </CardFrame>
    );
}
