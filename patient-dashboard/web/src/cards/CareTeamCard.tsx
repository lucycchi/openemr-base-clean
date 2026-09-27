/**
 * The Care Team card on screen. In: the patient's id and the teams already prepared by
 * mappers/careTeam.ts (or a note that they are still loading or failed). Out: for each team, its name
 * with a coloured status badge and a table of members (type, name, role, facility, since).
 * The markup below looks like HTML; see AllergiesCard.tsx for a short reading guide.
 */
import type { LoadState } from '../hooks/loadState';
import { CardFrame } from './CardFrame';
import type { CareTeamView } from '../mappers/careTeam';

/**
 * Badge colour by team status; the old card's badge was always green.
 * Each entry pairs a status word with a style name (green, blue, amber, grey).
 */
const BADGE: Record<string, string> = {
    Active: 'badge-success',
    Proposed: 'badge-info',
    Suspended: 'badge-warning',
    Inactive: 'badge-secondary',
};

/**
 * The patient's care teams. Member status, note and the old Remove column are left out, and Since
 * is blank when FHIR has no period (BM-037).
 */
export function CareTeamCard({ patientId, state }: { patientId: string; state: LoadState<CareTeamView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: the heading and a loading or error message (`a ? b : c` chooses).
        return (
            <CardFrame card="care-team" title="Care Team" state={state.status} patientId={patientId}>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading the care team…</p>
                ) : (
                    <p role="alert">Couldn't load the care team</p>
                )}
            </CardFrame>
        );
    }
    return (
        <CardFrame card="care-team" title="Care Team" state="ready" patientId={patientId}>
            {state.data.length === 0 ? (
                // The old card showed a bare header row (BM-030).
                <p data-empty>No care team recorded</p>
            ) : (
                // One block per team, in the order given: a sub-heading, then its members.
                state.data.map((team) => (
                    <div key={team.id}>
                        <h4 data-item="team">
                            {/* The team name, a space, then the status badge. An unlisted status gets grey. */}
                            <span data-field="name">{team.name}</span>{' '}
                            <span data-field="status" className={`badge ${BADGE[team.status] ?? 'badge-secondary'}`}>
                                {team.status}
                            </span>
                        </h4>
                        {team.members.length === 0 ? (
                            <p>No members recorded</p>
                        ) : (
                            // A table: <thead> is the header row, <tbody> holds one <tr> row per member.
                            <table className="table table-sm">
                                <thead>
                                    <tr>
                                        <th scope="col">Type</th>
                                        <th scope="col">Member</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Facility</th>
                                        <th scope="col">Since</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {team.members.map((member) => (
                                        <tr key={member.key} data-item="member">
                                            <td data-field="type">{member.type}</td>
                                            <td data-field="name">{member.name}</td>
                                            <td data-field="role">{member.role}</td>
                                            <td data-field="facility">{member.facility}</td>
                                            <td data-field="since">{member.since}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                ))
            )}
            {/* `cond && <p>…</p>` draws the note only when cond is true: here, when any team is shown. */}
            {state.data.length > 0 && (
                <p className="small text-muted">
                    OpenEMR's API does not say whether a member was removed from the team. Check the care team in
                    OpenEMR before relying on it.
                </p>
            )}
        </CardFrame>
    );
}
