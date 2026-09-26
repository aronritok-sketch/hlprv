# Screaming Frog SEO Spider + SEO OS ügynök, szerveroldali (fej nélküli) futtatáshoz.
# A Screaming Frog telepítőjét a hivatalos oldalról tölti le; a verziót a SF_VERSION build-argumentum adja meg
# (a letöltési oldal alján látható, pl. 22.0). A licencet futásidőben, környezeti változóból kapja.
FROM ubuntu:24.04

ARG SF_VERSION=22.0
ENV DEBIAN_FRONTEND=noninteractive \
    HPV_WORK_DIR=/work

RUN apt-get update \
 && apt-get install -y --no-install-recommends ca-certificates wget python3 fontconfig fonts-dejavu xdg-utils zenity \
 && wget -q -O /tmp/sf.deb "https://download.screamingfrog.co.uk/products/seo-spider/screamingfrogseospider_${SF_VERSION}_all.deb" \
 && apt-get install -y /tmp/sf.deb \
 && rm -f /tmp/sf.deb && rm -rf /var/lib/apt/lists/*

RUN useradd -m -u 1000 spider && mkdir -p /work /config && chown spider:spider /work /config
USER spider
WORKDIR /home/spider

COPY --chown=spider:spider agent.py entrypoint.sh /opt/hpv/
ENTRYPOINT ["/bin/sh", "/opt/hpv/entrypoint.sh"]
