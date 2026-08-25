FROM andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9

USER root
COPY modules.conf /etc/asterisk/modules.conf
COPY logger.conf /etc/asterisk/logger.conf
COPY rtp.conf /etc/asterisk/rtp.conf
COPY extensions.conf /etc/asterisk/extensions.conf
COPY entrypoint /usr/local/bin/external-sip-peer-entrypoint
COPY readiness /usr/local/bin/external-sip-peer-readiness
RUN chmod 0555 /usr/local/bin/external-sip-peer-entrypoint /usr/local/bin/external-sip-peer-readiness \
    && mkdir -p /tmp/external-sip-peer \
    && chown -R 1000:1000 /tmp/external-sip-peer
EXPOSE 5060/udp
ENTRYPOINT ["/usr/local/bin/external-sip-peer-entrypoint"]
