import { serve } from '@hono/node-server';
import { createApp } from './app';

const port = Number(process.env.BFF_PORT ?? 5180);
const hostname = process.env.BFF_HOST ?? '127.0.0.1';

const app = createApp({ staticRoot: 'dist/web' });

serve({ fetch: app.fetch, port, hostname }, (info) => {
    console.log(`patient-dashboard BFF listening on http://${hostname}:${info.port}`);
});
