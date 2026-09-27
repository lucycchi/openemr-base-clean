/**
 * The server's memory of who is logged in, and the OpenEMR tokens that go with each login.
 *
 * Runs on the server. A "session" is one logged-in browser: a random id (kept in the browser's cookie,
 * see auth.ts) and, on the server only, the tokens OpenEMR issued at login. Tokens are OpenEMR's "passes"
 * for API calls: an access token that works for a short time, and a refresh token that can be swapped for
 * a new access token without logging in again. Every other server file asks this one for a usable access
 * token before calling OpenEMR (ensureFreshToken). Sessions live only in memory, so restarting the server
 * logs everyone out; idle ones are removed after the timeout.
 */
import { randomBytes } from 'node:crypto';
import { OAuthError } from './oauth';
import type { OAuthClient, TokenResponse } from './oauth';

/** The OpenEMR tokens held for one session. */
export interface Tokens {
    accessToken: string;
    refreshToken?: string;
    /** Epoch milliseconds (the moment the access token stops working, as a clock reading). */
    expiresAt: number;
}

/** The two one-time values from a login that has started but not yet come back from OpenEMR (see auth.ts). */
export interface PendingLogin {
    state: string;
    codeVerifier: string;
}

/** One browser's session. `?` marks parts that may be absent (no login in progress; not logged in yet). */
export interface Session {
    id: string;
    lastSeenAt: number;
    pending?: PendingLogin;
    tokens?: Tokens;
}

/** Refresh when the access token has less than this long left. */
export const REFRESH_WINDOW_MS = 60_000;

/** A login that never completes is dropped after this long, whatever the idle timeout (Opus review 4). */
export const PENDING_TTL_MS = 10 * 60_000;

/** Thrown when the store is full even after removing expired sessions. */
export class SessionLimitError extends Error {}

/**
 * In-memory sessions keyed by a random id. Tokens never leave this store.
 * (A "class" bundles data with the actions on it; `new Map()` is a lookup table from session id to session.)
 */
export class SessionStore {
    private readonly sessions = new Map<string, Session>();
    private readonly ttlMs: number;
    private readonly maxSessions: number;
    private readonly now: () => number;

    /** ttlMs: the idle timeout. maxSessions: the most sessions held at once (default 10,000). */
    constructor(options: { ttlMs: number; now: () => number; maxSessions?: number }) {
        this.ttlMs = options.ttlMs;
        this.maxSessions = options.maxSessions ?? 10_000;
        this.now = options.now;
    }

    /**
     * A new session; throws SessionLimitError rather than grow without bound (unauthenticated callers can
     * create them).
     */
    create(): Session {
        // If full, clear out expired sessions first; if still full, refuse.
        if (this.sessions.size >= this.maxSessions) {
            this.sweep();
        }
        if (this.sessions.size >= this.maxSessions) {
            throw new SessionLimitError('session store is full');
        }
        const session: Session = { id: randomBytes(24).toString('base64url'), lastSeenAt: this.now() };
        this.sessions.set(session.id, session);
        return session;
    }

    /** Returns a live session and slides its idle timeout; expired sessions are removed. */
    get(id: string | undefined): Session | undefined {
        if (id === undefined) {
            return undefined;
        }
        const session = this.sessions.get(id);
        if (session === undefined) {
            return undefined;
        }
        if (this.isExpired(session)) {
            this.sessions.delete(id);
            return undefined;
        }
        session.lastSeenAt = this.now();
        return session;
    }

    /** Forgets a session (logout, or replacing it with a new one at login). */
    delete(id: string): void {
        this.sessions.delete(id);
    }

    /** Removes every idle-expired session, including abandoned login attempts. */
    sweep(): void {
        for (const [id, session] of this.sessions) {
            if (this.isExpired(session)) {
                this.sessions.delete(id);
            }
        }
    }

    /** How many sessions are held (used by tests). */
    size(): number {
        return this.sessions.size;
    }

    /**
     * True when a session has been idle longer than allowed. A session still waiting on a login gets the
     * shorter of the two limits (10 minutes or the idle timeout).
     */
    private isExpired(session: Session): boolean {
        const limit = session.tokens === undefined ? Math.min(PENDING_TTL_MS, this.ttlMs) : this.ttlMs;
        return this.now() - session.lastSeenAt > limit;
    }
}

/**
 * Turns OpenEMR's token answer into the Tokens kept in a session: works out when the access token expires
 * (OpenEMR gives "seconds from now"), and keeps the previous refresh token if OpenEMR did not send a new one.
 */
export function tokensFrom(response: TokenResponse, now: number, previous?: Tokens): Tokens {
    const tokens: Tokens = { accessToken: response.access_token, expiresAt: now + response.expires_in * 1000 };
    const refreshToken = response.refresh_token ?? previous?.refreshToken;
    if (refreshToken !== undefined) {
        tokens.refreshToken = refreshToken;
    }
    return tokens;
}

/**
 * One refresh at a time per session: OpenEMR revokes a refresh token on first use (Fable review 2).
 * This table remembers, per session, a refresh that is already under way, so others can wait for it.
 */
const refreshing = new WeakMap<Session, Promise<Tokens | undefined>>();

/**
 * Asks OpenEMR for a new access token using the refresh token, and stores it in the session.
 * A "Promise" is a result that arrives later; `async`/`await` waits for OpenEMR's answer.
 */
async function refreshSession(
    session: Session,
    oauth: OAuthClient,
    now: () => number,
    tokens: Tokens,
    refreshToken: string,
): Promise<Tokens | undefined> {
    let response;
    try {
        response = await oauth.refresh(refreshToken);
    } catch (error) {
        // OpenEMR refused the refresh token (revoked or expired): the session is logged out, so the
        // proxy answers 401 and the browser returns to the login page. Anything else is an outage.
        if (error instanceof OAuthError && (error.status === 400 || error.status === 401)) {
            delete session.tokens;
            return undefined;
        }
        throw error;
    }
    const refreshed = tokensFrom(response, now(), tokens);
    session.tokens = refreshed;
    return refreshed;
}

/**
 * Returns a usable access token for the session, refreshing it first when it expires within
 * REFRESH_WINDOW_MS. Returns undefined (the callers then answer 401, not logged in) when the session has no
 * tokens, or they have run out and cannot be renewed. Throws if OpenEMR is down during a refresh.
 */
export async function ensureFreshToken(
    session: Session,
    oauth: OAuthClient,
    now: () => number,
): Promise<Tokens | undefined> {
    const tokens = session.tokens;
    if (tokens === undefined) {
        return undefined;
    }
    // More than a minute left: use it as it is.
    if (tokens.expiresAt - now() > REFRESH_WINDOW_MS) {
        return tokens;
    }
    // No refresh token: use the access token while it still works, then give up.
    if (tokens.refreshToken === undefined) {
        return tokens.expiresAt > now() ? tokens : undefined;
    }
    // Every card loads in parallel, so many requests reach this point together; they share one refresh.
    const inFlight = refreshing.get(session);
    if (inFlight !== undefined) {
        return inFlight;
    }
    // Start the refresh; `.finally(...)` removes it from the table once it is done, whether it worked or not.
    const refresh = refreshSession(session, oauth, now, tokens, tokens.refreshToken).finally(() => {
        refreshing.delete(session);
    });
    refreshing.set(session, refresh);
    return refresh;
}
