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
import { useAllergyCard } from '../hooks/useAllergyCard';
import { useCareTeam } from '../hooks/useCareTeam';
import { useMedicationCards } from '../hooks/useMedicationCards';
import { useProblemCard } from '../hooks/useProblemCard';
import { useEncounters } from '../hooks/useEncounters';
import { useIdleLogout } from '../hooks/useIdleLogout';
import { usePatient } from '../hooks/usePatient';
import { visibleCards } from './hiddenCards';
import { parseSiteConfig } from './siteConfig';
import type { SiteConfig } from './siteConfig';
import type { LoadState } from '../hooks/loadState';

type AuthState = 'checking' | 'signed-in' | 'signed-out';

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

/** OpenEMR refused this card's data (403): the old page would not have rendered the card at all. */
function forbidden(state: LoadState<unknown>): boolean {
    return state.status === 'error' && state.error.kind === 'http' && state.error.status === 403;
}

/** The local date and time, "YYYY-MM-DD HH:MM:SS", for the list rules' end-date comparison. */
function localNow(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${localToday()} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}

function PatientView({ client, patientId, config }: { client: ApiClient; patientId: string; config: SiteConfig }) {
    const [asOf] = useState(localToday);
    const [asOfTime] = useState(localNow);
    const header = usePatient(client, patientId, {
        asOf,
        age: config.ageDisplay,
        dateFormat: config.dateDisplayFormat,
    });
    const allergies = useAllergyCard(client, patientId, asOfTime);
    const problems = useProblemCard(client, patientId, asOfTime);
    const medicationCards = useMedicationCards(client, patientId, asOfTime);
    const careTeam = useCareTeam(client, patientId);
    const encounters = useEncounters(client, patientId);
    const shown = visibleCards(config.hiddenCards);
    // As old demographics.php exits before any card when the patient cannot be shown, no clinical card
    // appears until the header has loaded and matched this patient (usePatient checks the id; Codex review 3).
    if (header.status !== 'ready') {
        return <PatientHeader state={header} />;
    }
    return (
        <>
            <PatientHeader state={header} />
            {shown.includes('card_allergies') && !forbidden(allergies) && (
                <AllergiesCard patientId={patientId} state={allergies} />
            )}
            {shown.includes('card_medicalproblems') && !forbidden(problems) && (
                <ProblemListCard patientId={patientId} state={problems} />
            )}
            {shown.includes('card_medication') && !forbidden(medicationCards.medications) && (
                <MedicationsCard patientId={patientId} state={medicationCards.medications} />
            )}
            {shown.includes('card_prescriptions') && !forbidden(medicationCards.prescriptions) && (
                <PrescriptionsCard patientId={patientId} state={medicationCards.prescriptions} />
            )}
            {shown.includes('card_care_team') && !forbidden(careTeam) && (
                <CareTeamCard patientId={patientId} state={careTeam} />
            )}
            {shown.includes('card_encounter_history') && !forbidden(encounters) && (
                <EncounterHistoryCard
                    patientId={patientId}
                    state={encounters}
                    pageSize={config.encounterPageSize}
                    dateFormat={config.dateDisplayFormat}
                />
            )}
        </>
    );
}

/** Application shell: session status, site config, and the patient view at /patient/:fhirId. */
export function App() {
    const [auth, setAuth] = useState<AuthState>('checking');
    const [config, setConfig] = useState<SiteConfig | 'error' | undefined>(undefined);
    const [signedOutForIdle, setSignedOutForIdle] = useState(false);
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
            .then(async (res) => (res.ok ? parseSiteConfig(await res.json()) : undefined))
            .catch(() => undefined)
            .then((parsed) => {
                if (!cancelled) {
                    setConfig(parsed ?? 'error');
                }
            });
        return () => {
            cancelled = true;
        };
    }, [auth]);

    async function logOut(reason?: 'idle') {
        await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin' }).catch(() => undefined);
        setSignedOutForIdle(reason === 'idle');
        setAuth('signed-out');
    }

    // Sign out after the site's idle timeout, as OpenEMR's tab frame does (Opus review 4).
    const idleTimeoutSeconds = config !== undefined && config !== 'error' ? config.idleTimeoutSeconds : 7200;
    useIdleLogout(auth === 'signed-in', idleTimeoutSeconds, () => void logOut('idle'));

    // Recheck the server session every minute and on returning to the tab, so a chart is never left
    // on screen after the session has ended.
    useEffect(() => {
        if (auth !== 'signed-in') {
            return;
        }
        const recheck = () => {
            void fetch('/auth/me', { credentials: 'same-origin' })
                .then((res) =>
                    res.ok ? (res.json() as Promise<{ authenticated: boolean }>) : { authenticated: false },
                )
                .then((body) => {
                    if (!body.authenticated) {
                        setAuth('signed-out');
                    }
                })
                .catch(() => undefined);
        };
        const onVisible = () => {
            if (document.visibilityState !== 'hidden') {
                recheck();
            }
        };
        document.addEventListener('visibilitychange', onVisible);
        const timer = setInterval(recheck, 60_000);
        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            clearInterval(timer);
        };
    }, [auth]);

    return (
        <main>
            <h1>Patient Dashboard</h1>
            {auth === 'signed-out' && signedOutForIdle && (
                <p role="status">
                    You were signed out after {Math.round(idleTimeoutSeconds / 60)} minutes without activity.
                </p>
            )}
            {auth === 'signed-out' && <a href="/auth/login">Log in with OpenEMR</a>}
            {auth === 'signed-in' && (
                <>
                    <button type="button" onClick={() => void logOut()}>
                        Log out
                    </button>
                    <PatientPicker client={client} onSelect={openPatient} />
                    {patientId === undefined && <p>Choose a patient to open their dashboard.</p>}
                    {config === 'error' && (
                        <p role="alert">Couldn't load the dashboard settings. Reload the page to try again.</p>
                    )}
                    {patientId !== undefined && config !== undefined && config !== 'error' && (
                        <PatientView key={patientId} client={client} patientId={patientId} config={config} />
                    )}
                </>
            )}
        </main>
    );
}
