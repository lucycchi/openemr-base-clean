import { describe, expect, it } from 'vitest';
import { loadConfig } from '../../../server/config';

const base = {
    OEMR_BASE: 'https://oemr.test/',
    OEMR_CLIENT_ID: 'app',
    OEMR_CLIENT_SECRET: 'secret',
    OEMR_SCOPE: 'openid',
    PUBLIC_URL: 'http://localhost:5180',
    NAMES_CLIENT_ID: 'names',
};

describe('BFF configuration', () => {
    it('reads the names client (the server-only lookup, Fable review F1), with a default key file', () => {
        const config = loadConfig(base);

        expect(config.namesClientId).toBe('names');
        expect(config.namesKeyFile).toBe('certs/names-client-key.pem');
        expect(loadConfig({ ...base, NAMES_KEY_FILE: '/run/secrets/names.pem' }).namesKeyFile).toBe(
            '/run/secrets/names.pem',
        );
    });

    it('fails fast without the names client, rather than silently showing "Name unavailable" to everyone', () => {
        expect(() => loadConfig({ ...base, NAMES_CLIENT_ID: '' })).toThrow(/NAMES_CLIENT_ID/);
    });

    it("the session idles out after IDLE_TIMEOUT_SECONDS, default 7200, mirroring OpenEMR's timeout global", () => {
        expect(loadConfig(base).sessionTtlMs).toBe(7_200_000);
        expect(loadConfig({ ...base, IDLE_TIMEOUT_SECONDS: '900' }).sessionTtlMs).toBe(900_000);
        for (const bad of ['0', '-5', '1.5', 'soon']) {
            expect(() => loadConfig({ ...base, IDLE_TIMEOUT_SECONDS: bad }), bad).toThrow(/IDLE_TIMEOUT_SECONDS/);
        }
    });
});
