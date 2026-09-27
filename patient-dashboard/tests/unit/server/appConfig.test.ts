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
        expect(await res.json()).toEqual({
            hiddenCards: ['card_prescriptions'],
            ageDisplay: { format: 0, limitYears: 3 },
            encounterPageSize: 20,
            dateDisplayFormat: 0,
            idleTimeoutSeconds: 7200,
        });
    });

    it('fails fast on an unknown card key', () => {
        expect(() => loadAppConfig(configFile('{ "hiddenCards": ["card_allergys"] }'))).toThrow(/card_allergys/);
    });

    it('treats a missing file as nothing hidden', () => {
        expect(loadAppConfig(join(tmpdir(), 'does-not-exist', 'hidden-cards.json'))).toEqual({
            hiddenCards: [],
            ageDisplay: { format: 0, limitYears: 3 },
            encounterPageSize: 20,
            dateDisplayFormat: 0,
            idleTimeoutSeconds: 7200,
        });
    });

    it('reads the age display settings (the age_display_format and age_display_limit globals) from the environment', () => {
        const config = loadAppConfig(join(tmpdir(), 'none.json'), { AGE_DISPLAY_FORMAT: '1', AGE_DISPLAY_LIMIT: '5' });

        expect(config.ageDisplay).toEqual({ format: 1, limitYears: 5 });
    });

    it('rejects an age display format other than 0 or 1', () => {
        expect(() => loadAppConfig(join(tmpdir(), 'none.json'), { AGE_DISPLAY_FORMAT: '2' })).toThrow(
            /AGE_DISPLAY_FORMAT/,
        );
    });

    it('reads the encounter page size (the encounter_page_size global) from the environment; 0 means all', () => {
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { ENCOUNTER_PAGE_SIZE: '50' }).encounterPageSize).toBe(50);
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { ENCOUNTER_PAGE_SIZE: '0' }).encounterPageSize).toBe(0);
    });

    it('rejects an encounter page size that is not a whole number of 0 or more', () => {
        for (const bad of ['-1', '2.5', 'twenty', '']) {
            expect(() => loadAppConfig(join(tmpdir(), 'none.json'), { ENCOUNTER_PAGE_SIZE: bad }), bad).toThrow(
                /ENCOUNTER_PAGE_SIZE/,
            );
        }
    });

    it('reads the site date format (the date_display_format global): 0, 1 or 2', () => {
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { DATE_DISPLAY_FORMAT: '1' }).dateDisplayFormat).toBe(1);
        expect(() => loadAppConfig(join(tmpdir(), 'none.json'), { DATE_DISPLAY_FORMAT: '3' })).toThrow(
            /DATE_DISPLAY_FORMAT/,
        );
    });

    it('DISABLE_PRESCRIPTIONS hides the Prescriptions card, as the disable_prescriptions global does', () => {
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { DISABLE_PRESCRIPTIONS: '1' }).hiddenCards).toEqual([
            'card_prescriptions',
        ]);
        expect(
            loadAppConfig(configFile('{ "hiddenCards": ["card_prescriptions"] }'), { DISABLE_PRESCRIPTIONS: '1' })
                .hiddenCards,
        ).toEqual(['card_prescriptions']);
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { DISABLE_PRESCRIPTIONS: '0' }).hiddenCards).toEqual([]);
        expect(() => loadAppConfig(join(tmpdir(), 'none.json'), { DISABLE_PRESCRIPTIONS: 'yes' })).toThrow(
            /DISABLE_PRESCRIPTIONS/,
        );
    });

    it('serves the idle timeout, so the page can sign itself out as OpenEMR does', () => {
        expect(loadAppConfig(join(tmpdir(), 'none.json'), { IDLE_TIMEOUT_SECONDS: '900' }).idleTimeoutSeconds).toBe(
            900,
        );
    });
});
