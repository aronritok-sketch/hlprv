# Levelezés (helloprovision-mail) ↔ CRM / értékesítés

A levelezés modul (`plugins/helloprovision-mail/`, szerver: `services/seo-os-api/app/services/mail`) ezekkel kapcsolódik a CRM-hez.

| Mi | Hogyan |
|---|---|
| Menüpont és oldal a CRM-ben | `hpv_crm_app_extensions` + `registerExtension` (`docs/integrations/crm-extensions.md`) |
| Ügyfél-párosítás | A CRM ügyfelek `email`, `billing_email` címe és a portál-felhasználók címe óránként (`hpv_mail_contacts` cron) és ügyfél mentésekor (`hpv_p_inserted_client`, `hpv_p_updated_client`) megy a szerverre. |
| Feladat levélből | `POST hpv/v1/mail-task` → belsőleg a `POST hpv/v1/pm/tasks` (a szokásos értesítésekkel), a leírásban a levél; `hpv_p_log` bejegyzés az ügyfélnél. |
| Kimenő levél új érdeklődőnek | Ha a sikeres `mail/send` válaszában lévő ügyfél `status = lead` és `lead_stage = new`: `hpv_sales_set_stage( $id, 'contacted', '', $user )`. A tölcsér mezőit a levelezés közvetlenül nem írja. |
| „Felvétel érdeklődőként” (ismeretlen feladó) | `POST hpv/v1/mail-lead` → `hpv_leads_ingest( array( 'source' => 'mail', 'form' => 'E-mail', 'name', 'email', 'message' => tárgy + első bekezdés ) )`, majd a levél az ügyfélhez kötődik. Magától nem történik (spam). |

Jogosultság: a levelezés végpontjai a CRM munkatársaké (`hpv_p_is_staff`) és az SEO OS szerepkörűeké; a portál ügyfelei nem érik el.
