import { Hono } from 'hono';
import { serveStatic } from '@hono/node-server/serve-static';

export interface AppOptions {
    /** Directory holding the built SPA (index.html and assets). Omitted in unit tests. */
    staticRoot?: string;
}

/** Builds the BFF: health check, and the built SPA with an index.html fallback for client routes. */
export function createApp(options: AppOptions = {}): Hono {
    const app = new Hono();

    app.get('/healthz', (c) => c.json({ ok: true }));

    if (options.staticRoot !== undefined) {
        const root = options.staticRoot;
        app.use('/*', serveStatic({ root }));
        app.get('*', serveStatic({ root, path: 'index.html' }));
    }

    return app;
}
