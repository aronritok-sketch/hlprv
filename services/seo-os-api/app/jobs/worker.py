"""Háttérfeladat-worker: python -m app.jobs.worker"""

import logging
import os
import signal
import time

from ..config import get_settings
from ..db import session_factory
from ..services import mail
from ..services.system import heartbeat

from . import runner
from .registry import load_handlers

stop = False


def _stop(*_):
    global stop
    stop = True


def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    load_handlers()
    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT, _stop)
    runner.requeue_stale()
    log = logging.getLogger("seo_os.worker")
    log.info("worker indul, feladattípusok: %s", ", ".join(sorted(runner.HANDLERS)))
    last_beat = 0.0
    last_mail = 0.0
    mail_every = get_settings().mail_sync_seconds
    while not stop:
        if time.monotonic() - last_beat > 30:
            last_beat = time.monotonic()
            try:
                heartbeat("worker", {"pid": os.getpid(), "handlers": len(runner.HANDLERS)})
            except Exception:  # noqa: BLE001 – az életjel hibája ne állítsa le a feldolgozást
                log.exception("életjel mentése sikertelen")
        if mail_every and time.monotonic() - last_mail > mail_every:
            last_mail = time.monotonic()
            try:
                db = session_factory()()
                try:
                    mail.enqueue_sync_if_idle(db)
                finally:
                    db.close()
            except Exception:  # noqa: BLE001 – a levélszinkron ütemezése ne állítsa le a workert
                log.exception("levélszinkron ütemezése sikertelen")
        job_id = runner.claim_next()
        if job_id is None:
            time.sleep(1.5)
            continue
        log.info("feladat %s indul", job_id)
        runner.run(job_id)


if __name__ == "__main__":
    main()
