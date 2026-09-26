import { randomBytes } from 'node:crypto';
import { OAuthError } from './oauth';
import type { OAuthClient, TokenResponse } from './oauth';

export interface Tokens {
    accessToken: string;
    refreshToken?: string;
    /** Epoch milliseconds. */
    expiresAt: number;
}

export interface PendingLogin {
    state: string;
    codeVerifier: string;
}

export interface Session {
    id: string;
    lastSeenAt: number;
    pending?: PendingLogin;
    tokens?: Tokens;
}

/** Refresh when the access token has less than this long left. */
export const REFRESH_WINDOW_MS = 60_000;

/** In-memory sessions keyed by a random id. Tokens never leave this store. */
export class SessionStore {
    private readonly sessions = new Map<string, Session>();
    private readonly ttlMs: number;
    private readonly now: () => number;

    constructor(options: { ttlMs: number; now: () => number }) {
        this.ttlMs = options.ttlMs;
        this.now = options.now;
    }

    create(): Session {
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

    size(): number {
        return this.sessions.size;
    }

    private isExpired(session: Session): boolean {
        return this.now() - session.lastSeenAt > this.ttlMs;
    }
}

export function tokensFrom(response: TokenResponse, now: number, previous?: Tokens): Tokens {
    const tokens: Tokens = { accessToken: response.access_token, expiresAt: now + response.expires_in * 1000 };
    const refreshToken = response.refresh_token ?? previous?.refreshToken;
    if (refreshToken !== undefined) {
        tokens.refreshToken = refreshToken;
    }
    return tokens;
}

/**
 * Returns a usable access token for the session, refreshing it first when it expires within
 * REFRESH_WINDOW_MS. Returns undefined when the session can no longer call the API.
 */
/** One refresh at a time per session: OpenEMR revokes a refresh token on first use (Fable review 2). */
const refreshing = new WeakMap<Session, Promise<Tokens | undefined>>();

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

export async function ensureFreshToken(
    session: Session,
    oauth: OAuthClient,
    now: () => number,
): Promise<Tokens | undefined> {
    const tokens = session.tokens;
    if (tokens === undefined) {
        return undefined;
    }
    if (tokens.expiresAt - now() > REFRESH_WINDOW_MS) {
        return tokens;
    }
    if (tokens.refreshToken === undefined) {
        return tokens.expiresAt > now() ? tokens : undefined;
    }
    // Every card loads in parallel, so many requests reach this point together; they share one refresh.
    const inFlight = refreshing.get(session);
    if (inFlight !== undefined) {
        return inFlight;
    }
    const refresh = refreshSession(session, oauth, now, tokens, tokens.refreshToken).finally(() => {
        refreshing.delete(session);
    });
    refreshing.set(session, refresh);
    return refresh;
}
