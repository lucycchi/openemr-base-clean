import { serve } from '@hono/node-server';
import { createApp } from './app';
import { loadAppConfig } from './appConfig';
import { loadConfig } from './config';
import { createOAuthClient } from './oauth';
import { SessionStore } from './session';

const config = loadConfig(process.env);
const appConfig = loadAppConfig(process.env.HIDDEN_CARDS_FILE ?? 'config/hidden-cards.json');
const now = () => Date.now();

const store = new SessionStore({ ttlMs: config.sessionTtlMs, now });
setInterval(() => store.sweep(), 60_000).unref();

const oauth = createOAuthClient({
    base: config.oemrBase,
    clientId: config.clientId,
    clientSecret: config.clientSecret,
    scope: config.scope,
    redirectUri: config.redirectUri,
});

const app = createApp({
    staticRoot: 'dist/web',
    appConfig,
    auth: { store, oauth, now, secureCookie: config.secureCookie },
    fhir: { store, oauth, now, fhirBase: `${config.oemrBase}/apis/default/fhir` },
    medicationEndDates: { store, oauth, now, apiBase: `${config.oemrBase}/apis/default/api` },
});

serve({ fetch: app.fetch, port: config.port, hostname: config.host }, (info) => {
    console.log(`patient-dashboard BFF listening on http://${config.host}:${info.port} (public ${config.publicUrl})`);
});
