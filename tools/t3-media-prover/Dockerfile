ARG T3_MEDIA_PROVER_BROWSER_IMAGE=mcr.microsoft.com/playwright:v1.61.1-noble
FROM ${T3_MEDIA_PROVER_BROWSER_IMAGE}

ARG T3_MEDIA_PROVER_PLAYWRIGHT_VERSION=1.61.1

WORKDIR /opt/utcp/t3-media-prover
COPY tools/t3-media-prover/package.json ./
RUN npm install --omit=dev --no-audit --no-fund --ignore-scripts \
    && npm cache clean --force \
    && test "$(node -p "require('./package.json').dependencies['@playwright/test']")" = "$T3_MEDIA_PROVER_PLAYWRIGHT_VERSION"
COPY tools/t3-media-prover/prover.mjs ./prover.mjs

USER 1000:1000
ENTRYPOINT ["node", "/opt/utcp/t3-media-prover/prover.mjs"]
