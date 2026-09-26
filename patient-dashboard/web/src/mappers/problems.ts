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

/**
 * Every problem that has not ended (clinicalStatus other than inactive), oldest onset first with a
 * missing onset first, matching the old card's ORDER BY begdate (BM-018).
 */
export function mapProblems(resources: readonly Condition[]): ProblemView[] {
    return resources
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
