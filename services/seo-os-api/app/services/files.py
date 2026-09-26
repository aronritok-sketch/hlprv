"""Fájlválasz letöltéshez (a WordPress proxy változatlanul továbbadja a böngészőnek)."""

import re
import unicodedata
from urllib.parse import quote

from fastapi.responses import Response

MIME = {
    "xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "pdf": "application/pdf",
    "csv": "text/csv; charset=utf-8",
    "zip": "application/zip",
}


def slug(text: str) -> str:
    s = re.sub(r"[^\w\-. ]+", "", text, flags=re.UNICODE).strip()
    return re.sub(r"\s+", " ", s)[:120] or "export"


def file_response(data: bytes, filename: str, mime: str | None = None) -> Response:
    ext = filename.rsplit(".", 1)[-1].lower()
    ascii_name = unicodedata.normalize("NFKD", filename).encode("ascii", "ignore").decode() or f"export.{ext}"
    return Response(
        content=data,
        media_type=mime or MIME.get(ext, "application/octet-stream"),
        headers={"Content-Disposition": f"attachment; filename=\"{ascii_name}\"; filename*=UTF-8''{quote(filename)}"},
    )
