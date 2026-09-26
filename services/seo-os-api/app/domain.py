"""A HelloProVision SEO-módszertan szótára: státuszok, szolgáltatások, bekérendő anyagok, szerepkörök.

A felület a /meta végpontból kapja meg ugyanezeket a címkékkel együtt, így egy helyen kell módosítani.
"""

ROLES = {
    "admin": "Admin",
    "seo_manager": "SEO manager",
    "content_manager": "Content manager",
    "designer": "Designer",
    "developer": "Fejlesztő",
    "staff": "CRM munkatárs",  # csak a CRM levelezéshez (SEO OS szerepkör nélkül)
}

# Magasabb szám = szélesebb jogkör (több szerepkörnél a legerősebb érvényes).
ROLE_RANK = {"staff": 0, "designer": 1, "developer": 1, "content_manager": 2, "seo_manager": 3, "admin": 4}

STATUSES = {
    "draft": "Vázlat",
    "researching": "Kutatás",
    "ai_analysis_complete": "AI-elemzés kész",
    "seo_review": "SEO-ellenőrzés",
    "client_review": "Ügyfél-ellenőrzés",
    "approved": "Jóváhagyva",
    "production": "Gyártás",
    "completed": "Kész",
}
STATUS_ORDER = list(STATUSES)

# Eladható szolgáltatások (SEO árak.xlsx) – ezekből áll össze egy projekt terjedelme.
SCOPES = {
    "tech_audit": "Technikai SEO audit",
    "tech_foundations": "Technikai SEO alapok (új oldal)",
    "keyword_research": "Kulcsszókutatás",
    "structure": "Oldalstruktúra javaslat",
    "content_strategy": "Tartalomstratégia",
    "content_mgmt": "Tartalommenedzsment",
    "wireframes_only": "Tartalommenedzsment – csak wireframe",
    "link_building": "Linképítés",
    "monitoring": "Havi monitoring",
}

# Bekérendő anyagok (SEO lépések – projektmenedzser_sales.docx). A lista a terjedelemből következik.
INTAKE_ITEMS = {
    "domain": ("Vizsgált domain", None),
    "previous_research": ("Korábbi SEO kutatások (ha van)", None),
    "gsc_access": ("Google Search Console hozzáférés (Áron, Olívia: tulajdonos; Laci, Máté: teljes)", None),
    "admin_access": ("WordPress admin jogosultság (ha nem mi fejlesztettük)", {"tech_audit", "tech_foundations"}),
    "seed_keywords": ("15–25 kiinduló kulcsszó", {"keyword_research"}),
    "competitors": ("4–5 versenytárs domain organikus pozíció alapján", {"keyword_research"}),
    "conversion_goals": ("Konverziós célok", {"keyword_research", "content_strategy"}),
    "product_list": ("Aktuális terméklista (webshop esetén)", {"keyword_research"}),
    "style_guide": ("Kitöltött Stílusbeli irányelvek (domain a címben, márkanév átírva)", {"content_mgmt", "wireframes_only"}),
    "approved_strategy": ("Ügyfél által jóváhagyott tartalomstratégia", {"content_mgmt", "wireframes_only"}),
    "own_images": ("Ügyfél saját képei a témákhoz", {"content_strategy", "content_mgmt"}),
}

INTAKE_STATUSES = {"missing": "Hiányzik", "requested": "Bekérve", "received": "Megérkezett", "n_a": "Nem releváns"}

SEED_KINDS = {
    "service": "Fő szolgáltatás / termék",
    "customer_term": "Ügyfelek által használt elnevezés",
    "location": "Település / szolgáltatási terület",
    "high_margin": "Üzletileg fontos, magas árrésű",
    "excluded": "Kizárt szolgáltatás / kulcsszó",
    "product": "Termék (webshop)",
}

COMPETITOR_SOURCES = {"client": "Ügyfél adta", "seo": "SEO-s választotta", "ahrefs": "Ahrefs"}

LANGUAGES = {"hu": "Magyar", "en-US": "Angol (US)", "en-GB": "Angol (UK)", "de": "Német"}


def intake_applies(key: str, scope: list[str]) -> bool:
    needed = INTAKE_ITEMS[key][1]
    return needed is None or bool(needed & set(scope))
