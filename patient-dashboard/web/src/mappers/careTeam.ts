import type { CareTeam, CareTeamParticipant, CodeableConcept } from 'fhir/r4';
import { isReadableNameReference, NAME_UNAVAILABLE } from './people';

/** One member row of the Care Team card. See modules/care-team.md. */
export interface CareTeamMemberView {
    key: string;
    type: string;
    /** The resolved name, or NAME_UNAVAILABLE (BM-028). */
    name: string;
    /** '' when no role coding has a display, which covers the bare 407542009 default (BM-031). */
    role: string;
    /** The member's facility name; '' when none is recorded, NAME_UNAVAILABLE when it cannot be read. */
    facility: string;
    /** participant.period.start as YYYY-MM-DD; '' when FHIR has no period (BM-037). */
    since: string;
}

export interface CareTeamView {
    id: string;
    name: string;
    status: string;
    members: CareTeamMemberView[];
}

const MEMBER_TYPE: Record<string, string> = {
    Practitioner: 'Provider',
    PractitionerRole: 'Provider',
    RelatedPerson: 'Related Person',
};

const TEAM_STATUS: Record<NonNullable<CareTeam['status']>, string> = {
    proposed: 'Proposed',
    active: 'Active',
    suspended: 'Suspended',
    inactive: 'Inactive',
    'entered-in-error': 'Entered in error',
};

function referenceType(reference: string): string {
    return reference.split('/')[0] ?? '';
}

/**
 * OpenEMR's care-team members are always a user or a contact. FhirCareTeamService also lists each
 * facility as its own Organization participant ("Healthcare facility"), which the old card never
 * showed as a row, so Organization participants are not members (BM-045).
 */
function isMember(participant: CareTeamParticipant): boolean {
    const reference = participant.member?.reference ?? '';
    return (participant.member?.type ?? referenceType(reference)) !== 'Organization';
}

/** Every member and facility reference the card should read for a name, each once, in order. */
export function memberReferences(teams: readonly CareTeam[]): string[] {
    const references = teams.flatMap((team) =>
        (team.participant ?? []).filter(isMember).flatMap((participant) => {
            const member = participant.member?.reference ?? '';
            const facility = participant.onBehalfOf?.reference ?? '';
            return [
                referenceType(member) === 'Organization' ? '' : member,
                referenceType(facility) === 'Organization' ? facility : '',
            ];
        }),
    );
    return [...new Set(references.filter((reference) => isReadableNameReference(reference)))];
}

function facilityName(participant: CareTeamParticipant, names: ReadonlyMap<string, string>): string {
    const facility = participant.onBehalfOf;
    if (facility === undefined) {
        return '';
    }
    return facility.display?.trim() || names.get(facility.reference ?? '') || NAME_UNAVAILABLE;
}

function roleText(roles: readonly CodeableConcept[] | undefined): string {
    return (roles ?? [])
        .map(
            (role) => role.text?.trim() || role.coding?.find((coding) => coding.display?.trim())?.display?.trim() || '',
        )
        .filter((text) => text !== '')
        .join('; ');
}

/**
 * Maps CareTeam resources for the card. `names` holds the names that could be read, keyed by
 * reference; every other member is kept and shown as "Name unavailable" (BM-028).
 */
export function mapCareTeams(teams: readonly CareTeam[], names: ReadonlyMap<string, string>): CareTeamView[] {
    // The old card leaves out teams marked entered-in-error (CareTeamService.php:581).
    return teams
        .filter((team) => team.status !== 'entered-in-error')
        .map((team, teamIndex) => ({
            id: team.id ?? String(teamIndex),
            name: team.name?.trim() || 'Unnamed care team',
            status: team.status === undefined ? '' : TEAM_STATUS[team.status],
            members: (team.participant ?? []).filter(isMember).map((participant, index) => {
                const reference = participant.member?.reference ?? '';
                const resourceType = participant.member?.type ?? referenceType(reference);
                return {
                    key: String(index),
                    type: MEMBER_TYPE[resourceType] ?? (resourceType || 'Unknown'),
                    name: names.get(reference) ?? NAME_UNAVAILABLE,
                    role: roleText(participant.role),
                    facility: facilityName(participant, names),
                    since: participant.period?.start?.slice(0, 10) ?? '',
                };
            }),
        }));
}
