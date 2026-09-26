# HelloProVision SEO OS – API és worker (ugyanaz a kép, más paranccsal).
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 \
    SEO_OS_STORAGE_DIR=/var/lib/seo-os/files

# WeasyPrint (PDF) natív könyvtárai és betűkészletek (ékezetes magyar szöveg).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpango-1.0-0 libpangoft2-1.0-0 libharfbuzz-subset0 libffi8 \
    fonts-dejavu fonts-liberation2 shared-mime-info \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY pyproject.toml ./
RUN pip install --upgrade pip && pip install .
COPY . .
RUN useradd -m -u 1000 seoos && mkdir -p /var/lib/seo-os/files && chown -R seoos /var/lib/seo-os && chmod +x /app/start.sh

EXPOSE 8100
# Migráció, majd API (SEO_OS_RUN_WORKER=1 esetén mellette a worker is); a start.sh korlátozott felhasználóra vált.
CMD ["/app/start.sh"]
