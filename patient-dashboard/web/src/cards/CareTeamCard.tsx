import type { LoadState } from '../hooks/loadState';
import type { CareTeamView } from '../mappers/careTeam';

/** Badge colour by team status; the old card's badge was always green. */
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
        return (
            <section data-card="care-team" data-state={state.status} aria-label="Care Team">
                <h3>Care Team</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading the care team…</p>
                ) : (
                    <p role="alert">Couldn't load the care team</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="care-team" data-state="ready" data-patient-id={patientId} aria-label="Care Team">
            <h3>Care Team</h3>
            {state.data.length === 0 ? (
                // The old card showed a bare header row (BM-030).
                <p data-empty>No care team recorded</p>
            ) : (
                state.data.map((team) => (
                    <div key={team.id}>
                        <h4 data-item="team">
                            <span data-field="name">{team.name}</span>{' '}
                            <span data-field="status" className={`badge ${BADGE[team.status] ?? 'badge-secondary'}`}>
                                {team.status}
                            </span>
                        </h4>
                        {team.members.length === 0 ? (
                            <p>No members recorded</p>
                        ) : (
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
            {state.data.length > 0 && (
                <p className="small text-muted">
                    OpenEMR's API does not say whether a member was removed from the team. Check the care team in
                    OpenEMR before relying on it.
                </p>
            )}
        </section>
    );
}
