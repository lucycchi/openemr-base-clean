/**
 * The whole dashboard page. App decides what to show: a login link when signed out, and when signed in
 * a log-out button, the patient picker and, once a patient is chosen, that patient's dashboard
 * (PatientView). The web address says which patient is open, in the form /patient/<id>, so the browser's
 * back and forward buttons move between patients.
 *
 * App also looks after the session: it asks the BFF whether the user is signed in, loads the site
 * settings, signs the user out after a period without activity, and rechecks the session every minute.
 * PatientView starts each card's data loading (through the hooks in ../hooks) and hands the results to
 * the card components in ../cards, which draw them.
 */
import { useEffect, useMemo, useState } from 'react';
import type { ReactElement } from 'react';
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
import { CardLayoutContext, useCardLayout } from '../hooks/useCardLayout';
import { usePatient } from '../hooks/usePatient';
import { visibleCards } from './hiddenCards';
import { parseSiteConfig } from './siteConfig';
import type { SiteConfig } from './siteConfig';
import type { LoadState } from '../hooks/loadState';

/** Whether the user is signed in: still asking the server, signed in, or signed out. */
type AuthState = 'checking' | 'signed-in' | 'signed-out';

/** The patient id in /patient/:fhirId, if any. */
export function patientIdFromPath(pathname: string): string | undefined {
    // The pattern matches an address that is exactly "/patient/" followed by one segment containing no
    // further "/" (an optional trailing "/" is allowed), and captures that segment as the patient id.
    const match = /^\/patient\/([^/]+)\/?$/.exec(pathname);
    return match?.[1] === undefined ? undefined : decodeURIComponent(match[1]);
}

/** Today's date in the browser's time zone, YYYY-MM-DD. */
function localToday(): string {
    const now = new Date();
    // Writes a number with at least two digits, so 7 becomes "07".
    const pad = (n: number) => String(n).padStart(2, '0');
    // getMonth counts from 0 for January, hence the + 1.
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

/**
 * One patient's dashboard: the header (name, age and so on) and then each card the site has not hidden.
 * It starts loading every card's data at once through the hooks, and each card shows "loading", its
 * data, or "Couldn't load" as its own answer arrives. A card OpenEMR refuses to this user (403) is left
 * out entirely, as on the old page. App gives PatientView a fresh start whenever the patient changes.
 */
function PatientView({ client, patientId, config }: { client: ApiClient; patientId: string; config: SiteConfig }) {
    // `useState` is a value the page remembers between redraws. Here it captures today's date and the
    // current time once, when the patient is opened, so ages and "has this ended?" checks stay fixed
    // for the life of the view rather than changing with every redraw.
    const [asOf] = useState(localToday);
    const [asOfTime] = useState(localNow);
    // Each "use..." line below starts loading one card's data and gives back its current state.
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
    // The first three cards share one row, as on the old dashboard (demographics.php:1099-1102): each
    // visible card gets an equal share of Bootstrap's 12 columns, so three cards get 4 each. Each
    // `if` adds a card only when the site has not hidden it and OpenEMR did not refuse its data.
    const topRow: ReactElement[] = [];
    if (shown.includes('card_allergies') && !forbidden(allergies)) {
        topRow.push(<AllergiesCard key="allergies" patientId={patientId} state={allergies} />);
    }
    if (shown.includes('card_medicalproblems') && !forbidden(problems)) {
        topRow.push(<ProblemListCard key="problems" patientId={patientId} state={problems} />);
    }
    if (shown.includes('card_medication') && !forbidden(medicationCards.medications)) {
        topRow.push(<MedicationsCard key="medications" patientId={patientId} state={medicationCards.medications} />);
    }
    const topColumn = `col-md-${String(12 / Math.max(1, topRow.length))} p-1`;
    // The markup below (JSX) describes what to draw. `<>...</>` groups items without adding a box around
    // them. The header stays outside the rows so it can stay pinned to the top while they scroll. The
    // cards below the first row each take the full width, as on the old page; each
    // `condition && (...)` line draws its card only when the condition holds.
    return (
        <>
            <PatientHeader state={header} />
            <div className="row no-gutters">
                {topRow.map((card) => (
                    <div key={card.key} className={topColumn}>
                        {card}
                    </div>
                ))}
            </div>
            <div className="row no-gutters">
                {shown.includes('card_prescriptions') && !forbidden(medicationCards.prescriptions) && (
                    <div className="col-12 p-1">
                        <PrescriptionsCard patientId={patientId} state={medicationCards.prescriptions} />
                    </div>
                )}
                {shown.includes('card_care_team') && !forbidden(careTeam) && (
                    <div className="col-12 p-1">
                        <CareTeamCard patientId={patientId} state={careTeam} />
                    </div>
                )}
                {shown.includes('card_encounter_history') && !forbidden(encounters) && (
                    <div className="col-12 p-1">
                        <EncounterHistoryCard
                            patientId={patientId}
                            state={encounters}
                            pageSize={config.encounterPageSize}
                            dateFormat={config.dateDisplayFormat}
                        />
                    </div>
                )}
            </div>
        </>
    );
}

/** Application shell: session status, site config, and the patient view at /patient/:fhirId. */
export function App() {
    // Values the page remembers and redraws on change: sign-in status, site settings (not yet loaded,
    // loaded, or 'error'), and whether the last sign-out was for inactivity.
    const [auth, setAuth] = useState<AuthState>('checking');
    const [config, setConfig] = useState<SiteConfig | 'error' | undefined>(undefined);
    const [signedOutForIdle, setSignedOutForIdle] = useState(false);
    // `useMemo` with an empty list `[]` makes the API client once and reuses it on every redraw.
    const client = useMemo(() => createApiClient(), []);
    // The current web address path; the open patient's id is read from it.
    const [pathname, setPathname] = useState(() => globalThis.location.pathname);
    const patientId = patientIdFromPath(pathname);

    // `useEffect` is work to do after the page is drawn; the function it returns is the clean-up.
    // This one follows the browser's back and forward buttons ("popstate"), so the page shows the
    // patient in the address bar. The empty list `[]` means "set up once, when the page first opens".
    useEffect(() => {
        const onPopState = () => setPathname(globalThis.location.pathname);
        globalThis.addEventListener('popstate', onPopState);
        return () => globalThis.removeEventListener('popstate', onPopState);
    }, []);

    /** Opens a patient chosen in the picker: puts /patient/<id> in the address bar and shows them. */
    function openPatient(id: string) {
        const next = `/patient/${encodeURIComponent(id)}`;
        globalThis.history.pushState(null, '', next);
        setPathname(next);
    }

    // On first opening, ask the BFF whether this browser has a signed-in session. Each `.then` step
    // runs when the previous one's answer arrives; `.catch` runs if the request fails, which is treated
    // as signed out. `cancelled` stops a late answer from updating a page that has already closed.
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

    // Once signed in, load the site settings. A failed or malformed answer becomes 'error', which shows
    // a message instead of the patient view. `[auth]` means this runs again whenever sign-in status changes.
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

    /**
     * Ends the session on the BFF (which forgets the user's OpenEMR tokens) and shows the signed-out
     * page. `reason` is 'idle' when the inactivity timer triggered it, so the page can say why.
     * A failed request is ignored: the page still signs out.
     */
    async function logOut(reason?: 'idle') {
        await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin' }).catch(() => undefined);
        setSignedOutForIdle(reason === 'idle');
        setAuth('signed-out');
    }

    // Sign out after the site's idle timeout, as OpenEMR's tab frame does (Opus review 4).
    // Until the site settings arrive, 7200 seconds (two hours) is used.
    // Which cards this user keeps collapsed, remembered between visits (hooks/useCardLayout.ts).
    const cardLayout = useCardLayout(auth === 'signed-in');

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
        // Clean-up: stop the timer and the tab listener when the user signs out or the page closes.
        return () => {
            document.removeEventListener('visibilitychange', onVisible);
            clearInterval(timer);
        };
    }, [auth]);

    return (
        <main className="container-fluid py-2">
            <h1 className="h4">Patient Dashboard</h1>
            {/* Each `condition && (...)` below is drawn only when its condition holds. */}
            {auth === 'signed-out' && signedOutForIdle && (
                <p role="status">
                    You were signed out after {Math.round(idleTimeoutSeconds / 60)} minutes without activity.
                </p>
            )}
            {auth === 'signed-out' && (
                <a className="btn btn-primary" href="/auth/login">
                    Log in with OpenEMR
                </a>
            )}
            {auth === 'signed-in' && (
                <>
                    <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm mb-2"
                        onClick={() => void logOut()}
                    >
                        Log out
                    </button>
                    <PatientPicker client={client} onSelect={openPatient} />
                    {patientId === undefined && <p>Choose a patient to open their dashboard.</p>}
                    {config === 'error' && (
                        <p role="alert">Couldn't load the dashboard settings. Reload the page to try again.</p>
                    )}
                    {/* `key={patientId}` makes React throw away the old patient's view and build a fresh one
                        when the patient changes, so nothing from the previous patient carries over. */}
                    {/* The patient view waits for the saved card layout too, so a card the user keeps
                        collapsed never flashes open first. CardLayoutContext hands the layout to every card. */}
                    {patientId !== undefined &&
                        config !== undefined &&
                        config !== 'error' &&
                        cardLayout !== 'loading' && (
                            <CardLayoutContext value={cardLayout}>
                                <PatientView key={patientId} client={client} patientId={patientId} config={config} />
                            </CardLayoutContext>
                        )}
                </>
            )}
        </main>
    );
}
