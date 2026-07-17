ARG ASTERISK_BASE_IMAGE=andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9
FROM ${ASTERISK_BASE_IMAGE}

RUN mkdir -p /opt/utcp-asterisk-config

COPY infrastructure/docker/asterisk/config/ /opt/utcp-asterisk-config/
COPY infrastructure/docker/asterisk/entrypoint /usr/local/bin/utcp-asterisk-entrypoint
COPY infrastructure/docker/asterisk/readiness /usr/local/bin/utcp-asterisk-readiness

RUN chmod 0755 /usr/local/bin/utcp-asterisk-entrypoint /usr/local/bin/utcp-asterisk-readiness \
    && chown -R asterisk:asterisk /opt/utcp-asterisk-config

USER 1000:1000
EXPOSE 8088
ENTRYPOINT ["/usr/local/bin/utcp-asterisk-entrypoint"]
