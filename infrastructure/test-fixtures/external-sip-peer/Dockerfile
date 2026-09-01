FROM andrius/asterisk:20@sha256:a27dae75b15343ac50ab7bf45eb0ca22681fd770a14863091c32883829cf3fc9

USER root
COPY modules.conf /etc/asterisk/modules.conf
COPY logger.conf /etc/asterisk/logger.conf
COPY rtp.conf /etc/asterisk/rtp.conf
COPY extensions.conf /etc/asterisk/extensions.conf
COPY rtp-sender.py /usr/local/bin/utcp-peer-rtp-sender
COPY generate-tone.py /tmp/generate-tone.py
COPY entrypoint /usr/local/bin/external-sip-peer-entrypoint
COPY readiness /usr/local/bin/external-sip-peer-readiness
RUN chmod 0555 /usr/local/bin/external-sip-peer-entrypoint /usr/local/bin/external-sip-peer-readiness \
    && chmod 0555 /usr/local/bin/utcp-peer-rtp-sender \
    && mkdir -p /tmp/external-sip-peer \
    && mkdir -p /var/lib/asterisk/sounds \
    && python3 /tmp/generate-tone.py /var/lib/asterisk/sounds/reference-tone.wav 10 \
    && mkdir -p /var/lib/asterisk/sounds/en \
    && cp /var/lib/asterisk/sounds/reference-tone.wav /var/lib/asterisk/sounds/en/reference-tone.wav \
    && chown -R 1000:1000 /tmp/external-sip-peer /var/lib/asterisk/sounds \
    && rm /tmp/generate-tone.py
EXPOSE 5060/udp
ENTRYPOINT ["/usr/local/bin/external-sip-peer-entrypoint"]
