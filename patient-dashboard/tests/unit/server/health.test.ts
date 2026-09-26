import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';

describe('BFF health endpoint', () => {
    it('GET /healthz returns 200 {"ok":true}', async () => {
        const app = createApp();

        const res = await app.request('/healthz');

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({ ok: true });
    });
});
