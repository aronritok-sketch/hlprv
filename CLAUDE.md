# HelloProVision – közös szabályok minden Claude munkamenetnek

Ebben a repóban két munkamenet (chat) dolgozik párhuzamosan ugyanazon a rendszeren. Hogy mindig összhangban legyenek, és a változások maguktól eljussanak az éles rendszerbe, ezeket kövesd.

## Ki min dolgozik

| Ág | Terület |
|---|---|
| `claude/great-gauss-jba4b5` | SEO OS: `services/`, `plugins/helloprovision-seo-os/`, `docs/seo-os/`, `render.yaml`. **A Render ebből az ágból telepíti az SEO OS szervert** (minden push után magától). A CRM e-mail modulja is itt készül. |
| `claude/laughing-wozniak-dpwf28` (alapértelmezett ág) | CRM és ügyfélportál (`plugins/helloprovision-portal/`), weboldal bővítmények (`helloprovision-grader`, `helloprovision-reviews`), `mu-plugins/`, `README.md` |

A két terület közti szerződés: `docs/integrations/seo-os.md` (portál ↔ SEO OS hookok, jóváhagyás, riport-mutatók).

## Munka előtt és után

1. **Kezdéskor** hozd be a másik ág munkáját: `git fetch origin && git merge origin/<másik ág>` (merge commit, ne rebase). Ütközésnél mindkét oldal viselkedését tartsd meg.
2. **A végén** futtasd a teszteket (lent), és pushold a saját ágadra.
3. A másik terület fájljaihoz csak integráció miatt nyúlj, minimálisan, és írd bele a commit üzenetbe.

## Kiadás: így jut el a változás az éles WordPressbe

- A bővítmények maguktól frissülnek a GitHub kiadásokból (`includes/github-updater.php`, a wp-config.php-ban `HPV_GITHUB_TOKEN`).
- Kiadás akkor készül, ha egy bővítmény **verziója nő**: a fő fájl `Version:` sora ÉS a verzió-konstans (pl. `HPV_PORTAL_VERSION`), X.Y.Z alakban. A `.github/workflows/plugin-releases.yml` bármelyik ágról pusholva kiadja (`<bővítmény>-v<verzió>` címke, `<bővítmény>.zip`). Ha már van magasabb kiadás, a lemaradt ág nem ad ki régebbit.
- **Ha élesbe szánt változtatást csinálsz egy bővítményen, emeld a verzióját** (javítás: 0.8.1 → 0.8.2, új funkció: 0.8.x → 0.9.0). Verzióemelés nélkül nem megy ki.
- A `github-updater.php` minden bővítményben ugyanaz a fájl: ha módosítod, mindegyikbe másold át.
- A `mu-plugins/` fájljai nem frissülnek maguktól (a WordPress nem kezeli őket): ezeket FTP-n kell cserélni.
- Az SEO OS szerver (`services/seo-os-api`) a `claude/great-gauss-jba4b5` ágra pusholva frissül a Renderen; az adatbázis-migráció induláskor magától lefut.

## Tesztek

```
php tests/seo-os.php && php tests/seo.php && php tests/grader.php && php tests/reviews.php && php tests/i18n.php
wp eval-file tests/<x>-integration.php        # valódi (teszt) WordPressen, a portál és az SEO OS bővítménnyel
cd services/seo-os-api && pytest -q           # PostgreSQL kell (SEO_OS_TEST_DATABASE_URL)
```

## Soha

- Titok (jelszó, API-kulcs, token, wp-config.php) nem kerülhet a repóba.
- Ne írd át a másik ág történetét (nincs force-push, rebase a közös ágakon).
