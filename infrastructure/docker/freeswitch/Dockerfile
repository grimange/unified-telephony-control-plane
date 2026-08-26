# FreeSWITCH is selected only by the local parity overlay.  The digest is the
# immutable multi-architecture manifest for the pinned upstream image.
ARG FREESWITCH_BASE_IMAGE=docker.io/safarov/freeswitch:1.10.12@sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6
FROM ${FREESWITCH_BASE_IMAGE}

ARG BUILD_VERSION=0.1.0-dev
ARG BUILD_COMMIT=unknown
ARG BUILD_CREATED=unknown
ARG IMAGE_SOURCE=unknown

LABEL org.opencontainers.image.title="UTCP FreeSWITCH runtime" \
      org.opencontainers.image.description="Repository-built FreeSWITCH runtime for the Unified Telephony Control Plane" \
      org.opencontainers.image.version="${BUILD_VERSION}" \
      org.opencontainers.image.revision="${BUILD_COMMIT}" \
      org.opencontainers.image.created="${BUILD_CREATED}" \
      org.opencontainers.image.source="${IMAGE_SOURCE}"

COPY infrastructure/docker/freeswitch/config/ /etc/freeswitch/
COPY infrastructure/docker/freeswitch/entrypoint /usr/local/bin/utcp-freeswitch-entrypoint
COPY infrastructure/docker/freeswitch/healthcheck /usr/local/bin/utcp-freeswitch-healthcheck
COPY infrastructure/docker/media/reference-tone.wav.base64 /tmp/utcp-reference-tone.wav.base64

RUN chmod 0755 /usr/local/bin/utcp-freeswitch-entrypoint /usr/local/bin/utcp-freeswitch-healthcheck \
    && test -s /etc/freeswitch/freeswitch.xml \
    && test -s /etc/freeswitch/autoload_configs/modules.conf.xml \
    && test -s /etc/freeswitch/autoload_configs/console.conf.xml \
    && test -s /etc/freeswitch/autoload_configs/switch.conf.xml \
    && test -s /etc/freeswitch/autoload_configs/sofia.conf.xml \
    && test -s /etc/freeswitch/autoload_configs/event_socket.conf.xml \
    && test -s /etc/freeswitch/autoload_configs/acl.conf.xml \
    && test -s /etc/freeswitch/sip_profiles/utcp-internal.xml \
    && test -s /etc/freeswitch/dialplan/utcp.xml \
    && mkdir -p /usr/share/freeswitch/sounds \
    && base64 -d /tmp/utcp-reference-tone.wav.base64 > /usr/share/freeswitch/sounds/reference-tone.wav \
    && chown 1000:1000 /usr/share/freeswitch/sounds/reference-tone.wav \
    && rm /tmp/utcp-reference-tone.wav.base64 \
    && for module in mod_console mod_commands mod_dialplan_xml mod_dptools mod_sofia mod_event_socket mod_sndfile; do \
         test -s "/usr/lib/freeswitch/mod/${module}.so"; \
       done

USER 1000:1000
EXPOSE 5060/udp
EXPOSE 8021/tcp
EXPOSE 21000-21099/udp
ENTRYPOINT ["/usr/local/bin/utcp-freeswitch-entrypoint"]
