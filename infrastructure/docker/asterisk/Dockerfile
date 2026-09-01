ARG ASTERISK_BASE_IMAGE=andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9
FROM ${ASTERISK_BASE_IMAGE}

ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=unknown

LABEL org.opencontainers.image.title="UTCP Asterisk ARI runtime" \
      org.opencontainers.image.description="Repository-built Asterisk ARI runtime for the Unified Telephony Control Plane" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

RUN mkdir -p /opt/utcp-asterisk-config

COPY infrastructure/docker/asterisk/config/ /opt/utcp-asterisk-config/
COPY infrastructure/docker/asterisk/entrypoint /usr/local/bin/utcp-asterisk-entrypoint
COPY infrastructure/docker/asterisk/readiness /usr/local/bin/utcp-asterisk-readiness
COPY infrastructure/docker/media/reference-tone.wav.base64 /tmp/utcp-reference-tone.wav.base64

RUN chmod 0755 /usr/local/bin/utcp-asterisk-entrypoint /usr/local/bin/utcp-asterisk-readiness \
    && mkdir -p /var/lib/asterisk/sounds/en \
    && mkdir -p /var/spool/asterisk/recording \
    && base64 -d /tmp/utcp-reference-tone.wav.base64 > /var/lib/asterisk/sounds/reference-tone.wav \
    && cp /var/lib/asterisk/sounds/reference-tone.wav /var/lib/asterisk/sounds/en/reference-tone.wav \
    && chown -R asterisk:asterisk /opt/utcp-asterisk-config /var/lib/asterisk/sounds /var/spool/asterisk/recording \
    && chmod 0750 /var/spool/asterisk/recording \
    && rm /tmp/utcp-reference-tone.wav.base64

USER 1000:1000
EXPOSE 8088
EXPOSE 5060/udp
ENTRYPOINT ["/usr/local/bin/utcp-asterisk-entrypoint"]
