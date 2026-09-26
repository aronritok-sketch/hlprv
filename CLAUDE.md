# HelloProVision – szabályok a repóban dolgozó Claude munkamenetnek

Áron döntése (2026. szeptember 26.): **egy chat dolgozik a repón**, és az viszi a teljes rendszert: SEO OS, CRM és ügyfélportál, levelezés, weboldal-bővítmények.

## Ágak

| Ág | Szerep |
|---|---|
| `claude/great-gauss-jba4b5` | **Munkaág és élesítési ág.** Ide commitolj és pusholj. A Render ebből telepíti az SEO OS szervert (API + worker, a levelezés szerver oldala is), minden push után magától. |
| `claude/laughing-wozniak-dpwf28` | Az alapértelmezett ág. Csak tükör: minden push után gyorsan előre kell tolni a munkaág állására (`git push origin HEAD:claude/laughing-wozniak-dpwf28`, fast-forward), új munka ide nem megy. |

A többi `claude/*` ág (Linora, iu-theme, …) más projekt, nem része ennek a rendszernek.

## Mi hol van

| Terület | Hol | Leírás |
|---|---|---|
| SEO OS szerver (FastAPI, worker, Postgres) | `services/seo-os-api/` | `docs/seo-os/ARCHITECTURE.md`, `docs/seo-os/TELEPITES.md` |
| Screaming Frog ügynök | `services/seo-os-crawler/` | `docs/seo-os/TELEPITES.md` 3. fejezet |
| SEO OS WordPress bővítmény | `plugins/helloprovision-seo-os/` | |
| CRM és ügyfélportál | `plugins/helloprovision-portal/` | `README.md`, `docs/HANDOFF-crm.md`, fejlesztői doksi: https://claude.ai/code/artifact/2c836be0-21a4-4d11-ac38-f865036a9c56 |
| Levelezés | `plugins/helloprovision-mail/` + `services/seo-os-api/app/services/mail` | `docs/integrations/mail.md` |
| Weboldal: Grader, értékelések, űrlapok, schema | `plugins/helloprovision-grader/`, `plugins/helloprovision-reviews/`, `mu-plugins/` | `README.md` |
| Telepítés (Render, tárhely, nginx) | `render.yaml`, `services/deploy/` | `docs/seo-os/TELEPITES.md` |
| Összekötések | `docs/integrations/` | `seo-os.md` (portál ↔ SEO OS), `crm-extensions.md` (CRM bővítés-pont), `mail.md` |
| Állapot, élő rendszer, csapdák | `docs/HANDOFF-crm.md`, `docs/HANDOFF-seo-os.md` | amit a kód nem mutat meg |

## Kiadás: így jut el a változás az éles WordPressbe

- A bővítmények maguktól frissülnek a GitHub kiadásokból (`includes/github-updater.php`, a wp-config.php-ban `HPV_GITHUB_TOKEN`).
- Kiadás akkor készül, ha egy bővítmény **verziója nő**: a fő fájl `Version:` sora ÉS a verzió-konstans (pl. `HPV_PORTAL_VERSION`, `HPV_MAIL_VERSION`), X.Y.Z alakban. A `.github/workflows/plugin-releases.yml` pushkor kiadja (`<bővítmény>-v<verzió>` címke, `<bővítmény>.zip`).
- **Élesbe szánt bővítmény-változásnál emeld a verziót** (javítás: 0.8.3 → 0.8.4, új funkció: → 0.9.0). Verzióemelés nélkül nem megy ki.
- A `github-updater.php` minden bővítményben ugyanaz a fájl: ha módosítod, mindegyikbe másold át.
- Új bővítményt az első alkalommal kézzel kell telepíteni; a `mu-plugins/` fájljai soha nem frissülnek maguktól (FTP).
- Az SEO OS szerver a munkaágra pusholva frissül a Renderen; az adatbázis-migráció induláskor magától lefut.

## Tesztek (push előtt)

```
php tests/seo-os.php && php tests/seo.php && php tests/grader.php && php tests/reviews.php && php tests/i18n.php
wp eval-file tests/<x>-integration.php        # teszt WordPress a portál, az SEO OS és a levelezés bővítménnyel
cd services/seo-os-api && pytest -q           # PostgreSQL kell (SEO_OS_TEST_DATABASE_URL)
```

A részleteket és a teszt-környezet igényeit a két HANDOFF jegyzet írja le.

## Konvenciók

- Minden kommunikáció és felület magyarul; a portál magyar szövegei tegezők. Minden `hpv_t()` szöveghez magyar fordítás kell (`tests/i18n.php`).
- CRM adatréteg: hivatkozás / int oszlopba `0`, soha `null`; az értékesítési mezőket csak a `hpv_sales_*` függvények írják; ügyfél-események: `hpv_p_inserted_{entity}`, `hpv_p_updated_{entity}`.
- Ami össze van kötve, az maradjon összekötve (Áron kérése): meglévő integrációt ne bonts szét.

## Soha

- Titok (jelszó, API-kulcs, token, wp-config.php) nem kerülhet a repóba.
- Nincs force-push, rebase a közös ágakon; a tükör ágat csak fast-forwarddal told előre.
