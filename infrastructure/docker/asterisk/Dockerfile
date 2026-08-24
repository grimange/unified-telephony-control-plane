ARG ASTERISK_BASE_IMAGE=andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9
FROM ${ASTERISK_BASE_IMAGE}

RUN mkdir -p /opt/utcp-asterisk-config

COPY infrastructure/docker/asterisk/config/ /opt/utcp-asterisk-config/
COPY infrastructure/docker/asterisk/entrypoint /usr/local/bin/utcp-asterisk-entrypoint
COPY infrastructure/docker/asterisk/readiness /usr/local/bin/utcp-asterisk-readiness
COPY infrastructure/docker/media/reference-tone.wav.base64 /tmp/utcp-reference-tone.wav.base64

RUN chmod 0755 /usr/local/bin/utcp-asterisk-entrypoint /usr/local/bin/utcp-asterisk-readiness \
    && mkdir -p /var/lib/asterisk/sounds \
    && base64 -d /tmp/utcp-reference-tone.wav.base64 > /var/lib/asterisk/sounds/reference-tone.wav \
    && chown -R asterisk:asterisk /opt/utcp-asterisk-config /var/lib/asterisk/sounds \
    && rm /tmp/utcp-reference-tone.wav.base64

USER 1000:1000
EXPOSE 8088
EXPOSE 5060/udp
ENTRYPOINT ["/usr/local/bin/utcp-asterisk-entrypoint"]
