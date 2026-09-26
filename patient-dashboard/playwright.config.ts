import { defineConfig, devices } from '@playwright/test';
import { devCertTrustArgs } from './tests/support/cert';

// The e2e project runs against the BFF, which serves the built SPA.
// The parity project (added in slice 01-04-01) also drives the old dashboard.
const PORT = Number(process.env.BFF_PORT ?? 5180);
// localhost (not 127.0.0.1) so the session cookie and the OAuth redirect_uri share one host.
const BASE_URL = `http://localhost:${PORT}`;

export default defineConfig({
    testDir: 'tests',
    fullyParallel: false,
    // One worker: the browser tests share one OpenEMR dev stack and user, and two OAuth logins
    // running at the same moment made OpenEMR reject one token exchange (HTTP 400).
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        launchOptions: { args: devCertTrustArgs() },
    },
    projects: [
        { name: 'e2e', testDir: 'tests/e2e', use: { ...devices['Desktop Chrome'] } },
        { name: 'parity', testDir: 'tests/parity', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'npm run build && npm start',
        // Health check on 127.0.0.1: the BFF binds IPv4 only, and localhost may resolve to ::1 first.
        url: `http://127.0.0.1:${PORT}/healthz`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
});
