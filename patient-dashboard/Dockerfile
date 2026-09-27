# The patient dashboard: the built React app and the Node BFF that serves it, in one image.
# Built on the droplet by deploy/deploy.sh; see deploy/README.md.
#
# A Dockerfile is a recipe for a container image: a sealed box holding the program and everything it needs
# to run. Each line below is one step. The result runs on the droplet, started by deploy/docker-compose.yml.

# Start from a small Linux with Node 24 already installed.
FROM node:24-alpine
WORKDIR /app
# Install the exact library versions in package-lock.json. The two files are copied first on their own so
# Docker can reuse this slow step when only the source code has changed.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# Copy the rest of the source and build the React page into dist/web.
COPY . .
RUN npm run build
# Fixed settings: production mode, listen on all addresses inside the container, on port 5180.
ENV NODE_ENV=production BFF_HOST=0.0.0.0 BFF_PORT=5180
# Run as the ordinary "node" user rather than the all-powerful root user.
USER node
EXPOSE 5180
# Every 30 seconds Docker asks the server's /healthz address; if it stops answering, the container is
# marked unhealthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD wget -qO- http://127.0.0.1:5180/healthz || exit 1
# tsx runs the TypeScript server directly, as `npm start` does in development.
CMD ["node", "--import", "tsx", "server/index.ts"]
