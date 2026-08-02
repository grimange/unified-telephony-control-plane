ARG T3_MEDIA_PROVER_BROWSER_IMAGE=mcr.microsoft.com/playwright:v1.61.1-noble
FROM ${T3_MEDIA_PROVER_BROWSER_IMAGE}

ARG T3_MEDIA_PROVER_PLAYWRIGHT_VERSION=1.61.1

WORKDIR /opt/utcp/t3-media-prover
COPY tools/t3-media-prover/package.json ./
RUN npm install --omit=dev --no-audit --no-fund --ignore-scripts \
    && npm cache clean --force \
    && test "$(node -p "require('./package.json').dependencies['@playwright/test']")" = "$T3_MEDIA_PROVER_PLAYWRIGHT_VERSION"
USER root
RUN apt-get update \
    && apt-get install --no-install-recommends --yes libnss3-tools \
    && rm -rf /var/lib/apt/lists/* \
    && command -v certutil >/dev/null \
    && nss_dir="$(mktemp -d)" \
    && certutil -N -d "sql:${nss_dir}" --empty-password \
    && test -f "${nss_dir}/cert9.db" \
    && test -f "${nss_dir}/key4.db" \
    && rm -rf "${nss_dir}"
COPY tools/t3-media-prover/prover.mjs ./prover.mjs

USER 1000:1000
ENV HOME=/home/ubuntu
ENTRYPOINT ["node", "/opt/utcp/t3-media-prover/prover.mjs"]
