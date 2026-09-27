/**
 * The BFF's front door: it decides which web address goes to which piece of the server.
 *
 * Runs on the server (the droplet in production, the developer's machine in development), inside the
 * Node process started by index.ts. The browser's requests come in here and are handed on:
 * - /healthz: a "yes, I am running" answer for the deploy script and Docker's health check.
 * - /app-config: the site's settings for the page (hidden cards, date format, idle timeout).
 * - /auth/...: logging in and out with OpenEMR (auth.ts).
 * - /api/fhir/...: read-only FHIR requests, checked and passed on to OpenEMR (fhirProxy.ts).
 * - /api/display-names: staff and facility names (displayNames.ts).
 * - /api/list-dates: medication, allergy and problem list details from the Standard REST API (listDates.ts).
 * - anything else: the built React page itself (index.html, scripts, styles).
 *
 * "Hono" is the small web-server library that does the routing. Each piece is optional so that tests can
 * build an app with only the parts they need.
 */
import { Hono } from 'hono';
import { serveStatic } from '@hono/node-server/serve-static';
import { authRoutes } from './auth';
import type { AuthDeps } from './auth';
import { fhirProxyRoutes } from './fhirProxy';
import type { FhirProxyDeps } from './fhirProxy';
import { displayNamesRoutes } from './displayNames';
import type { DisplayNamesDeps } from './displayNames';
import { listDatesRoutes } from './listDates';
import type { ListDatesDeps } from './listDates';
import type { AppConfig } from './appConfig';
import { cardSettingsRoutes } from './cardSettings';
import { openemrLinkRoutes } from './openemrLink';
import type { OpenEmrLinkDeps } from './openemrLink';
import { prescriptionWritesRoutes } from './prescriptionWrites';
import type { PrescriptionWritesDeps } from './prescriptionWrites';
import type { CardSettingsDeps } from './cardSettings';

/**
 * Everything createApp can be given. A "?" after a name means that part is optional: when it is left out,
 * the matching web addresses simply do not exist.
 */
export interface AppOptions {
    /** Directory holding the built SPA (index.html and assets). Omitted in unit tests. */
    staticRoot?: string;
    /** Login and session routes under /auth. Omitted when a test only needs the health check. */
    auth?: AuthDeps;
    /** Allow-listed FHIR reads under /api/fhir. */
    fhir?: FhirProxyDeps;
    /** Medication and allergy list dates from the standard API, under /api/list-dates (BM-044, BM-047). */
    listDates?: ListDatesDeps;
    /** Staff and facility names read with the BFF's system client, under /api/display-names (Fable F1). */
    displayNames?: DisplayNamesDeps;
    /** Site settings for the SPA (hidden cards), served at /app-config. */
    appConfig?: AppConfig;
    cardSettings?: CardSettingsDeps;
    prescriptionWrites?: PrescriptionWritesDeps;
    openemrLink?: OpenEmrLinkDeps;
}

/**
 * Builds the BFF: health check, /auth routes, the /api/fhir proxy, and the built SPA with an index.html fallback.
 * ("SPA" = single-page app: the React page, which handles its own screens once loaded.)
 */
export function createApp(options: AppOptions = {}): Hono {
    const app = new Hono();

    // `(c) => ...` is a short function that Hono runs for each request; `c` holds the request and builds
    // the reply. c.json(...) sends a JSON reply, here simply {"ok": true}.
    app.get('/healthz', (c) => c.json({ ok: true }));

    // Each block below adds a group of web addresses only if that part was supplied ("!== undefined"
    // means "was given").
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
    if (options.displayNames !== undefined) {
        app.route('/api/display-names', displayNamesRoutes(options.displayNames));
    }
    if (options.listDates !== undefined) {
        app.route('/api/list-dates', listDatesRoutes(options.listDates));
    }
    if (options.cardSettings !== undefined) {
        app.route('/api/card-settings', cardSettingsRoutes(options.cardSettings));
    }
    if (options.prescriptionWrites !== undefined) {
        app.route('/api/prescriptions', prescriptionWritesRoutes(options.prescriptionWrites));
    }
    if (options.openemrLink !== undefined) {
        app.route('/openemr', openemrLinkRoutes(options.openemrLink));
    }

    // Last of all, the page's own files. If no file matches the address, send index.html anyway, so a
    // reload on any dashboard address still opens the app ("*" matches any address).
    if (options.staticRoot !== undefined) {
        const root = options.staticRoot;
        app.use('/*', serveStatic({ root }));
        app.get('*', serveStatic({ root, path: 'index.html' }));
    }

    return app;
}
