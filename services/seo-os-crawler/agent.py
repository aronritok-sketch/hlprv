#!/usr/bin/env python3
"""HelloProVision SEO OS – Screaming Frog ügynök.

A licencelt Screaming Frog SEO Spider mellett fut (irodai gépen vagy szerveren, Dockerben), és a SEO OS-től kér munkát:

  1. 30 másodpercenként jelentkezik (heartbeat) és elkér egy sorban álló crawlt,
  2. lefuttatja a Screaming Frog parancssoros módját (--headless --crawl … --export-tabs …),
  3. az exportokat ZIP-be csomagolja és feltölti; a SEO OS feldolgozza (Technikai audit fül).

Csak kimenő HTTPS kell: a WordPress (seo.helloprovision.com) /wp-json/hpv-seo/v1/agent/ útvonalát hívja Bearer tokennel.
Nincs külső függősége (Python 3.9+ standard könyvtár).

Beállítás környezeti változókkal vagy az agent.py mellé tett agent.env fájllal:
  HPV_SEO_URL        https://seo.helloprovision.com
  HPV_AGENT_TOKEN    a szerveren beállított SEO_OS_AGENT_TOKEN értéke
  HPV_AGENT_NAME     az ügynök neve a felületen (alapértelmezés: a gép neve)
  SF_CLI             a Screaming Frog parancssoros indítója (ha nem a szokásos helyen van)
  HPV_WORK_DIR       ideiglenes mappa az exportoknak (alapértelmezés: a rendszer temp mappája)
  HPV_POLL_SECONDS   lekérdezési gyakoriság (alapértelmezés: 30)
  HPV_MAX_HOURS      egy crawl leghosszabb ideje (alapértelmezés: 6)

Futtatás:  python agent.py            (folyamatosan)
           python agent.py --once     (egy kör, pl. ütemezett feladatból)
           python agent.py --check    (beállítások és kapcsolat ellenőrzése)
"""

import json
import os
import platform
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import zipfile
from pathlib import Path

VERSION = "1.0.0"
HERE = Path(__file__).resolve().parent

# A Screaming Frog parancssoros indítójának szokásos helye operációs rendszerenként.
SF_CANDIDATES = {
    "Windows": [
        r"C:\Program Files (x86)\Screaming Frog SEO Spider\ScreamingFrogSEOSpiderCli.exe",
        r"C:\Program Files\Screaming Frog SEO Spider\ScreamingFrogSEOSpiderCli.exe",
    ],
    "Darwin": ["/Applications/Screaming Frog SEO Spider.app/Contents/MacOS/ScreamingFrogSEOSpiderLauncher"],
    "Linux": ["/usr/bin/screamingfrogseospider", "/usr/local/bin/screamingfrogseospider"],
}


def load_env_file() -> None:
    f = HERE / "agent.env"
    if not f.exists():
        return
    for line in f.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#") and "=" in line:
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"'))


def log(msg: str) -> None:
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


class Config:
    def __init__(self):
        load_env_file()
        self.url = os.environ.get("HPV_SEO_URL", "").rstrip("/")
        self.token = os.environ.get("HPV_AGENT_TOKEN", "")
        self.name = os.environ.get("HPV_AGENT_NAME") or socket.gethostname()
        self.poll = int(os.environ.get("HPV_POLL_SECONDS", "30"))
        self.max_seconds = float(os.environ.get("HPV_MAX_HOURS", "6")) * 3600
        self.work = Path(os.environ.get("HPV_WORK_DIR") or tempfile.gettempdir()) / "hpv-sf-agent"
        self.sf = self.find_sf()

    def find_sf(self) -> str:
        explicit = os.environ.get("SF_CLI")
        if explicit:
            return explicit
        for p in SF_CANDIDATES.get(platform.system(), []):
            if Path(p).exists():
                return p
        return shutil.which("screamingfrogseospider") or ""

    def api(self, path: str) -> str:
        return f"{self.url}/wp-json/hpv-seo/v1/agent/{path}"


def request(cfg: Config, path: str, body=None, raw: bytes = b"", filename: str = "", timeout: int = 60) -> dict:
    headers = {"Authorization": f"Bearer {cfg.token}", "User-Agent": f"hpv-sf-agent/{VERSION}"}
    if raw:
        data = raw
        headers["Content-Type"] = "application/zip"
        headers["X-Filename"] = filename
    else:
        data = json.dumps(body or {}).encode()
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(cfg.api(path), data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")[:300]
        raise RuntimeError(f"HTTP {e.code}: {detail}") from None


def info(cfg: Config) -> dict:
    return {"agent_version": VERSION, "os": f"{platform.system()} {platform.release()}", "python": platform.python_version(),
            "sf_cli": cfg.sf, "sf_found": bool(cfg.sf and (Path(cfg.sf).exists() or shutil.which(cfg.sf)))}


def build_command(cfg: Config, run: dict, out: Path) -> list[str]:
    cmd = [cfg.sf, "--headless", "--crawl", run["start_url"], "--output-folder", str(out), "--export-format", "csv"]
    tabs = [t for t in run.get("export_tabs") or [] if t.strip()]
    if tabs:
        cmd += ["--export-tabs", ",".join(tabs)]
    bulk = [b for b in run.get("bulk_exports") or [] if b.strip()]
    if bulk:
        cmd += ["--bulk-export", ",".join(bulk)]
    if run.get("config_file"):
        if Path(run["config_file"]).exists():
            cmd += ["--config", run["config_file"]]
        else:
            log(f"Figyelem: a konfigurációs fájl nem található ezen a gépen: {run['config_file']} – alapbeállítással fut.")
    if run.get("save_crawl"):
        cmd += ["--save-crawl"]
    return cmd


def run_crawl(cfg: Config, run: dict) -> None:
    rid = run["id"]
    out = cfg.work / f"crawl-{rid}-{int(time.time())}"
    out.mkdir(parents=True, exist_ok=True)
    cmd = build_command(cfg, run, out)
    log(f"Crawl #{rid}: {run['start_url']}")
    log("Parancs: " + " ".join(f'"{c}"' if " " in c else c for c in cmd))
    last_line = {"text": "A Screaming Frog elindult"}
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding="utf-8", errors="replace")

    def reader():
        for line in proc.stdout:
            line = line.strip()
            if line:
                last_line["text"] = line[-300:]

    t = threading.Thread(target=reader, daemon=True)
    t.start()
    started = time.monotonic()
    try:
        while proc.poll() is None:
            time.sleep(20)
            elapsed = int(time.monotonic() - started)
            try:
                res = request(cfg, f"crawls/{rid}/progress", {"message": f"{elapsed // 60} perc · {last_line['text']}"})
                if res.get("cancelled"):
                    log(f"Crawl #{rid}: megszakítva a felületről.")
                    proc.terminate()
                    return
            except Exception as e:  # noqa: BLE001 – a hálózati hiba ne állítsa le a crawlt
                log(f"Állapotjelentés sikertelen: {e}")
            if elapsed > cfg.max_seconds:
                proc.terminate()
                raise RuntimeError(f"A crawl túllépte a {cfg.max_seconds / 3600:.0f} órás időkorlátot.")
        t.join(timeout=5)
        if proc.returncode != 0:
            raise RuntimeError(f"A Screaming Frog hibával állt le (kód: {proc.returncode}). Utolsó sor: {last_line['text']}")
        files = [p for p in out.rglob("*") if p.is_file() and p.suffix.lower() in (".csv", ".xlsx")]
        if not files:
            raise RuntimeError("A Screaming Frog nem készített exportot. Ellenőrizd a licencet és az export fülek nevét (Beállítások → Screaming Frog).")
        buf_path = out / f"crawl-{rid}.zip"
        with zipfile.ZipFile(buf_path, "w", zipfile.ZIP_DEFLATED) as z:
            for f in files:
                z.write(f, f.name)
        size = buf_path.stat().st_size
        log(f"Crawl #{rid}: {len(files)} export, {size / 1024 / 1024:.1f} MB feltöltése…")
        res = request(cfg, f"crawls/{rid}/upload", raw=buf_path.read_bytes(), filename=buf_path.name, timeout=600)
        job = res.get("job") or {}
        log(f"Crawl #{rid}: feltöltve, feldolgozás: {job.get('status', '?')}")
    finally:
        if proc.poll() is None:
            proc.kill()
        shutil.rmtree(out, ignore_errors=True)


def once(cfg: Config) -> bool:
    res = request(cfg, "crawls/claim", {"agent": cfg.name, "info": info(cfg)})
    run = res.get("run")
    if not run:
        return False
    try:
        if not cfg.sf:
            raise RuntimeError("Nem található a Screaming Frog parancssoros indítója ezen a gépen (SF_CLI).")
        run_crawl(cfg, run)
    except Exception as e:  # noqa: BLE001 – minden hibát visszajelzünk a felületre
        log(f"Crawl #{run['id']} hiba: {e}")
        try:
            request(cfg, f"crawls/{run['id']}/fail", {"error": str(e)})
        except Exception as e2:  # noqa: BLE001
            log(f"A hiba visszajelzése sem sikerült: {e2}")
    return True


def check(cfg: Config) -> int:
    ok = True
    print(f"SEO OS URL:      {cfg.url or '– (HPV_SEO_URL hiányzik)'}")
    print(f"Token:           {'beállítva' if cfg.token else '– (HPV_AGENT_TOKEN hiányzik)'}")
    print(f"Ügynök neve:     {cfg.name}")
    print(f"Screaming Frog:  {cfg.sf or '– nem található (SF_CLI)'}")
    ok &= bool(cfg.url and cfg.token and cfg.sf)
    if cfg.url and cfg.token:
        try:
            request(cfg, "heartbeat", {"agent": cfg.name, "info": info(cfg)})
            print("Kapcsolat:       rendben")
        except Exception as e:  # noqa: BLE001
            print(f"Kapcsolat:       HIBA – {e}")
            ok = False
    return 0 if ok else 1


def main() -> int:
    cfg = Config()
    if "--check" in sys.argv:
        return check(cfg)
    if not cfg.url or not cfg.token:
        log("Hiányzó beállítás: HPV_SEO_URL és HPV_AGENT_TOKEN (környezeti változó vagy agent.env).")
        return 2
    if "--once" in sys.argv:
        once(cfg)
        return 0
    log(f"HelloProVision SF ügynök {VERSION} – {cfg.name} – {cfg.sf or 'Screaming Frog nem található!'}")
    while True:
        try:
            request(cfg, "heartbeat", {"agent": cfg.name, "info": info(cfg)})
            while once(cfg):
                pass
        except Exception as e:  # noqa: BLE001 – átmeneti hálózati hiba: a következő körben újra
            log(f"Hiba: {e}")
        time.sleep(cfg.poll)


if __name__ == "__main__":
    sys.exit(main())
