import { describe, expect, it } from 'vitest';
import { userIdFromIdToken } from '../../../server/idToken';

const NOW_MS = 1_000_000_000;
const ISSUER = 'https://oemr.test/oauth2/default';
const EXPECT = { clientId: 'client-1', issuer: ISSUER };

/** An unsigned JWT with the given claims; only the payload matters to userIdFromIdToken. */
function jwt(claims: Record<string, unknown>): string {
    const part = (value: unknown) => Buffer.from(JSON.stringify(value)).toString('base64url');
    return `${part({ alg: 'RS256', typ: 'JWT' })}.${part(claims)}.signature`;
}

const valid = {
    sub: 'user-uuid-1',
    aud: 'client-1',
    exp: NOW_MS / 1000 + 3600,
    iss: ISSUER,
};

// OpenEMR's ID token names the user in `sub` and is addressed (`aud`) to the dashboard's client id
// (src/Common/Auth/OpenIDConnect/IdTokenSMARTResponse.php, getBuilder).
describe('userIdFromIdToken', () => {
    it('reads the user id from an ID token addressed to this client', () => {
        expect(userIdFromIdToken(jwt(valid), EXPECT, NOW_MS)).toBe('user-uuid-1');
    });

    it('accepts an audience list that includes this client', () => {
        expect(userIdFromIdToken(jwt({ ...valid, aud: ['other', 'client-1'] }), EXPECT, NOW_MS)).toBe('user-uuid-1');
    });

    it('refuses a token addressed to another client', () => {
        expect(userIdFromIdToken(jwt({ ...valid, aud: 'client-2' }), EXPECT, NOW_MS)).toBeUndefined();
    });

    it('refuses a token from another issuer, or with none', () => {
        expect(
            userIdFromIdToken(jwt({ ...valid, iss: 'https://evil.test/oauth2/default' }), EXPECT, NOW_MS),
        ).toBeUndefined();
        expect(userIdFromIdToken(jwt({ ...valid, iss: undefined }), EXPECT, NOW_MS)).toBeUndefined();
    });

    it('refuses an expired token', () => {
        expect(userIdFromIdToken(jwt({ ...valid, exp: NOW_MS / 1000 - 1 }), EXPECT, NOW_MS)).toBeUndefined();
    });

    it('refuses a token with no user', () => {
        expect(userIdFromIdToken(jwt({ ...valid, sub: '' }), EXPECT, NOW_MS)).toBeUndefined();
        expect(userIdFromIdToken(jwt({ ...valid, sub: 7 }), EXPECT, NOW_MS)).toBeUndefined();
    });

    it('refuses something that is not a JWT', () => {
        expect(userIdFromIdToken('not-a-jwt', EXPECT, NOW_MS)).toBeUndefined();
        expect(userIdFromIdToken('a.!!!.c', EXPECT, NOW_MS)).toBeUndefined();
        expect(userIdFromIdToken(undefined, EXPECT, NOW_MS)).toBeUndefined();
    });
});
