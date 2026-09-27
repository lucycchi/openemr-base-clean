import { defineConfig, devices } from '@playwright/test';

// Smoke tests against the deployed dashboard (ARC-05). No local server, and no dev certificate:
// the droplet's Caddy serves a real one. DEPLOYED_URL overrides the default host.
export default defineConfig({
    testDir: 'tests/deployed',
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: process.env.DEPLOYED_URL ?? 'https://dashboard.146-190-139-37.sslip.io',
        trace: 'retain-on-failure',
    },
    projects: [{ name: 'deployed', use: { ...devices['Desktop Chrome'] } }],
});
