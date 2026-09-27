/**
 * Care Team card rules. In: the patient's FHIR CareTeam records (a named team with a status and a
 * list of participants) plus the names that could be looked up for the people and facilities they
 * point to. Out: one entry per team, each with the member rows the Care Team card's table shows
 * (type, name, role, facility, since). It also lists which names need looking up in the first place.
 *
 * A participant does not carry the member's name itself; it holds a "reference" such as
 * "Practitioner/abc", which points to another record (a Practitioner is a clinician or staff user;
 * a RelatedPerson is a family member or other contact; an Organization is a facility).
 */
import type { CareTeam, CareTeamParticipant, CodeableConcept } from 'fhir/r4';
import { isReadableNameReference, NAME_UNAVAILABLE } from './people';

/** One member row of the Care Team card. See modules/care-team.md. */
export interface CareTeamMemberView {
    /** A position number that keeps rows apart on screen; not shown. */
    key: string;
    /** "Provider", "Related Person", or the FHIR record type when it is something else. */
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

/** One care team as the card shows it: its name, a status badge and its member rows. */
export interface CareTeamView {
    id: string;
    name: string;
    status: string;
    members: CareTeamMemberView[];
}

/** The record type a member's reference points to, turned into the word the Type column shows. */
const MEMBER_TYPE: Record<string, string> = {
    Practitioner: 'Provider',
    PractitionerRole: 'Provider',
    RelatedPerson: 'Related Person',
};

/** FHIR's team status codes turned into the words on the status badge. */
const TEAM_STATUS: Record<NonNullable<CareTeam['status']>, string> = {
    proposed: 'Proposed',
    active: 'Active',
    suspended: 'Suspended',
    inactive: 'Inactive',
    'entered-in-error': 'Entered in error',
};

/** The record type at the front of a reference: "Practitioner/abc" gives "Practitioner". */
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
    // Use the stated type if there is one, otherwise read it from the front of the reference.
    return (participant.member?.type ?? referenceType(reference)) !== 'Organization';
}

/**
 * Every member and facility reference the card should read for a name, each once, in order.
 * For each member row this collects the person it points to and the facility they act for
 * ("onBehalfOf"), skips anything of the wrong kind or that is not safe to fetch, and removes repeats.
 */
export function memberReferences(teams: readonly CareTeam[]): string[] {
    // For every team, take its member participants and, for each, a pair: the person, then the facility.
    // A person reference that is really a facility, or a facility reference that is not one, becomes ''.
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
    // Keep only the references safe to look up (which drops the blanks); a Set holds each value once,
    // so repeats are removed while the first-seen order is kept.
    return [...new Set(references.filter((reference) => isReadableNameReference(reference)))];
}

/** The Facility column: '' when the member has no facility, its name when known, otherwise "Name unavailable". */
function facilityName(participant: CareTeamParticipant, names: ReadonlyMap<string, string>): string {
    const facility = participant.onBehalfOf;
    if (facility === undefined) {
        return '';
    }
    // The name written on the reference itself, or the looked-up name, or "Name unavailable".
    return facility.display?.trim() || names.get(facility.reference ?? '') || NAME_UNAVAILABLE;
}

/** The Role column: each role's text, or failing that its first coded name, joined with "; ". */
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
    // Every other team keeps its original order and becomes one card entry.
    return teams
        .filter((team) => team.status !== 'entered-in-error')
        .map((team, teamIndex) => ({
            // A team with no id falls back to its position in the list.
            id: team.id ?? String(teamIndex),
            name: team.name?.trim() || 'Unnamed care team',
            status: team.status === undefined ? '' : TEAM_STATUS[team.status],
            // The team's people (facility participants dropped), in order, each turned into one table row.
            members: (team.participant ?? []).filter(isMember).map((participant, index) => {
                const reference = participant.member?.reference ?? '';
                const resourceType = participant.member?.type ?? referenceType(reference);
                return {
                    key: String(index),
                    // A known type gets its friendly word; an unknown one shows as written, or "Unknown" if blank.
                    type: MEMBER_TYPE[resourceType] ?? (resourceType || 'Unknown'),
                    name: names.get(reference) ?? NAME_UNAVAILABLE,
                    role: roleText(participant.role),
                    facility: facilityName(participant, names),
                    // `slice(0, 10)` keeps just the YYYY-MM-DD part of a date that may also carry a time.
                    since: participant.period?.start?.slice(0, 10) ?? '',
                };
            }),
        }));
}
