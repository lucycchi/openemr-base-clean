import type { CareTeam, CodeableConcept, HumanName, Practitioner, RelatedPerson } from 'fhir/r4';

/** One member row of the Care Team card. See modules/care-team.md. */
export interface CareTeamMemberView {
    key: string;
    type: string;
    /** The resolved name, or NAME_UNAVAILABLE (BM-028). */
    name: string;
    /** '' when no role coding has a display, which covers the bare 407542009 default (BM-031). */
    role: string;
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

export const NAME_UNAVAILABLE = 'Name unavailable';

/**
 * Relative references to the member types the BFF proxy serves, with a FHIR id that cannot be a
 * dot-segment. Anything else (absolute URLs, other types, traversal) is never fetched.
 */
const READABLE_MEMBER = /^(Practitioner|RelatedPerson)\/[A-Za-z0-9-][A-Za-z0-9.-]{0,63}$/;

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

/** Every member reference the card should read for a name, each once, in order. */
export function memberReferences(teams: readonly CareTeam[]): string[] {
    const references = teams.flatMap((team) =>
        (team.participant ?? []).map((participant) => participant.member?.reference ?? ''),
    );
    return [...new Set(references.filter((reference) => READABLE_MEMBER.test(reference)))];
}

function joinName(name: HumanName | undefined, style: 'last-first' | 'as-written'): string {
    if (name === undefined) {
        return '';
    }
    const given = (name.given ?? []).join(' ').trim();
    const family = name.family?.trim() ?? '';
    if (style === 'last-first') {
        return family !== '' && given !== '' ? `${family}, ${given}` : family || given || name.text?.trim() || '';
    }
    return name.text?.trim() || [given, family].filter((part) => part !== '').join(' ');
}

/** A provider reads "Last, First" as on the old card; a related person as written. */
export function personName(resource: Practitioner | RelatedPerson): string {
    const style = resource.resourceType === 'Practitioner' ? 'last-first' : 'as-written';
    return joinName(resource.name?.[0], style) || NAME_UNAVAILABLE;
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
    return teams.map((team, teamIndex) => ({
        id: team.id ?? String(teamIndex),
        name: team.name?.trim() || 'Unnamed care team',
        status: team.status === undefined ? '' : TEAM_STATUS[team.status],
        members: (team.participant ?? []).map((participant, index) => {
            const reference = participant.member?.reference ?? '';
            const resourceType = participant.member?.type ?? reference.split('/')[0] ?? '';
            return {
                key: String(index),
                type: MEMBER_TYPE[resourceType] ?? (resourceType || 'Unknown'),
                name: names.get(reference) ?? NAME_UNAVAILABLE,
                role: roleText(participant.role),
                facility: participant.onBehalfOf?.display?.trim() ?? '',
                since: participant.period?.start?.slice(0, 10) ?? '',
            };
        }),
    }));
}
