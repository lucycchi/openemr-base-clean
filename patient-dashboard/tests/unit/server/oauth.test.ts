import { describe, expect, it } from 'vitest';
import { createOAuthClient } from '../../../server/oauth';

const config = {
    base: 'https://oemr.test',
    clientId: 'client-1',
    clientSecret: 'secret',
    scope: 'openid api:fhir',
    redirectUri: 'https://app.test/auth/callback',
};

/** A token endpoint that answers every request with `body`. */
const answering = (body: unknown) =>
    (async () => new Response(JSON.stringify(body), { status: 200 })) as unknown as typeof fetch;

// The login keeps the OpenID Connect ID token OpenEMR returns, so auth.ts can read who signed in
// (Codex challenge review, 2026-09-27: parseTokenResponse used to drop it).
describe('createOAuthClient token answers', () => {
    it('keeps the ID token OpenEMR returns with the access token', async () => {
        const client = createOAuthClient(
            config,
            answering({
                access_token: 'at',
                expires_in: 3600,
                refresh_token: 'rt',
                scope: 'openid',
                id_token: 'a.b.c',
            }),
        );
        expect(await client.exchangeCode('code', 'verifier')).toEqual({
            access_token: 'at',
            expires_in: 3600,
            refresh_token: 'rt',
            scope: 'openid',
            id_token: 'a.b.c',
        });
    });

    it('leaves the ID token out when OpenEMR sends none, or sends something that is not text', async () => {
        const none = createOAuthClient(config, answering({ access_token: 'at', expires_in: 3600 }));
        expect(await none.exchangeCode('code', 'verifier')).toEqual({ access_token: 'at', expires_in: 3600 });
        const odd = createOAuthClient(config, answering({ access_token: 'at', expires_in: 3600, id_token: 7 }));
        expect(await odd.exchangeCode('code', 'verifier')).toEqual({ access_token: 'at', expires_in: 3600 });
    });
});
