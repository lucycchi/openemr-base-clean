import { Hono } from 'hono';
import { serveStatic } from '@hono/node-server/serve-static';
import { authRoutes } from './auth';
import type { AuthDeps } from './auth';
import { fhirProxyRoutes } from './fhirProxy';
import type { FhirProxyDeps } from './fhirProxy';
import { medicationEndDatesRoutes } from './medicationEndDates';
import type { MedicationEndDatesDeps } from './medicationEndDates';
import type { AppConfig } from './appConfig';

export interface AppOptions {
    /** Directory holding the built SPA (index.html and assets). Omitted in unit tests. */
    staticRoot?: string;
    /** Login and session routes under /auth. Omitted when a test only needs the health check. */
    auth?: AuthDeps;
    /** Allow-listed FHIR reads under /api/fhir. */
    fhir?: FhirProxyDeps;
    /** Medication-list end dates from the standard API, under /api/medication-end-dates (BM-044). */
    medicationEndDates?: MedicationEndDatesDeps;
    /** Site settings for the SPA (hidden cards), served at /app-config. */
    appConfig?: AppConfig;
}

/** Builds the BFF: health check, /auth routes, the /api/fhir proxy, and the built SPA with an index.html fallback. */
export function createApp(options: AppOptions = {}): Hono {
    const app = new Hono();

    app.get('/healthz', (c) => c.json({ ok: true }));

    if (options.appConfig !== undefined) {
        const appConfig = options.appConfig;
        app.get('/app-config', (c) => c.json(appConfig));
    }

    if (options.auth !== undefined) {
        app.route('/auth', authRoutes(options.auth));
    }

    if (options.fhir !== undefined) {
        app.route('/api/fhir', fhirProxyRoutes(options.fhir));
    }
    if (options.medicationEndDates !== undefined) {
        app.route('/api/medication-end-dates', medicationEndDatesRoutes(options.medicationEndDates));
    }

    if (options.staticRoot !== undefined) {
        const root = options.staticRoot;
        app.use('/*', serveStatic({ root }));
        app.get('*', serveStatic({ root, path: 'index.html' }));
    }

    return app;
}
