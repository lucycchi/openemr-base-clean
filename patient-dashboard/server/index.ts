/**
 * The BFF's starting point: the file Node runs to switch the server on.
 *
 * Runs on the server (inside the Docker container on the droplet, or `npm start` on a developer's
 * machine). It reads the settings from environment variables (named values handed to the program by
 * Docker or the .env file, such as OEMR_BASE, the OpenEMR address), builds each piece once, wires them
 * together with createApp (app.ts), and starts listening for the browser's requests. Nothing comes back
 * out of this file; if a setting is missing it stops with an error before listening.
 */
import { readFileSync } from 'node:fs';
import { serve } from '@hono/node-server';
import { createApp } from './app';
import { loadAppConfig } from './appConfig';
import { loadConfig } from './config';
import { createOAuthClient } from './oauth';
import { SessionStore } from './session';
import { createSystemTokenSource } from './systemToken';

// process.env holds the environment variables. `??` means "if that is not set, use this instead".
const config = loadConfig(process.env);
const appConfig = loadAppConfig(process.env.HIDDEN_CARDS_FILE ?? 'config/hidden-cards.json');
// The clock, as a function every part shares, so tests can swap in a fake one.
const now = () => Date.now();

// The one place logged-in sessions are kept (in memory). Once a minute, clear out sessions that have
// been idle too long; `.unref()` lets the server shut down without waiting for this timer.
const store = new SessionStore({ ttlMs: config.sessionTtlMs, now });
setInterval(() => store.sweep(), 60_000).unref();

// Talks to OpenEMR's login (OAuth) service on the user's behalf.
const oauth = createOAuthClient({
    base: config.oemrBase,
    clientId: config.clientId,
    clientSecret: config.clientSecret,
    scope: config.scope,
    redirectUri: config.redirectUri,
});

// The server's own OpenEMR login, used only to read staff and facility names (see systemToken.ts).
// Its private key is read from a file on disk; the scope asks to read Practitioner and Organization only.
const systemToken = createSystemTokenSource({
    tokenUrl: `${config.oemrBase}/oauth2/default/token`,
    clientId: config.namesClientId,
    privateKeyPem: readFileSync(config.namesKeyFile, 'utf8'),
    scope: 'system/Practitioner.rs system/Organization.rs',
    now,
});

// Build the app with every part switched on. `${...}` inside backticks inserts a value into text,
// so this builds OpenEMR's API addresses from its base address.
const app = createApp({
    staticRoot: 'dist/web',
    appConfig,
    auth: { store, oauth, now, secureCookie: config.secureCookie },
    fhir: { store, oauth, now, fhirBase: `${config.oemrBase}/apis/default/fhir` },
    listDates: {
        store,
        oauth,
        now,
        apiBase: `${config.oemrBase}/apis/default/api`,
        fhirBase: `${config.oemrBase}/apis/default/fhir`,
    },
    displayNames: { store, oauth, now, fhirBase: `${config.oemrBase}/apis/default/fhir`, systemToken },
});

// Start listening on the configured address and port, and log one line once ready.
serve({ fetch: app.fetch, port: config.port, hostname: config.host }, (info) => {
    console.log(`patient-dashboard BFF listening on http://${config.host}:${info.port} (public ${config.publicUrl})`);
});
