"""Weboldal-pillanatkép az üzleti profilhoz: cím, meta leírás, címsorok, navigáció, szövegminta."""

from html.parser import HTMLParser

import httpx


class _Extract(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title = ""
        self.description = ""
        self.headings: list[str] = []
        self.nav: list[str] = []
        self.text: list[str] = []
        self._stack: list[str] = []
        self._skip = 0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ("script", "style", "noscript", "svg"):
            self._skip += 1
        if tag == "meta" and (a.get("name") or "").lower() == "description":
            self.description = a.get("content") or ""
        self._stack.append(tag)

    def handle_endtag(self, tag):
        if tag in ("script", "style", "noscript", "svg") and self._skip:
            self._skip -= 1
        if self._stack and self._stack[-1] == tag:
            self._stack.pop()

    def handle_data(self, data):
        if self._skip:
            return
        d = " ".join(data.split())
        if not d:
            return
        tag = self._stack[-1] if self._stack else ""
        if tag == "title":
            self.title = d
        elif tag in ("h1", "h2", "h3"):
            self.headings.append(f"{tag.upper()}: {d}")
        elif "nav" in self._stack and tag == "a":
            self.nav.append(d)
        else:
            self.text.append(d)


def snapshot(domain: str, timeout: float = 12.0) -> dict:
    url = domain if domain.startswith("http") else f"https://{domain}/"
    try:
        r = httpx.get(url, timeout=timeout, follow_redirects=True, headers={"User-Agent": "HelloProVision-SEO-OS/1.0"})
        r.raise_for_status()
    except Exception as e:  # noqa: BLE001
        return {"url": url, "error": f"{type(e).__name__}: {e}"[:300]}
    p = _Extract()
    p.feed(r.text[:800_000])
    text = " ".join(p.text)
    return {
        "url": str(r.url),
        "title": p.title,
        "description": p.description,
        "headings": p.headings[:40],
        "nav": list(dict.fromkeys(p.nav))[:40],
        "text": text[:4000],
    }
