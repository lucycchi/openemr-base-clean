import { defineConfig, devices } from '@playwright/test';

// The e2e project runs against the BFF, which serves the built SPA.
// The parity project (added in slice 01-04-01) also drives the old dashboard.
const PORT = Number(process.env.BFF_PORT ?? 5180);
const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: 'tests',
    fullyParallel: false,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'e2e', testDir: 'tests/e2e', use: { ...devices['Desktop Chrome'] } },
        { name: 'parity', testDir: 'tests/parity', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'npm run build && npm start',
        url: `${BASE_URL}/healthz`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
