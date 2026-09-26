import type { Condition } from 'fhir/r4';
import { narrativeText, realCodingDisplay } from './narrative';

/** One row of the problem list. See modules/problem-list.md and the Gate 2 decisions. */
export interface ProblemView {
    id: string;
    name: string;
    /**
     * '' for active problems. OpenEMR reports a first-occurrence problem as "resolved" and a
     * chronic one as "recurrence" (BM-017), so those are shown with a label rather than hidden.
     */
    label: string;
}

const LABELS: Record<string, string> = {
    resolved: 'resolved per FHIR',
    recurrence: 'recurrence',
    relapse: 'relapse',
    remission: 'remission',
};

function clinicalStatus(condition: Condition): string {
    return condition.clinicalStatus?.coding?.[0]?.code ?? '';
}

function problemName(condition: Condition): string {
    return (
        condition.code?.text?.trim() ||
        realCodingDisplay(condition.code?.coding) ||
        narrativeText(condition.text?.div) ||
        'Unnamed problem'
    );
}

/** The problem list proper, plus problems OpenEMR moves out of it once they are linked to a visit. */
const PROBLEM_CATEGORIES = new Set(['problem-list-item', 'encounter-diagnosis']);

/** When visit copies of one problem disagree, the most current status wins. */
const STATUS_RANK = ['active', 'recurrence', 'relapse', 'remission', 'resolved'];

function category(condition: Condition): string {
    return condition.category?.[0]?.coding?.[0]?.code ?? '';
}

function rank(condition: Condition): number {
    const index = STATUS_RANK.indexOf(clinicalStatus(condition));
    return index === -1 ? STATUS_RANK.length : index;
}

/**
 * A problem linked to a visit is left out of the problem-list-item search and returned once per
 * linked visit as encounter-diagnosis (BM-043), so those copies are merged by name, onset and
 * diagnosis codes. Nothing in a visit copy identifies its problem row, so two separate problems with
 * the same name, onset and codes would show once. A problem-list entry is never merged: it cannot
 * also have visit copies.
 */
function mergeVisitCopies(conditions: readonly Condition[]): Condition[] {
    const merged: Condition[] = [];
    const byKey = new Map<string, number>();
    for (const condition of conditions) {
        if (category(condition) !== 'encounter-diagnosis') {
            merged.push(condition);
            continue;
        }
        const codes = (condition.code?.coding ?? [])
            .map((coding) => `${coding.system ?? ''}#${coding.code ?? ''}`)
            .sort();
        const key = `${problemName(condition)}|${condition.onsetDateTime ?? ''}|${codes.join(',')}`;
        const at = byKey.get(key);
        const kept = at === undefined ? undefined : merged[at];
        if (at === undefined || kept === undefined) {
            byKey.set(key, merged.length);
            merged.push(condition);
        } else if (rank(condition) < rank(kept)) {
            merged[at] = condition;
        }
    }
    return merged;
}

/**
 * Every problem that has not ended (clinicalStatus other than inactive), oldest onset first with a
 * missing onset first, matching the old card's ORDER BY begdate (BM-018). The search is
 * Condition?patient= (every category); only problems are kept.
 */
export function mapProblems(resources: readonly Condition[]): ProblemView[] {
    return mergeVisitCopies(resources.filter((condition) => PROBLEM_CATEGORIES.has(category(condition))))
        .filter((condition) => clinicalStatus(condition) !== 'inactive')
        .map((condition, index) => ({ condition, index }))
        .sort((a, b) => {
            const onsetA = a.condition.onsetDateTime ?? '';
            const onsetB = b.condition.onsetDateTime ?? '';
            return onsetA === onsetB ? a.index - b.index : onsetA < onsetB ? -1 : 1;
        })
        .map(({ condition }) => ({
            id: condition.id ?? '',
            name: problemName(condition),
            label: LABELS[clinicalStatus(condition)] ?? '',
        }));
}
