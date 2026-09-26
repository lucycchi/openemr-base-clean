import { Hono } from 'hono';
import { serveStatic } from '@hono/node-server/serve-static';
import { authRoutes } from './auth';
import type { AuthDeps } from './auth';

export interface AppOptions {
    /** Directory holding the built SPA (index.html and assets). Omitted in unit tests. */
    staticRoot?: string;
    /** Login and session routes under /auth. Omitted when a test only needs the health check. */
    auth?: AuthDeps;
}

/** Builds the BFF: health check, /auth routes, and the built SPA with an index.html fallback. */
export function createApp(options: AppOptions = {}): Hono {
    const app = new Hono();

    app.get('/healthz', (c) => c.json({ ok: true }));

    if (options.auth !== undefined) {
        app.route('/auth', authRoutes(options.auth));
    }

    if (options.staticRoot !== undefined) {
        const root = options.staticRoot;
        app.use('/*', serveStatic({ root }));
        app.get('*', serveStatic({ root, path: 'index.html' }));
    }

    return app;
}
