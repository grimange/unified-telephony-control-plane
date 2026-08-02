# FreeSWITCH is selected only by the local parity overlay.  The digest is the
# immutable multi-architecture manifest for the pinned upstream image.
ARG FREESWITCH_BASE_IMAGE=docker.io/safarov/freeswitch:1.10.12@sha256:b31c743f4c911a19687c61e3214968f2a24f93f9d3d667cc26284192e158ffc6
FROM ${FREESWITCH_BASE_IMAGE}

COPY infrastructure/docker/freeswitch/config/ /etc/freeswitch/
COPY infrastructure/docker/freeswitch/entrypoint /usr/local/bin/utcp-freeswitch-entrypoint

RUN chmod 0755 /usr/local/bin/utcp-freeswitch-entrypoint \
    && test -s /etc/freeswitch/freeswitch.xml \
    && test -s /etc/freeswitch/sip_profiles/internal.xml \
    && test -s /etc/freeswitch/dialplan/default.xml

USER 1000:1000
EXPOSE 5060/udp
EXPOSE 21000-21099/udp
ENTRYPOINT ["/usr/local/bin/utcp-freeswitch-entrypoint"]
