import { useState } from 'react';
import type { LoadState } from '../hooks/loadState';
import { formatShortDate } from '../mappers/dates';
import type { DateDisplayFormat } from '../mappers/dates';
import type { EncounterView } from '../mappers/encounters';

/**
 * The extra section: visits newest first, the first `pageSize` (the encounter_page_size global; 0
 * shows all) with a "Show all" toggle in place of the old page's picker and Prev / Next (BM-034).
 * Only date, reason and provider are in FHIR Encounter (BM-039).
 */
export function EncounterHistoryCard({
    patientId,
    state,
    pageSize,
    dateFormat = 0,
}: {
    patientId: string;
    state: LoadState<EncounterView[]>;
    pageSize: number;
    /** The site date format (the date_display_format global), as the old page's oeFormatShortDate. */
    dateFormat?: DateDisplayFormat;
}) {
    const [showAll, setShowAll] = useState(false);
    if (state.status !== 'ready') {
        return (
            <section data-card="encounter-history" data-state={state.status} aria-label="Encounter history">
                <h3>Encounter history</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading encounters…</p>
                ) : (
                    <p role="alert">Couldn't load encounters</p>
                )}
            </section>
        );
    }
    const total = state.data.length;
    const paged = pageSize > 0 && total > pageSize;
    const shown = paged && !showAll ? state.data.slice(0, pageSize) : state.data;
    return (
        <section
            data-card="encounter-history"
            data-state="ready"
            data-patient-id={patientId}
            aria-label="Encounter history"
        >
            <h3>Encounter history</h3>
            {total === 0 ? (
                // The old page showed "1-0 of 0" above an empty header (BM-035).
                <p data-empty>No encounters recorded</p>
            ) : (
                <>
                    <table className="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Reason</th>
                                <th scope="col">Provider</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((encounter) => (
                                <tr key={encounter.id} data-item="encounter">
                                    <td data-field="date">{formatShortDate(encounter.date, dateFormat)}</td>
                                    <td data-field="reason">{encounter.reason}</td>
                                    <td data-field="provider">{encounter.provider}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {paged && (
                        <p className="small">
                            {showAll ? `Showing all ${total}` : `Showing the ${pageSize} most recent of ${total}`}{' '}
                            <button
                                type="button"
                                className="btn btn-link btn-sm p-0"
                                onClick={() => setShowAll(!showAll)}
                            >
                                {showAll ? `Show the ${pageSize} most recent` : `Show all ${total}`}
                            </button>
                        </p>
                    )}
                    <p className="small text-muted">
                        OpenEMR's API does not apply the visit sensitivity restrictions the Visit History page uses, so
                        reasons it would show as "(No access)" appear here.
                    </p>
                </>
            )}
        </section>
    );
}
