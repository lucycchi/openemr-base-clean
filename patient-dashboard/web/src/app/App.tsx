import { useEffect, useMemo, useState } from 'react';
import { createApiClient } from '../api/client';
import type { ApiClient } from '../api/client';
import { AllergiesCard } from '../cards/AllergiesCard';
import { CareTeamCard } from '../cards/CareTeamCard';
import { EncounterHistoryCard } from '../cards/EncounterHistoryCard';
import { MedicationsCard } from '../cards/MedicationsCard';
import { PrescriptionsCard } from '../cards/PrescriptionsCard';
import { PatientHeader } from '../cards/PatientHeader';
import { ProblemListCard } from '../cards/ProblemListCard';
import { PatientPicker } from '../cards/PatientPicker';
import { useBundleCard } from '../hooks/useBundleCard';
import { useCareTeam } from '../hooks/useCareTeam';
import { useEncounters } from '../hooks/useEncounters';
import { selectLoadState } from '../hooks/loadState';
import { usePatient } from '../hooks/usePatient';
import { mapAllergies } from '../mappers/allergies';
import { splitMedications } from '../mappers/medications';
import { mapProblems } from '../mappers/problems';
import { visibleCards } from './hiddenCards';
import type { AgeSettings } from '../mappers/age';

type AuthState = 'checking' | 'signed-in' | 'signed-out';

interface SiteConfig {
    hiddenCards: string[];
    ageDisplay: AgeSettings;
    encounterPageSize: number;
}

/** The patient id in /patient/:fhirId, if any. */
export function patientIdFromPath(pathname: string): string | undefined {
    const match = /^\/patient\/([^/]+)\/?$/.exec(pathname);
    return match?.[1] === undefined ? undefined : decodeURIComponent(match[1]);
}

/** Today's date in the browser's time zone, YYYY-MM-DD. */
function localToday(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

function PatientView({ client, patientId, config }: { client: ApiClient; patientId: string; config: SiteConfig }) {
    const [asOf] = useState(localToday);
    const header = usePatient(client, patientId, { asOf, age: config.ageDisplay });
    const allergies = useBundleCard(client, patientId, 'AllergyIntolerance', mapAllergies);
    const problems = useBundleCard(client, patientId, 'Condition', mapProblems, '&category=problem-list-item');
    const medicationRequests = useBundleCard(client, patientId, 'MedicationRequest', splitMedications);
    const careTeam = useCareTeam(client, patientId);
    const encounters = useEncounters(client, patientId);
    const shown = visibleCards(config.hiddenCards);
    return (
        <>
            <PatientHeader state={header} />
            {shown.includes('card_allergies') && <AllergiesCard patientId={patientId} state={allergies} />}
            {shown.includes('card_medicalproblems') && <ProblemListCard patientId={patientId} state={problems} />}
            {shown.includes('card_medication') && (
                <MedicationsCard
                    patientId={patientId}
                    state={selectLoadState(medicationRequests, (split) => split.medications)}
                />
            )}
            {shown.includes('card_prescriptions') && (
                <PrescriptionsCard
                    patientId={patientId}
                    state={selectLoadState(medicationRequests, (split) => split.prescriptions)}
                />
            )}
            {shown.includes('card_care_team') && <CareTeamCard patientId={patientId} state={careTeam} />}
            {shown.includes('card_encounter_history') && (
                <EncounterHistoryCard patientId={patientId} state={encounters} pageSize={config.encounterPageSize} />
            )}
        </>
    );
}

/** Application shell: session status, site config, and the patient view at /patient/:fhirId. */
export function App() {
    const [auth, setAuth] = useState<AuthState>('checking');
    const [config, setConfig] = useState<SiteConfig | undefined>(undefined);
    const client = useMemo(() => createApiClient(), []);
    const [pathname, setPathname] = useState(() => globalThis.location.pathname);
    const patientId = patientIdFromPath(pathname);

    useEffect(() => {
        const onPopState = () => setPathname(globalThis.location.pathname);
        globalThis.addEventListener('popstate', onPopState);
        return () => globalThis.removeEventListener('popstate', onPopState);
    }, []);

    function openPatient(id: string) {
        const next = `/patient/${encodeURIComponent(id)}`;
        globalThis.history.pushState(null, '', next);
        setPathname(next);
    }

    useEffect(() => {
        let cancelled = false;
        fetch('/auth/me', { credentials: 'same-origin' })
            .then((res) => (res.ok ? (res.json() as Promise<{ authenticated: boolean }>) : { authenticated: false }))
            .then((body) => {
                if (!cancelled) {
                    setAuth(body.authenticated ? 'signed-in' : 'signed-out');
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setAuth('signed-out');
                }
            });
        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        if (auth !== 'signed-in') {
            return;
        }
        let cancelled = false;
        fetch('/app-config', { credentials: 'same-origin' })
            .then((res) => res.json() as Promise<SiteConfig>)
            .then((body) => {
                if (!cancelled) {
                    setConfig(body);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setConfig({ hiddenCards: [], ageDisplay: { format: 0, limitYears: 3 }, encounterPageSize: 20 });
                }
            });
        return () => {
            cancelled = true;
        };
    }, [auth]);

    async function logOut() {
        await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin' });
        setAuth('signed-out');
    }

    return (
        <main>
            <h1>Patient Dashboard</h1>
            {auth === 'signed-out' && <a href="/auth/login">Log in with OpenEMR</a>}
            {auth === 'signed-in' && (
                <>
                    <button type="button" onClick={() => void logOut()}>
                        Log out
                    </button>
                    <PatientPicker client={client} onSelect={openPatient} />
                    {patientId === undefined && <p>Choose a patient to open their dashboard.</p>}
                    {patientId !== undefined && config !== undefined && (
                        <PatientView key={patientId} client={client} patientId={patientId} config={config} />
                    )}
                </>
            )}
        </main>
    );
}
