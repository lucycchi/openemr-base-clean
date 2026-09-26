import { readFileSync } from 'node:fs';
import { serve } from '@hono/node-server';
import { createApp } from './app';
import { loadAppConfig } from './appConfig';
import { loadConfig } from './config';
import { createOAuthClient } from './oauth';
import { SessionStore } from './session';
import { createSystemTokenSource } from './systemToken';

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

const systemToken = createSystemTokenSource({
    tokenUrl: `${config.oemrBase}/oauth2/default/token`,
    clientId: config.namesClientId,
    privateKeyPem: readFileSync(config.namesKeyFile, 'utf8'),
    scope: 'system/Practitioner.rs system/Organization.rs',
    now,
});

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

serve({ fetch: app.fetch, port: config.port, hostname: config.host }, (info) => {
    console.log(`patient-dashboard BFF listening on http://${config.host}:${info.port} (public ${config.publicUrl})`);
});
