import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { loadAppConfig } from '../../../server/appConfig';

function configFile(contents: string): string {
    const dir = mkdtempSync(join(tmpdir(), 'pd-config-'));
    const path = join(dir, 'hidden-cards.json');
    writeFileSync(path, contents);
    return path;
}

describe('app configuration', () => {
    it('serves the hidden cards from the config file', async () => {
        const appConfig = loadAppConfig(configFile('{ "hiddenCards": ["card_prescriptions"] }'));
        const app = createApp({ appConfig });

        const res = await app.request('/app-config');

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({ hiddenCards: ['card_prescriptions'] });
    });

    it('fails fast on an unknown card key', () => {
        expect(() => loadAppConfig(configFile('{ "hiddenCards": ["card_allergys"] }'))).toThrow(/card_allergys/);
    });

    it('treats a missing file as nothing hidden', () => {
        expect(loadAppConfig(join(tmpdir(), 'does-not-exist', 'hidden-cards.json'))).toEqual({ hiddenCards: [] });
    });
});
