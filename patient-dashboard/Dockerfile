# The patient dashboard: the built React app and the Node BFF that serves it, in one image.
# Built on the droplet by deploy/deploy.sh; see deploy/README.md.
FROM node:24-alpine
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build
ENV NODE_ENV=production BFF_HOST=0.0.0.0 BFF_PORT=5180
USER node
EXPOSE 5180
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD wget -qO- http://127.0.0.1:5180/healthz || exit 1
# tsx runs the TypeScript server directly, as `npm start` does in development.
CMD ["node", "--import", "tsx", "server/index.ts"]
