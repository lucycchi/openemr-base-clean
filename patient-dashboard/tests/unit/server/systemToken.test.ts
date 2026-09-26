import { generateKeyPairSync, verify } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { clientAssertion, createSystemTokenSource, publicJwks } from '../../../server/systemToken';

const TOKEN_URL = 'https://oemr.test/oauth2/default/token';
const { privateKey, publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
const PRIVATE_PEM = privateKey.export({ type: 'pkcs8', format: 'pem' }).toString();

function decode(part: string): Record<string, unknown> {
    return JSON.parse(Buffer.from(part, 'base64url').toString('utf8')) as Record<string, unknown>;
}

describe('system token (SMART backend services client for names, Fable review F1)', () => {
    it('signs an RS384 client assertion OpenEMR accepts: iss and sub the client, aud the token endpoint', () => {
        const jwt = clientAssertion(
            { tokenUrl: TOKEN_URL, clientId: 'names-client', privateKeyPem: PRIVATE_PEM },
            1_700_000_000_000,
            'id-1',
        );
        const [header = '', payload = '', signature = ''] = jwt.split('.');

        expect(decode(header)).toEqual({ alg: 'RS384', typ: 'JWT', kid: publicJwks(PRIVATE_PEM).keys[0]?.kid });
        expect(decode(payload)).toEqual({
            iss: 'names-client',
            sub: 'names-client',
            aud: TOKEN_URL,
            jti: 'id-1',
            iat: 1_700_000_000,
            exp: 1_700_000_300,
        });
        expect(
            verify('sha384', Buffer.from(`${header}.${payload}`), publicKey, Buffer.from(signature, 'base64url')),
        ).toBe(true);
    });

    it('publishes only the public key, with a stable kid', () => {
        const jwks = publicJwks(PRIVATE_PEM);
        const key = jwks.keys[0];

        expect(jwks.keys).toHaveLength(1);
        expect(key).toMatchObject({ kty: 'RSA', alg: 'RS384', use: 'sig' });
        expect(key).not.toHaveProperty('d');
        expect(publicJwks(PRIVATE_PEM).keys[0]?.kid).toBe(key?.kid);
    });

    it('asks for a client_credentials token with the system scopes, and reuses it until a minute before expiry', async () => {
        let clock = 1_700_000_000_000;
        const bodies: URLSearchParams[] = [];
        const source = createSystemTokenSource({
            tokenUrl: TOKEN_URL,
            clientId: 'names-client',
            privateKeyPem: PRIVATE_PEM,
            scope: 'system/Practitioner.rs system/Organization.rs',
            now: () => clock,
            fetchImpl: (async (_url: string | URL | Request, init?: RequestInit) => {
                bodies.push(new URLSearchParams(String(init?.body)));
                return new Response(JSON.stringify({ access_token: `token-${bodies.length}`, expires_in: 300 }), {
                    status: 200,
                });
            }) as typeof fetch,
        });

        expect(await source.getToken()).toBe('token-1');
        clock += 200_000;
        expect(await source.getToken()).toBe('token-1');
        clock += 50_000;
        expect(await source.getToken()).toBe('token-2');

        expect(bodies[0]?.get('grant_type')).toBe('client_credentials');
        expect(bodies[0]?.get('scope')).toBe('system/Practitioner.rs system/Organization.rs');
        expect(bodies[0]?.get('client_assertion_type')).toBe('urn:ietf:params:oauth:client-assertion-type:jwt-bearer');
        expect(bodies[0]?.get('client_assertion')?.split('.')).toHaveLength(3);
        expect(bodies[0]?.get('client_assertion')).not.toBe(bodies[1]?.get('client_assertion'));
    });

    it('a refused token request is an error, never a cached empty token', async () => {
        const source = createSystemTokenSource({
            tokenUrl: TOKEN_URL,
            clientId: 'names-client',
            privateKeyPem: PRIVATE_PEM,
            scope: 'system/Practitioner.rs',
            now: () => 1_700_000_000_000,
            fetchImpl: (async () => new Response('{"error":"invalid_client"}', { status: 401 })) as typeof fetch,
        });

        await expect(source.getToken()).rejects.toThrow(/401/);
    });
});
