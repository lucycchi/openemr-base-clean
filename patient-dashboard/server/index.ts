import { serve } from '@hono/node-server';
import { createApp } from './app';
import { loadConfig } from './config';
import { createOAuthClient } from './oauth';
import { SessionStore } from './session';

const config = loadConfig(process.env);
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
    auth: { store, oauth, now, secureCookie: config.secureCookie },
});

serve({ fetch: app.fetch, port: config.port, hostname: config.host }, (info) => {
    console.log(`patient-dashboard BFF listening on http://${config.host}:${info.port} (public ${config.publicUrl})`);
});
