ARG RTPENGINE_BASE_IMAGE=debian:trixie-slim@sha256:020c0d20b9880058cbe785a9db107156c3c75c2ac944a6aa7ab59f2add76a7bd
FROM ${RTPENGINE_BASE_IMAGE}

ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=local
ARG RTPENGINE_VERSION=mr26.0.1.19
ARG RTPENGINE_SOURCE_COMMIT=3552ac76cceb24e3ec176b77ec9c25554ae5923b
ARG TARGETARCH

LABEL org.opencontainers.image.title="UTCP rtpengine media relay" \
      org.opencontainers.image.description="Repository-built rtpengine userspace media relay for the UTCP T3-S1 foundation" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}" \
      org.opencontainers.image.vendor="Unified Telephony Control Plane" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"

RUN set -eux; \
    case "${TARGETARCH:-amd64}" in \
      amd64) \
        package="rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_amd64.deb"; \
        sha256="c60c7a1463e454dbcff81bf0fbd07c65dbeac742e5997d0c611f40f09161f950"; \
        ;; \
      arm64) \
        package="rtpengine-daemon_26.0.1.19+0.mr26.0.1.19+gh+trixie_arm64.deb"; \
        sha256="f279457f2356b6d4dc7fc483bb2631f79303a3393dbbeae0db9abd58c8c36906"; \
        ;; \
      *) \
        echo "unsupported TARGETARCH=${TARGETARCH:-unset}" >&2; \
        exit 1; \
        ;; \
    esac; \
    printf '%s\n' \
      'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian/20260713T000000Z trixie main' \
      'deb [check-valid-until=no] https://snapshot.debian.org/archive/debian-security/20260713T000000Z trixie-security main' \
      > /etc/apt/sources.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl python3-minimal; \
    curl --fail --location --show-error --output "/tmp/${package}" \
      "https://github.com/sipwise/rtpengine/releases/download/${RTPENGINE_VERSION}/${package}"; \
    printf '%s  %s\n' "$sha256" "/tmp/${package}" | sha256sum --check; \
    apt-get install -y --no-install-recommends "/tmp/${package}"; \
    test -x /usr/bin/rtpengine; \
    rm -f "/tmp/${package}"; \
    rm -rf /var/lib/apt/lists/*; \
    mkdir -p /tmp/rtpengine /run/rtpengine; \
    chown -R 1000:1000 /tmp/rtpengine /run/rtpengine

COPY infrastructure/docker/rtpengine/entrypoint /usr/local/bin/utcp-rtpengine-entrypoint
COPY infrastructure/docker/rtpengine/address-validation /usr/local/lib/utcp-rtpengine/address-validation
COPY infrastructure/docker/rtpengine/readiness /usr/local/bin/utcp-rtpengine-ng-ping
RUN chmod 0755 /usr/local/bin/utcp-rtpengine-entrypoint /usr/local/lib/utcp-rtpengine/address-validation /usr/local/bin/utcp-rtpengine-ng-ping

USER 1000:1000
EXPOSE 2223/udp 40000-40099/udp 2224/tcp
ENTRYPOINT ["/usr/local/bin/utcp-rtpengine-entrypoint"]
