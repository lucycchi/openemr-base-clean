import { describe, expect, it } from 'vitest';
import { userIdFromToken } from '../../../server/idToken';

const NOW_MS = 1_000_000_000;

/** An unsigned JWT with the given claims; only the payload matters to userIdFromToken. */
function jwt(claims: Record<string, unknown>): string {
    const part = (value: unknown) => Buffer.from(JSON.stringify(value)).toString('base64url');
    return `${part({ alg: 'RS256', typ: 'JWT' })}.${part(claims)}.signature`;
}

const valid = {
    sub: 'user-uuid-1',
    aud: 'client-1',
    exp: NOW_MS / 1000 + 3600,
    iss: 'https://oemr.test/oauth2/default',
};

// OpenEMR's ID token names the user in `sub` and is addressed (`aud`) to the dashboard's client id
// (src/Common/Auth/OpenIDConnect/IdTokenSMARTResponse.php, getBuilder).
describe('userIdFromToken', () => {
    it('reads the user id from an ID token addressed to this client', () => {
        expect(userIdFromToken(jwt(valid), 'client-1', NOW_MS)).toBe('user-uuid-1');
    });

    it('accepts an audience list that includes this client', () => {
        expect(userIdFromToken(jwt({ ...valid, aud: ['other', 'client-1'] }), 'client-1', NOW_MS)).toBe('user-uuid-1');
    });

    it('refuses a token addressed to another client', () => {
        expect(userIdFromToken(jwt({ ...valid, aud: 'client-2' }), 'client-1', NOW_MS)).toBeUndefined();
    });

    it('refuses an expired token', () => {
        expect(userIdFromToken(jwt({ ...valid, exp: NOW_MS / 1000 - 1 }), 'client-1', NOW_MS)).toBeUndefined();
    });

    it('refuses a token with no user', () => {
        expect(userIdFromToken(jwt({ ...valid, sub: '' }), 'client-1', NOW_MS)).toBeUndefined();
        expect(userIdFromToken(jwt({ ...valid, sub: 7 }), 'client-1', NOW_MS)).toBeUndefined();
    });

    it('refuses something that is not a JWT', () => {
        expect(userIdFromToken('not-a-jwt', 'client-1', NOW_MS)).toBeUndefined();
        expect(userIdFromToken('a.!!!.c', 'client-1', NOW_MS)).toBeUndefined();
        expect(userIdFromToken(undefined, 'client-1', NOW_MS)).toBeUndefined();
    });
});
