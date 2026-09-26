import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// The SPA source lives in web/; the BFF serves the built files from dist/web.
// In development the Vite server proxies /auth and /api to the BFF so the
// browser only ever talks to one origin.
const BFF_URL = process.env.BFF_URL ?? 'http://127.0.0.1:5180';

export default defineConfig({
    root: 'web',
    plugins: [react()],
    build: {
        outDir: '../dist/web',
        emptyOutDir: true,
    },
    server: {
        port: 5181,
        strictPort: true,
        proxy: {
            '/auth': BFF_URL,
            '/api': BFF_URL,
        },
    },
    test: {
        root: '.',
        include: ['tests/unit/**/*.test.{ts,tsx}'],
        environment: 'node',
    },
});
