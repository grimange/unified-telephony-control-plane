# syntax=docker/dockerfile:1.7

ARG GATEWAY_RUNTIME_IMAGE=nginxinc/nginx-unprivileged:1.29.4-alpine

FROM ${GATEWAY_RUNTIME_IMAGE} AS app-prod
ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=unknown

LABEL org.opencontainers.image.title="Unified Telephony Control Plane Gateway" \
      org.opencontainers.image.description="Local HTTP gateway for the Unified Telephony Control Plane Compose platform" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

COPY infrastructure/docker/gateway/nginx.conf /etc/nginx/conf.d/default.conf
COPY apps/api/public /var/www/api-public

EXPOSE 8080
