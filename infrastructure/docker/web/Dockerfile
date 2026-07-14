# syntax=docker/dockerfile:1.7

ARG NODE_IMAGE=node:22.23.1-bookworm-slim
ARG WEB_RUNTIME_IMAGE=nginxinc/nginx-unprivileged:1.29.4-alpine

FROM ${NODE_IMAGE} AS deps
WORKDIR /app
COPY apps/web/package.json apps/web/package-lock.json ./
RUN npm ci

FROM deps AS test
COPY apps/web ./
RUN npm run test \
    && npm run lint \
    && npm run typecheck \
    && npm run build

FROM deps AS build
ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG VITE_UTCP_API_BASE_URL=
ENV VITE_UTCP_WEB_VERSION="${BUILD_VERSION}" \
    VITE_UTCP_WEB_COMMIT="${BUILD_COMMIT}" \
    VITE_UTCP_WEB_BUILT_AT="${BUILD_CREATED}" \
    VITE_UTCP_API_BASE_URL="${VITE_UTCP_API_BASE_URL}"
COPY apps/web ./
RUN npm run build

FROM ${WEB_RUNTIME_IMAGE} AS app-prod
ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=unknown

LABEL org.opencontainers.image.title="Unified Telephony Control Plane Web" \
      org.opencontainers.image.description="Static Vue administration shell for the Unified Telephony Control Plane" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

COPY infrastructure/docker/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 8080
