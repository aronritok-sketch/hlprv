# HELLOPROVISION SEO OS — Architecture & Phase 1 Plan

Status: **proposal, awaiting approval**. No application code has been written yet.

This document is based on the full `seotool.zip` package: 26 top-level files and 7 nested archives, about 60 unique documents. It covers five parts:

1. The HelloProVision SEO methodology as it is practiced today, extracted from the documents.
2. The system architecture.
3. The database schema.
4. The UI structure.
5. The development roadmap and the Phase 1 implementation plan.

---

## 0. Source inventory (what was analyzed)

| Group | Documents | What they define |
|---|---|---|
| **Process** | `SEO lépések - projektmenedzser_sales.docx`, `SEO árak.xlsx` (schedule + prices + service contents) | The service catalogue, intake checklist, approval gates, who does what, upsell rule |
| **Research inputs** | `Kiindulás.xlsx` (core keywords, locations, competitors, keyword collection), 4× Google Keyword Planner exports (Naples, Fort Myers, Cape Coral, Florida), Ahrefs *matching terms* and *content gap* exports | What raw data comes in and in which shape |
| **Keyword research deliverable** | `Kulcsszókutatás - helloprovision.com.xlsx` | Keyword × location rows, KD, parent topic, per-competitor position/URL/traffic |
| **Structure deliverable** | `Wireframe - főoldal.docx`, `Wireframe - aloldalak.docx`, `Főoldal wireframe - szövegírónak.docx`, `Aloldalak wireframe - szövegírónak.docx`, `Belső linkelési struktúra - főoldal.docx`, `helloprovision.com struktúra rajz.png`, change logs | Site structure, URL/title/H1 matrix, page models, internal linking plan |
| **Content strategy deliverable** | `tartalomstratégia 2026-2027 helloprovision.com_.xlsx` (6 sheets) | Summary, keyword clusters, 6-month roadmap, measurement plan, PPC/Meta plan, parked topics |
| **Content wireframes** | `01–04 HelloProVision wireframe - *.docx` | The per-article / case-study wireframe format |
| **Internal briefs** | `gulyastamas.hu SEO fejlesztői módosítások.docx` (developer), `gulyastamas.hu SEO szövegírás.docx` (writer keyword mapping), `artmirror.hu … belső kivitelezési feladatlista.docx` (color-coded SEO/writer/dev task list), `SEO alapok - grafikus.docx` (designer), `SEO alapok - szövegíró.docx`, `Technikai SEO alapok - fejlesztői.docx`, `FEJLESZTŐI BETANÍTÁSI KÉZIKÖNYV - SABLON.docx` | Role-specific briefs and standing rules |
| **Client templates** | `Stílusbeli irányelvek szövegíráshoz - sablon.docx`, `Technikai SEO audit - összefoglaló sablon.docx` + a filled audit, TimeHeist proposal PDF | Client-facing formats and branding |
| **Technical audit data** | 9 Screaming Frog exports (missing canonicals, noindex, duplicate H1/title/meta, long titles/metas, 4xx, images >100 KB) | Audit input shape |
| **Finished content** | 2 articles + 2 Facebook posts | Quality bar for the writer output |

---

## 1. The HelloProVision methodology (extracted)

### 1.1 Services sold (these determine what a project contains)

From `SEO árak.xlsx` and the process document. A project is a **bundle of these deliverables**, not a single fixed pipeline:

| Service | Type | Main output |
|---|---|---|
| Technical SEO audit (+ optional implementation) | one-off | Audit summary document (XL/M/S priority), developer tickets |
| Technical SEO foundations (new site) | one-off | Developer requirements |
| Keyword research | one-off | Keyword research spreadsheet. The client can comment on it but not edit it. |
| Site structure proposal | one-off | Structure spreadsheet → Figma, sent to the client |
| Content strategy (usually 6 months) | one-off | 6-sheet strategy workbook |
| Content management (monthly: 2–8 pieces/month) | monthly | Wireframe → text → QA → client approval → upload |
| Wireframe-only content management | monthly | Wireframes only. The client writes the text; developers train the client once. |
| Link building / link profile audit | one-off / monthly | Link strategy |
| Monitoring report | monthly | Monthly report, starting after month 1 |

**Business rule:** at the start of month 5, remind sales/PM to upsell. The two options are a free strategy for the next 6 months (with a contract renewal), or a new, discounted research and strategy package.

### 1.2 Workflow, gates and owners (as practiced)

```
Sales/proposal ─► Contract ─► INTAKE (client checklist)
                                 │
          ┌──────────────────────┼──────────────────────────┐
          ▼                      ▼                          ▼
  Technical audit        Keyword research            (new site: tech foundations)
  (SF crawl → audit)     (Ahrefs + GKP + competitors)
          │                      │  ◄── GATE: client comments / approves (mandatory)
          │                      ▼
          │              Site structure proposal (Excel → designer → Figma)
          │                      │  ◄── GATE: client review/meeting
          │                      ▼
          │              Content strategy (6-month roadmap)
          │                      │  ◄── GATE: client approval (+ client supplies own images)
          │                      ▼
          │              Style guidelines doc filled by client
          │                      ▼
          │              Monthly: wireframe (SEO) → text (writer) → QA (PM/SEO)
          │                      │  ◄── GATE: client checks accuracy, brand voice, business facts.
          │                      │          SEO manager judges any change that affects SEO.
          ▼                      ▼
  Dev implementation      Upload/publish → indexing request → monthly monitoring report
```

The people named in the documents map to the requested roles:

| Person in the documents | Role in SEO OS |
|---|---|
| Áron | Admin |
| Olívia (research, structure, strategy, wireframes) | SEO Manager |
| Zsolti (PM, QA of content) | Content Manager |
| Anni (writer) | Content Manager |
| Laci, Máté (developers) | Developer |
| Graphic designer | Designer |

### 1.3 Client intake checklist (becomes a structured form)

**Always:**
- The domain to analyze.
- Earlier SEO research, if there is any.
- Google Search Console access. Specific team emails need Owner or Full permission. If the client has no GSC, the agency creates it, which requires domain-registrar access. Credentials are shared through 1Password.

**For technical implementation:** WordPress admin access, unless HelloProVision built the site.

**For keyword research:**
- 15–25 seed keywords, tagged by kind:
  - main services and products;
  - the terms customers actually use;
  - towns and service area;
  - high-margin services;
  - excluded services or keywords;
  - the current product list (webshops).
- 4–5 competitor domains, chosen by organic position.
- Conversion goals.

**For content management:** the client-approved strategy and the filled style guideline document. The document title must contain the client's domain, and the highlighted brand name must be replaced before sending.

### 1.4 Decision rules found in the documents (the system must enforce or encode these)

**Keyword prioritization**

1. **The primary keyword is not always the highest-volume term.** On service pages, search intent comes first. Example from the documents: *önismereti tanácsadás* (30/mo, service intent) beats *önismeret* (880/mo, informational) as the primary keyword. This matches the requested priority order: intent → business value → commercial opportunity → competition → volume.
2. **Intent labels used:** Informational, Commercial, Commercial investigation ("információs/kereskedelmi vizsgálódó"), Problem-aware ("problématudatos"), Navigational, Transactional, Mixed.
3. **Location is a separate dimension, not an intent.** The research sheets store one row per keyword × location, and KD differs by city.
4. **Priority scale:**
   - **P1:** strong business intent, plus a direct internal link to the strongest service or city pages.
   - **P2:** a supporting or broader topic that builds proof and topical authority, often with higher KD.
   - **Parked:** carried to the next period, with a reason and a recommended handling, for example "wait for GSC data" or "handle through the city landing page".
5. **Alternative market language is not a service primary.** Example: *párterápia* is not how the client names the service, so it goes into comparison articles only.

**Cannibalization and page mapping**

6. **One primary commercial keyword maps to exactly one URL.** Close variants are handled on the same page, so there is no separate page for each word order.
7. **One page serves one intent.** The homepage does not target a keyword that belongs to a service page.
8. **City + service commercial keywords go to dedicated landing pages.** Articles may use a location only as supporting context.
9. **Articles answer an informational or decision question, then route to the service page.** They must not copy the landing page. Every roadmap row carries a *cannibalization rule*.
10. **A city hub is not a service page.** The hub routes to the city's service pages.
11. **Every city gets the same services, with unique local copy.** The problems, industries, FAQ and proof differ per city. Pages are never city-name swaps.
12. **Don't create a page the research doesn't justify.** helloprovision.com, for example, has no PPC, e-commerce, branding, "near me" or separate web-development landing pages.

**Content and linking**

13. **Minimum before a page goes live:**
    - a unique title and H1;
    - local copy;
    - proof;
    - 5 or more genuine FAQs;
    - internal links;
    - a CTA.
14. **Proof rules:**
    - use only verified proof;
    - never invent clients or results, and never promise rankings;
    - leave out any case-study module whose work was not part of the project.
15. **Link labels must read naturally for visitors.**
    - Don't auto-link exact-match keywords, and don't use sitewide keyword links.
    - Link placement types: MENU, BUTTON, CARD, TEXT LINK, NO LINK.
16. **Keep the measurement expectations phased:**
    - 0–30 days: indexing;
    - 31–60 days: query discovery;
    - 61–90 days: clicks and CTR;
    - month 4: assisted paths;
    - month 5: lead quality;
    - month 6: decide the next 6 months.
17. **SEO and paid work share one content system.** Articles become Meta/Facebook creatives. High-intent Google Search traffic goes to service or city landing pages, never to the blog.

**Technical audit and tasks**

18. **Technical audit priorities use t-shirt sizes (XL/M/S).** Developer tasks carry a source, an action, a target and a *"done when"* check.
19. **Internal task lists are color-coded by role:** SEO = green, writer = blue, developer = orange.

### 1.5 Document structures to reproduce (field-level)

**Keyword research sheet** — one row per keyword × location:

`Keyword | Local long-tail modifier | Parent topic | Volume | KD | Location | {competitor}: Position | {competitor}: URL | {competitor}: Traffic … | (Magyar translation) | Traffic potential | Current position | Current URL | Category`

**Content strategy workbook** — 6 sheets:

1. **Summary:** strategy essence, pillars with article counts, cadence, priority logic, core SEO principles.
2. **Keyword clusters:** `Primary keyword | Volume | KD | Intent | Pillar | Related keywords | Cluster volume | Target URL | Content type | Priority | Cannibalization rule`
3. **6-month roadmap:** `Month | Priority | Primary keyword | Pillar | Related keywords | Volume | Location context | URL | Page type | H1/title | Content direction/main blocks | Main CTA | Internal links | Boost/social hook | Status`
4. **Measurement plan:** `Period | Focus | What to check | Where | Success signal | Decision | Content scope | Owner | Status | Note`
5. **PPC/Meta:** `Month | Content/hook | SEO role | Location | Meta creative | Paid role | Google Search target | Remarketing next step | KPI`
6. **Parked topics:** `Keyword | Volume | KD | Pillar | Why not now | Recommended handling`

Every sheet ends with a column-explanation block written for the client.

**Site structure / page wireframe** (service and city pages):
- URL / SEO title / H1 matrix.
- Page models: regional home, city hub, service × city.
- Per model: purpose, then `# | H2/block | Task`, followed by PROOF / LINKS / LOCAL VARIATION.
- Per block: benchmark competitor URLs.
- The homepage content-ratio guide and the publishing minimum.

**Article / case-study wireframe:**
- **Header:** URL, H1, primary keyword, volume/KD, related keywords, pillar, word count range, main CTA, and for case studies also the schema and page type.
- **"Essence" box:** the brief in 2–4 sentences, including the language instruction (for example "research in English, US English").
- **Flow line:** block → block → …
- **Section table:** `# | H2 | Goal | What to write | SEO/keyword use | Internal link/CTA | Length`
- **Internal link table:** `Where | Anchor | Target | Implementation note`
- **Proof/input box:** stating who sources the proof.
- **Checklists:** "Must have before delivery" and "Forbidden".
- **Visual sequence and required inputs** (case studies).

**Developer brief:**
- The decision basis.
- Change tables: `Change | Exact task | SEO reason`.
- The URL structure: `Page | URL | SEO role`.
- Navigation.
- Existing anchors → target URLs.
- Redirect tables: `Source URL | Action (301/noindex/canonical) | Target | Verification / done-when`.

**Writer brief (keyword mapping):**
- General writing rules.
- The mapping table: `Page | URL | Primary | Secondary | Intent | H1`.
- Per-page "use / avoid".
- Keywords explicitly *not* targeted.
- Cannibalization rules.
- The service-page template.

**Designer brief:**
- The designer's responsibility boundary.
- A required-inputs checklist.
- Filename rules.
- Formats per content type.
- Export sizes, responsive crops and handoff.

**Client proposal / PDF style** (TimeHeist): dark background, bordered cards, an accent label chip on each page, a page-number footer, short sentences.

### 1.6 Two things the brief should account for (recommendations)

- **Spreadsheets are real deliverables, not just intermediates.** The keyword research is sent as a sheet the client can comment on but not edit. The strategy is a 6-sheet workbook. Exports must therefore include XLSX (and later Google Sheets) in the house layout, next to the PDFs.
- **Work happens in two languages.** The internal team writes briefs in Hungarian, and client output is Hungarian (ideastyle.hu clients) or US English (helloprovision.com clients). Each project gets two settings:
  - a **content language**, used for keywords, H1s, titles and client documents;
  - a **working language**, used for internal briefs, defaulting to Hungarian.

---

## 2. System architecture

### 2.1 Overview

```
 Browser (team: Admin / SEO Mgr / Content Mgr / Designer / Developer)
   │   same-origin, WP login cookie + REST nonce
   ▼
 WordPress  ── plugin: helloprovision-seo-os ──────────────────────────────
   • Auth, users, roles/capabilities (WordPress is the identity provider)
   • Serves the SPA shell at /seo-os/ (or seo.helloprovision.com, like the CRM)
   • REST proxy  /wp-json/hpv-seo/v1/*  → FastAPI, adds signed user context
   • Links to existing CRM clients and the client portal (reuse, not rebuild)
   │   server-to-server HTTPS, HMAC-SHA256 signed headers
   ▼
 FastAPI service  (Python 3.12, private network only)
   • Domain API: projects, research, keywords, clusters, pages, roadmap,
     wireframes, documents, approvals
   • Job runner (Postgres-backed queue) for imports, AI and document rendering
   • Integrations: OpenAI (analysis), Anthropic Claude (documents),
     DataForSEO, Ahrefs, later Google Search Console
   • Renderers: HTML→PDF (WeasyPrint), DOCX (python-docx), XLSX (openpyxl)
   │
   ▼
 PostgreSQL 16   (system of record)   +   file storage (local volume / S3)
```

### 2.2 Key decisions and why

| Decision | Choice | Reason |
|---|---|---|
| Where the data lives | **PostgreSQL via FastAPI only.** WordPress stores only users, roles and plugin settings. | One source of truth. WordPress stays thin, so the data can outlive the frontend. |
| Browser → backend | **Through a WordPress REST proxy.** FastAPI is not exposed publicly. | Same origin, no CORS, and the WordPress login cookie is reused. FastAPI trusts only signed calls from WordPress. |
| Service auth | **HMAC-SHA256 over `method + path + timestamp + body-hash + user-id`** with a shared secret and a 5-minute clock window | Simple and replay-resistant. FastAPI re-checks permissions from the role in the signed header and also keeps its own `users` mirror. |
| Long operations | **Async jobs** in a `jobs` table (`SELECT … FOR UPDATE SKIP LOCKED`), polled by the UI | A PHP proxy must not wait minutes. No Redis or Celery is needed at this scale. |
| Frontend tech | **Preact + htm, no build step,** the same stack as the existing CRM app (`plugins/helloprovision-portal/assets/app`) | Matches the codebase, has no toolchain to maintain, and is fast. The existing `preact-htm.js` vendor file is reused. |
| AI split | **OpenAI** for classification, clustering, mapping and planning, with strict JSON-schema outputs. **Claude** for all narrative and document text. | As requested. Both sit behind one `llm` adapter with prompt versioning, caching and cost logging. |
| AI vs. humans | **AI never writes final values directly.** Every AI result is stored as a *suggestion* with its reason, and a person accepts or overrides it. Approved artifacts are versioned and locked. | Automates the repetitive work while keeping the strategic decisions human. |
| Priority | **A deterministic, explainable score** in the requested order, computed in Python from AI-classified inputs | Reproducible, auditable, and the team can tune it. The model only classifies; the code ranks. |
| Cannibalization | **Enforced in the database:** a keyword can be *primary* on only one page (unique partial index) | Rule 6 in §1.4 becomes a hard guarantee, not a checklist item. |
| Reuse | Link projects to the **CRM clients** table, push production tasks to **CRM tasks**, run client review through the **existing client portal** | Avoids building a second client portal and task system. |

### 2.3 FastAPI service layout

```
services/seo-os-api/
  app/
    main.py              app factory, routers, error handlers
    config.py            pydantic-settings (DB URL, HMAC secret, API keys)
    db.py                SQLAlchemy 2.0 engine/session
    auth.py              HMAC verification, CurrentUser dependency, permission checks
    permissions.py       role → capability matrix (mirrors WordPress)
    models/              ORM models, one module per domain
    schemas/             Pydantic request/response models
    routers/             projects, dashboard, users, research, keywords, clusters,
                         pages, roadmap, wireframes, documents, approvals, jobs
    services/
      workflow.py        project state machine + gates
      scoring.py         priority score (deterministic)
      importers/         ahrefs_*, gkp, screaming_frog, generic (column auto-map)
      llm/               adapter, prompts/ (versioned), openai_client, claude_client
      integrations/      dataforseo, ahrefs, gsc
      renderers/         pdf (Jinja2 + WeasyPrint), docx, xlsx
    jobs/                worker loop, job handlers
  alembic/               migrations
  tests/                 pytest (against a real Postgres)
  Dockerfile, docker-compose.yml, .env.example, pyproject.toml
```

### 2.4 AI pipeline design (Phase 3+)

1. **Normalize.** Deterministic code merges duplicates, lowercases, strips location tokens into a `location` field, and flags "near me", "best", "vs", "cost" and similar modifiers.
2. **Classify intent** with OpenAI, using batches of about 100 keywords and a JSON schema. The prompt includes the project business profile, the service list and the excluded topics. Results are cached by `(term, market, language, prompt_version)`, so re-runs are free.
3. **Assess business value** (1–5) against the client's services, margins and exclusions. The output includes a one-line reason.
4. **Cluster.** Embeddings go through agglomerative clustering, and an LLM names each cluster and picks the pillar. Each cluster is split into the five requested buckets: Commercial, Informational, Problem, Comparison, Local.
5. **Score (code):**
   ```
   score = 0.35·intent_fit + 0.25·business_value + 0.20·commercial_opportunity
         + 0.12·ease(KD) + 0.08·log_volume
   ```
   The weights are editable per agency. Hard overrides:
   - excluded → Parked;
   - alternative market language → article-only.

   The P1/P2/Parked thresholds are configurable.
6. **Map.** The LLM proposes the page type and URL. Code then enforces the rules:
   - one primary per URL;
   - city + commercial → city landing page;
   - informational → article linking to the service page.

   The system writes a cannibalization rule for each mapping.
7. **Plan.** The system generates the site structure, then the 6-month roadmap (at the project's cadence), then the wireframes.
8. **Write documents** with Claude. It receives the *approved* structured data plus 1–2 reference documents from the uploaded examples, which act as style anchors. It returns section JSON, and the renderers turn that into branded PDF, DOCX or XLSX.

Every call is logged in `api_logs` with tokens, cost, latency and the prompt version.

---

## 3. Database schema (PostgreSQL, schema `seo`)

Conventions:
- `id bigserial` primary keys and `created_at`/`updated_at timestamptz`.
- Soft-archive via `archived_at`.
- Enumerations are `text` with `CHECK` constraints, which are easier to migrate than native enums.
- The **Phase** column shows when each table is introduced.

### 3.1 Identity, projects, workflow

| Table | Key columns | Phase |
|---|---|---|
| `users` | `wp_user_id` (unique), `email`, `display_name`, `role` (admin / seo_manager / content_manager / designer / developer), `is_active`, `last_seen_at` | 1 |
| `clients` | `name`, `crm_client_id` (nullable → CRM `hpv_clients.id`), `primary_domain`, `notes` | 1 |
| `projects` | `client_id`, `name`, `domain`, `industry`, `market` (country code), `locations text[]`, `content_language`, `working_language`, `business_services jsonb` (name, margin: high/normal, is_priority), `target_audience`, `business_goals`, `conversion_goals`, `excluded_topics text[]`, `scope text[]` (tech_audit, tech_foundations, keyword_research, structure, content_strategy, content_mgmt, wireframes_only, link_building, monitoring), `status`, `owner_id`, `start_date`, `strategy_months` (default 6), `content_per_month`, `upsell_reminder_at`, `archived_at` | 1 |
| `project_members` | `project_id`, `user_id`, `project_role` | 1 |
| `project_competitors` | `project_id`, `domain`, `source` (client / seo / ahrefs), `is_active`, `notes` | 1 |
| `seed_keywords` | `project_id`, `keyword`, `kind` (service / customer_term / location / high_margin / excluded / product) | 1 |
| `intake_items` | `project_id`, `key` (gsc_access, admin_access, seed_keywords, competitors, conversion_goals, previous_research, style_guide, product_list), `status` (missing / requested / received / n_a), `note`, `updated_by` | 1 |
| `status_history` | `project_id`, `from_status`, `to_status`, `user_id`, `note`, `at` | 1 |
| `activity_log` | `project_id`, `user_id`, `entity`, `entity_id`, `action`, `diff jsonb`, `at` | 1 |
| `business_profiles` | `project_id`, `version`, `positioning`, `services jsonb`, `audiences jsonb`, `differentiators`, `proof_assets jsonb`, `source` (ai / manual), `approved_by`, `approved_at` | 3 |
| `style_guides` | `project_id`, `answers jsonb` (the 11 questions of the style-guide template), `received_at` | 2 |

**Project status** (as requested):

`draft → researching → ai_analysis_complete → seo_review → client_review → approved → production → completed`

The state machine allows going back from `client_review` to `seo_review` when the client requests changes. It also has an `on_hold` flag that does not count as a status.

Real projects have **several client gates** (keyword research, structure, strategy, content). These live in `approvals` (§3.5), and the project status advances when the gates for its scope pass. The status stays a readable summary, while the gates hold the detail.

### 3.2 Research

| Table | Key columns | Phase |
|---|---|---|
| `files` | `project_id`, `kind` (import / reference_doc / export), `filename`, `mime`, `size`, `storage_key`, `sha256`, `uploaded_by` | 2 |
| `imports` | `project_id`, `file_id`, `source` (ahrefs_keywords / ahrefs_organic / ahrefs_content_gap / ahrefs_matching_terms / gkp / screaming_frog / manual), `sheet`, `location`, `column_map jsonb`, `row_count`, `status`, `error` | 2 |
| `keywords` | `project_id`, `term`, `term_normalized` (unique per project), `translation`, `parent_topic`, `category`, `is_excluded`, `exclusion_reason`, `first_import_id` | 2 |
| `keyword_metrics` | `keyword_id`, `location` (null = national), `volume`, `kd`, `cpc`, `traffic_potential`, `source`, `fetched_at`; unique `(keyword_id, location, source)` | 2 |
| `competitor_rankings` | `keyword_id`, `competitor_id`, `location`, `position`, `url`, `traffic`, `source`, `fetched_at` | 2 |
| `own_rankings` | `keyword_id`, `location`, `position`, `url`, `source` (ahrefs / gsc), `fetched_at` | 2 |
| `serp_snapshots` | `keyword_id`, `location`, `serp jsonb`, `features text[]`, `fetched_at` | 5 |
| `audit_issues` *(optional module)* | `project_id`, `import_id`, `issue_type`, `url`, `detail jsonb`, `size` (XL / M / S) | 5+ |

### 3.3 Keyword intelligence

| Table | Key columns | Phase |
|---|---|---|
| `keyword_analysis` | `keyword_id` (PK), `intent` (informational / commercial_investigation / commercial / transactional / navigational / problem / mixed), `modifiers text[]`, `is_local`, `business_value` 1–5, `commercial_opportunity` 1–5, `priority` (P1 / P2 / parked), `priority_score numeric`, `cluster_id`, `role` (primary / secondary / supporting), `reason`, `notes`, `status` (suggested / accepted / overridden), `reviewed_by`, `reviewed_at` | 3 |
| `ai_suggestions` | `project_id`, `entity`, `entity_id`, `field`, `value jsonb`, `reason`, `model`, `prompt_version`, `job_id`, `status` (pending / accepted / rejected) | 3 |
| `clusters` | `project_id`, `name`, `pillar`, `bucket` (commercial / informational / problem / comparison / local), `intent`, `total_volume`, `priority`, `target_page_id`, `cannibalization_rule`, `notes` | 3 |
| `parked_topics` | `project_id`, `keyword_id`, `reason`, `recommended_handling`, `revisit_after` | 3 |

### 3.4 Structure, strategy, wireframes

| Table | Key columns | Phase |
|---|---|---|
| `pages` | `project_id`, `url`, `page_type` (home / city_hub / service / city_service / article / case_study / pillar_hub / category / product / support), `parent_page_id`, `location`, `h1`, `seo_title`, `meta_description`, `intent`, `seo_goal`, `cta_label`, `cta_url`, `priority` (P1 / P2 / hub / support), `lifecycle` (existing / new / redirect / merge / remove), `schema_types text[]`, `word_count_min`, `word_count_max`, `notes`, `version` | 3 |
| `page_keywords` | `page_id`, `keyword_id`, `role` (primary / secondary / supporting); **unique `(keyword_id)` where `role='primary'`** | 3 |
| `internal_links` | `project_id`, `from_page_id`, `to_page_id` or `to_url`, `anchor`, `placement`, `link_type` (menu / button / card / text), `wireframe_section_id`, `note` | 3 |
| `roadmap_items` | `project_id`, `month date`, `priority`, `page_id`, `cluster_id`, `content_type` (article / case_study / service / pillar / category), `title`, `content_direction`, `location_context`, `cta`, `social_hook`, `client_input`, `status` (planned / wireframe / writing / qa / client / published), `assignee_id`, `due_date` | 3 |
| `measurement_plan` | `project_id`, `period`, `focus`, `what`, `where_measured`, `success_signal`, `decision`, `content_scope`, `owner`, `status`, `note` | 3 |
| `paid_plan_items` | `project_id`, `roadmap_item_id`, `seo_role`, `meta_creative`, `paid_role`, `search_target`, `remarketing_next`, `kpi` | 3 |
| `wireframes` | `page_id`, `version`, `essence`, `flow text[]`, `language_note`, `proof_requirements`, `must_have text[]`, `forbidden text[]`, `visual_sequence`, `status`, `approved_by`, `approved_at` | 3 |
| `wireframe_sections` | `wireframe_id`, `position`, `h2`, `goal`, `what_to_write`, `seo_usage`, `link_cta`, `length_min`, `length_max`, `benchmark_urls text[]`, `local_variation` | 3 |
| `wireframe_inputs` | `wireframe_id`, `item`, `description`, `provided` | 3 |
| `production_tasks` | `project_id`, `page_id`, `role` (seo / writer / developer / designer), `priority` (P1 / P2 / P3 or XL / M / S), `title`, `source_url`, `action`, `target_url`, `done_when`, `status`, `crm_task_id` | 4 |

### 3.5 Documents, approvals, logs

| Table | Key columns | Phase |
|---|---|---|
| `documents` | `project_id`, `doc_type` (seo_strategy / content_strategy / roadmap / wireframe_deck / keyword_research / structure / dev_brief / writer_brief / designer_brief / seo_checklist), `audience` (client / internal), `language`, `version`, `status` (draft / review / approved / sent), `title`, `content jsonb` (sections), `source_hash` (hash of the data it was built from, to detect stale documents), `model`, `prompt_version`, `created_by` | 4 |
| `document_files` | `document_id`, `format` (pdf / docx / xlsx), `file_id` | 4 |
| `reference_docs` | `doc_type`, `language`, `file_id`, `extracted_text`, `is_active`. These are the uploaded house examples, used as style anchors for Claude. | 4 |
| `prompt_templates` | `key`, `version`, `provider`, `model`, `body`, `json_schema`, `is_active` | 3 |
| `approvals` | `project_id`, `subject_type` (keyword_research / structure / content_strategy / wireframe / document / content), `subject_id`, `subject_version`, `stage` (internal / client), `status` (pending / approved / changes_requested), `requested_by`, `decided_by`, `decided_at`, `comment` | 6 (minimal version in 4) |
| `comments` | `project_id`, `subject_type`, `subject_id`, `author_id`, `is_client`, `body`, `resolved_at` | 6 |
| `notifications` | `user_id`, `project_id`, `kind`, `payload`, `read_at`, `emailed_at` | 6 |
| `jobs` | `project_id`, `type`, `payload jsonb`, `status` (queued / running / done / failed), `progress`, `attempts`, `result jsonb`, `error`, `created_by`, `started_at`, `finished_at` | 2 |
| `api_logs` | `project_id`, `job_id`, `provider` (openai / anthropic / ahrefs / dataforseo / gsc), `endpoint`, `model`, `request_hash`, `status_code`, `tokens_in`, `tokens_out`, `units`, `cost_usd`, `duration_ms`, `error`, `created_at` | 1 (table only), used from 3 |

---

## 4. UI structure

### 4.1 Shell and design system

- **Layout:** a left sidebar (collapsible, icons plus labels), a top bar (project switcher, ⌘K search, job indicator, user menu), and the content area. At phone width the sidebar becomes a bottom sheet.
- **Theme tokens** (dark, khaki/olive accent; final values are tuned at build time):

  | Token | Value |
  |---|---|
  | `--bg` | `#0b0b0a` |
  | `--surface` | `#131311` |
  | `--card` | `#1b1b19` |
  | `--line` | `#2a2a26` |
  | `--ink` | `#ecebe4` |
  | `--muted` | `#9a998d` |
  | `--accent` | `#b3b07a` (khaki) |
  | `--accent-strong` | `#8f8f4e` (olive) |
  | `--accent-ink` | `#16160f` |
  | `--ok` | `#8fbf6a` |
  | `--warn` | `#d9b35c` |
  | `--danger` | `#d9695c` |

- **Typography:** Inter or Satoshi, 14px base, tabular numerals in data tables.
- **Components:**
  - status pill and 8-step workflow stepper;
  - data table with column filters and saved views;
  - side panel editor;
  - suggestion chip with accept/override (for AI values);
  - approval banner;
  - empty states that say what unlocks the tab.
- **Role colors** match the house convention: SEO = green, writer = blue, developer = orange, designer = violet. They appear on task chips.

### 4.2 Screens

| Screen | Contents | Phase |
|---|---|---|
| **Dashboard** | Active projects (client, status stepper, next gate, owner). My pending approvals and reviews. Recently generated documents. This month's roadmap items due. Intake items still missing. Upsell reminders (month 5). | 1 (docs and roadmap widgets fill in later) |
| **Projects** | Table and board by status. Filters: status, owner, scope, market. | 1 |
| **New project** | A 3-step form: 1) client and domain (with a CRM client picker); 2) business (industry, market, locations, services with high-margin flag, audience, goals, exclusions); 3) scope (the sold services), competitors, seed keywords by kind. | 1 |
| **Project → Overview** | Business profile, scope, team, intake checklist (§1.3), workflow stepper with transition buttons, status history, activity. | 1 |
| **Project → Research** | File uploads (Ahrefs / GKP / SF) with column mapping preview, import history, competitor list, API fetch buttons (Phase 5). | 2 |
| **Project → Keywords** | A keyword × location grid. Columns: volume, KD, intent, cluster, priority, primary/secondary, recommended URL, notes, and competitor positions (toggle). Supports bulk actions, exclusions, "accept all suggestions in view", and cluster and bucket views. | 2 → 3 |
| **Project → Structure** | Sitemap tree plus the URL / title / H1 matrix. Page panel: type, primary and secondary keywords, intent, SEO goal, H1, CTA. Also the internal-link map and cannibalization warnings. | 3 |
| **Project → Content Strategy** | The 6-month roadmap (month columns or table), clusters, measurement plan, paid/Meta plan, parked topics. | 3 |
| **Project → Wireframes** | A list per page, and an editor that mirrors the house wireframe format (§1.5). | 3 |
| **Project → Documents** | Client and internal documents with generate / regenerate, a "stale" badge when the data changed, versions, and an approval state. | 4 |
| **Project → Exports** | PDF / DOCX / XLSX downloads in the house layouts, a Google Sheets link (later), and a push of tasks to the CRM. | 4 |
| **My work** | A role-filtered task and wireframe list across projects. It is the landing page for Designer and Developer. | 3–4 |
| **Settings** (admin) | Team and roles, API keys (stored server-side and shown masked), scoring weights, prompt templates, reference documents, branding. | 1 (team), later the rest |

### 4.3 Role visibility

| Capability | Admin | SEO Manager | Content Manager | Designer | Developer |
|---|:-:|:-:|:-:|:-:|:-:|
| Create / edit projects, intake | ✓ | ✓ | – | – | – |
| Change project status | ✓ | ✓ | only `production → completed` | – | – |
| Research, keywords, clusters, structure (edit) | ✓ | ✓ | view | view (structure) | view (structure) |
| Approve strategy / structure (internal gate) | ✓ | ✓ | – | – | – |
| Content strategy, roadmap | ✓ | ✓ | ✓ edit status/assignee | – | – |
| Wireframes | ✓ | ✓ | ✓ | view | view |
| Writer briefs / content QA | ✓ | ✓ | ✓ | – | – |
| Designer brief | ✓ | ✓ | ✓ | view | – |
| Developer brief, technical tasks | ✓ | ✓ | view | – | view + update task status |
| Generate documents | ✓ | ✓ | writer briefs only | – | – |
| Settings, API keys, team | ✓ | – | – | – | – |

---

## 5. Development roadmap

The phase order follows the brief. Phase 3 is split into 3a and 3b because the planning work is a large, separate step.

| Phase | Scope | Main result for the team |
|---|---|---|
| **1. Foundation** | WordPress plugin (roles, SPA shell, REST proxy, settings). FastAPI + Postgres skeleton with migrations. Users sync, clients (linked to CRM), projects with intake, competitors, seed keywords, and scope. Workflow state machine with history. Dashboard and project Overview. Dark design system. | Projects and intake move out of email and Excel. Everyone sees status and next steps. |
| **2. Research system** | File storage and upload. Import parsers for Ahrefs (keywords, organic, matching terms, content gap), Google Keyword Planner and Screaming Frog, with a generic column auto-map and preview. Keyword database with keyword × location metrics, competitor rankings, dedupe/merge, exclusions and translation. Keywords grid. **XLSX export in the house "Kulcsszókutatás" layout.** Style-guide questionnaire. Jobs table and worker. | Assembling the keyword research sheet goes from hours to minutes. |
| **3a. AI keyword intelligence** | LLM adapter with prompt versioning, caching and cost logs. Business profile (from a site crawl plus the intake). Intent classification, business value, clustering into 5 buckets, deterministic priority score, and the suggestion/accept UI. | First-pass classification, clustering and prioritization, which the SEO manager reviews rather than builds. |
| **3b. AI planning** | URL mapping with DB-enforced cannibalization rules, site structure generator (page models, URL / title / H1 matrix, internal links), 6-month roadmap generator (cadence-aware, with parked topics, measurement and paid plan), and wireframe generator in the house format. | Structure, strategy and wireframes arrive pre-drafted and follow the house rules. |
| **4. Claude document engine** | Reference-document library, Claude section generation, renderers: PDF (branded HTML → WeasyPrint), DOCX (wireframe, dev, writer and designer briefs as house-style tables), XLSX (the 6-sheet strategy workbook). Stale-document detection, versions, a minimal internal approval step, production tasks, and a push to CRM tasks. | Client and internal documents are generated from approved data. |
| **5. API integrations** | DataForSEO (volumes per location to replace the manual GKP exports, SERP data, related keywords). Ahrefs API v3 (competitor organic keywords, content gap, backlinks, traffic). Per-project budget caps and response caching. Optionally, a Screaming Frog import → technical audit summary. | No more manual exports. Research refreshes with one click. |
| **6. Advanced workflow** | Full approvals (internal and client). Client review in the **existing client portal**: comment-only keyword view and approve/request changes. Notifications (email and CRM chat). Upsell reminder. Monthly report scaffolding. Later: Google Search Console integration for the measurement plan. | Closes the loop from proposal to monitoring. |

---

## 6. Phase 1 implementation plan

### 6.1 Architecture of Phase 1

```
plugins/helloprovision-seo-os/             WordPress plugin
  helloprovision-seo-os.php                bootstrap, constants, activation (roles/caps)
  includes/roles.php                       5 roles + capability map, admin gets all
  includes/settings.php                    API base URL, shared secret, route/subdomain
  includes/signing.php                     HMAC request signing (pure PHP, unit-tested)
  includes/proxy.php                       /wp-json/hpv-seo/v1/{path} → FastAPI (GET/POST/PATCH/DELETE)
  includes/app.php                         renders the SPA shell at /seo-os/ (noindex, logged-in only)
  includes/users.php                       pushes user/role changes to FastAPI (profile_update, set_user_role)
  assets/app/app.js                        router, API client, layout
  assets/app/ui.js                         shared components (table, pill, stepper, panel, form)
  assets/app/views/dashboard.js
  assets/app/views/projects.js             list + board
  assets/app/views/project-new.js          3-step form
  assets/app/views/project.js              header + tabs; Overview live, other tabs show "unlocks in Phase N"
  assets/app/views/settings.js             team & roles (read-only list from WP + role)
  assets/app/app.css                       dark design tokens + components
  assets/app/vendor/preact-htm.js          same vendor file as the CRM

services/seo-os-api/                       FastAPI service
  app/{main,config,db,auth,permissions}.py
  app/models/{user,client,project,workflow,log}.py
  app/schemas/{user,client,project,dashboard}.py
  app/routers/{health,users,clients,projects,workflow,dashboard}.py
  app/services/workflow.py                 state machine, allowed transitions per role, history
  alembic/versions/0001_foundation.py
  tests/{conftest,test_auth,test_projects,test_workflow,test_dashboard}.py
  Dockerfile, docker-compose.yml (api + postgres), .env.example, pyproject.toml

tests/seo-os.php                           PHP tests (no WordPress): signing, capability map, proxy path whitelist
docs/seo-os/ARCHITECTURE.md                this document
README.md                                  new section: install, configure, run
```

### 6.2 Database changes (migration `0001_foundation`)

- **Tables:** `users`, `clients`, `projects`, `project_members`, `project_competitors`, `seed_keywords`, `intake_items`, `status_history`, `activity_log`, `api_logs` (empty, ready for later phases).
- **Constraints:**
  - `CHECK` on status and roles;
  - unique `users.wp_user_id`;
  - unique `(project_id, lower(domain))` on competitors;
  - unique `(project_id, lower(keyword))` on seed keywords.
- **Indexes:** `projects(status)`, `projects(owner_id)`, `status_history(project_id, at)`, `activity_log(project_id, at)`.
- **Seed data:** new projects automatically get the 8 intake items (§1.3). Items that don't apply to the scope start as `n_a`.

WordPress stores only its plugin option (`hpv_seo_os_settings`) and the roles. It creates **no WordPress database tables.**

### 6.3 API endpoints (Phase 1)

```
GET    /health
GET    /me                                   current user + capabilities
GET    /users                                team list (admin)
PUT    /users/{wp_user_id}                   upsert from WordPress (called by the plugin)
GET    /clients?search=  POST /clients  PATCH /clients/{id}
GET    /projects?status=&owner=&q=
POST   /projects                             create (+ intake items, competitors, seeds)
GET    /projects/{id}                        full overview payload
PATCH  /projects/{id}
POST   /projects/{id}/archive
PUT    /projects/{id}/competitors            replace list
PUT    /projects/{id}/seed-keywords          replace list
PATCH  /projects/{id}/intake/{key}
POST   /projects/{id}/transition             {to, note} → validated by workflow.py + role
GET    /projects/{id}/history                status history + activity
GET    /dashboard                            active projects, my pending items, missing intake
```

### 6.4 Implementation steps

1. **Backend skeleton:** config, database, HMAC auth dependency, capability matrix, health endpoint, docker-compose with Postgres 16, Alembic migration `0001`.
2. **Models and schemas** for users, clients, projects and their child lists, with validation (domains normalized, locations trimmed, scope values checked against the service catalogue).
3. **Workflow service:**
   - It knows the allowed transitions: forward one step, back from `client_review` to `seo_review`, and admin override with a required note.
   - Each transition has role guards and writes to `status_history` and `activity_log`.
   - Phase 1 has no data gates yet. Gates are added as later phases bring their data.
4. **Routers and pytest suite** against a real Postgres, covering:
   - authentication: valid, expired and tampered signatures;
   - CRUD;
   - intake seeding;
   - each allowed and forbidden transition for each role;
   - the dashboard aggregation.
5. **WordPress plugin:**
   - roles and capabilities on activation;
   - the settings page, with a connection test button;
   - signing and the proxy, with a path whitelist, forwarding of the signed user, a 30s timeout and clean error mapping;
   - user sync hooks;
   - the SPA shell at `/seo-os/`, redirecting to login if the user is not logged in.
6. **SPA:**
   - layout and design tokens;
   - Dashboard, Projects list/board, New project wizard, and Project Overview (intake checklist, stepper, transitions, history);
   - tab placeholders;
   - Settings → Team.
7. **Tests and docs:**
   - `php tests/seo-os.php` (no WordPress);
   - pytest;
   - a manual smoke test on a local WordPress with the plugin talking to the dockerized API;
   - a README section in Hungarian, matching the existing README.

### 6.5 Phase 1 acceptance criteria

- An Admin creates a project for "Imperial Kitchens / Kitchen Remodeling / Naples, FL". It has competitors, seed keywords by kind, and a scope, and it appears on the dashboard with the missing intake items.
- An SEO Manager can move the project through `draft → researching → … → approved`. A Designer or Developer sees the project read-only and cannot change status; the API returns 403, not just a hidden button.
- Every status change appears in the history with the user, the time and the note.
- FastAPI rejects any request without a valid WordPress signature, and it is reachable only from the WordPress host.
- The UI is dark, usable at 375px width, and never shows the WordPress admin chrome.
- The PHP and Python test suites pass.

---

## 7. Decisions needed before Phase 1 coding

1. **Hosting for FastAPI + PostgreSQL.** WordPress can stay where it is, but the API and database need a server: a small VPS or a managed container host plus managed Postgres. Is there an existing server, or should Phase 1 include Docker deployment files for a VPS?
2. **UI language.** The internal CRM is Hungarian, while this brief is in English. Recommendation: a Hungarian team UI, with the per-project content language driving all client output.
3. **Entry point.** Should the app live at `crm.helloprovision.com/seo-os/` (inside the CRM subdomain, shared login) or at its own `seo.helloprovision.com`? Recommendation: its own subdomain, using the same domain-routing pattern the CRM already uses.
4. **Roles vs. existing CRM staff.** Should SEO OS roles be added *alongside* the existing `hpv_staff` role? Recommendation: yes. A user can hold both, and SEO OS never grants WordPress admin access.
5. **Scope confirmations:**
   - XLSX exports in the house layouts in Phase 2 and 4, in addition to PDFs (recommended, see §1.6);
   - the optional technical-audit module (Screaming Frog import) in Phase 5.
