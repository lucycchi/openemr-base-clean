/**
 * The Allergies card on screen. In: the patient's id and the allergy rows already prepared by
 * mappers/allergies.ts (or a note that they are still loading or failed to load). Out: the drawn card:
 * a heading and a list with one line per allergy, high-risk ones highlighted.
 *
 * Reading guide for all the card files: a "component" is a function that returns markup describing
 * what to draw. That markup (JSX) looks like HTML: <section>, <h3> (a heading), <ul>/<li> (a bulleted
 * list and its items), <p> (a paragraph). Anything inside { } is worked out, not printed as written.
 * `data-...` attributes are labels the automated tests read to find things; they change nothing visible.
 * `aria-...` and `role` attributes describe the page to screen readers.
 */
import type { LoadState } from '../hooks/loadState';
import type { AllergyView } from '../mappers/allergies';

/**
 * The allergies card. Empty means "none recorded", never "No Known Allergies" (BM-012).
 * `state` is loading, error, or ready with the rows; only "ready" shows the list.
 */
export function AllergiesCard({ patientId, state }: { patientId: string; state: LoadState<AllergyView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: show the heading and a loading or error message, never an empty list.
        return (
            <section data-card="allergies" data-state={state.status} aria-label="Allergies">
                <h3>Allergies</h3>
                {/* `a ? b : c` chooses: the loading line while loading, otherwise the error line. */}
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading allergies…</p>
                ) : (
                    <p role="alert">Couldn't load allergies</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="allergies" data-state="ready" data-patient-id={patientId} aria-label="Allergies">
            <h3>Allergies</h3>
            {/* No rows: say none are recorded. Otherwise draw one list item per allergy, in the given order. */}
            {state.data.length === 0 ? (
                <p data-empty>No allergies recorded</p>
            ) : (
                <ul>
                    {state.data.map((allergy) => (
                        <li
                            key={allergy.id}
                            data-item="allergy"
                            // High-risk allergies get a highlight label for the tests and a yellow, bold style.
                            data-highlight={allergy.high ? '' : undefined}
                            className={allergy.high ? 'bg-warning font-weight-bold' : undefined}
                            // Hover text: name, reaction and risk, with runs of spaces squeezed to one.
                            title={`${allergy.name} Reaction: ${allergy.reaction} - ${allergy.risk}`
                                .replace(/\s+/g, ' ')
                                .trim()}
                        >
                            {/* The name, followed by the risk in brackets when there is one. */}
                            {allergy.risk === '' ? allergy.name : `${allergy.name} (${allergy.risk})`}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
