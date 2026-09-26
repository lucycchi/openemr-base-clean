import { useEffect, useState } from 'react';

type AuthState = 'checking' | 'signed-in' | 'signed-out';

/** Application shell: session status, log in and log out. Cards are added by later slices. */
export function App() {
    const [auth, setAuth] = useState<AuthState>('checking');

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

    async function logOut() {
        await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin' });
        setAuth('signed-out');
    }

    return (
        <main>
            <h1>Patient Dashboard</h1>
            {auth === 'signed-out' && <a href="/auth/login">Log in with OpenEMR</a>}
            {auth === 'signed-in' && (
                <button type="button" onClick={() => void logOut()}>
                    Log out
                </button>
            )}
        </main>
    );
}
