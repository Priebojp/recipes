# v2 – rozdelenie na etapy a stav implementácie

Zadanie: `moje-recepty-v2-predplatne-admin-pravne.md`. Táto stránka drží dohodnuté rozdelenie na menšie kusy
(etapa = samostatná vetva/PR) a čo je z nej hotové. Poradie sleduje kapitolu 16 zadania: najprv merateľná AI
a bezpečná administrácia, potom ledger a Cashier, potom právne stránky, súhlas a súkromie, nakoniec Plus funkcie.

| # | Etapa | Stav | Obsah |
|---|---|---|---|
| 1 | **Administrácia a meranie AI** | ✅ hotové (táto vetva) | Rola administrátora platformy, `/admin`, MFA, audit, meranie usage a odhad nákladov AI, prepínanie modelu / reasoning effort / kvality obrázkov, kill switch, cenník sadzieb, účet `support@moje-recepty.sk` |
| 2 | Ledger a granty použití | ⬜ | `UsageGrant`, `UsageReservation`, `UsageLedger`, rezervácia pod zámkom, spotreba/uvoľnenie, skúšobné granty, `reconciling`, súbežné testy (akceptačné testy 6, 7, 22, 23) |
| 3 | Cashier a Stripe | ⬜ | `BillingAccount`, katalóg `PlanVersion`/`AddonVersion`, Checkout, webhook inbox, `PaidEntitlement`, mesačné granty pri ročnej platbe, portal, refund workflow (testy 1–5, 8–12, 14) |
| 4 | Admin – finančné moduly | ⬜ | Dashboard MRR/inkaso/refundácie, predplatné, balíky a objednávky, použitia a kompenzácie, synchronizácia so Stripe |
| 5 | Právne stránky, cookies, súkromie | ⬜ | `/vop`, `/ochrana-osobnych-udajov`, `/cookies`, `/odstupenie-od-zmluvy`, verzie a akceptácie, registrácia služieb, **cookie lišta podľa kap. 10 tohto zadania** (nie z iného projektu), consent receipt, export/výmaz, žiadosti (testy 15–21) |
| 6 | Plus funkcie | ⬜ | Týždenný jedálniček, nákupný zoznam, uložené skupiny a filtre – bez platenej AI, ak stačí existujúci algoritmus |
| 7 | Staging a launch | ⬜ | Test clock, doklady, identita prevádzkovateľa, meranie 30+30 AI úloh, potvrdenie cien, produkčné secrets a webhook |

## Etapa 1 – čo je hotové

### Rola administrátora platformy

- `users.is_platform_admin` + `platform_admin_granted_at`; nie je mass-assignable, registrácia ju nikdy nenastaví.
- `php artisan app:grant-platform-admin <email>` – idempotentné udelenie roly existujúcemu účtu; `--create` vytvorí
  overený účet (heslo cez `--password`, inak sa vygeneruje a raz vypíše); `--revoke` odoberie rolu, odhlási relácie
  (database session driver) a otočí remember token; posledného administrátora odobrať nemožno. Všetko ide do auditu.
- `PlatformAdminSeeder` (súčasť `db:seed`) vytvorí `ADMIN_EMAIL` (predvolene `support@moje-recepty.sk`). Mimo produkcie
  s heslom `ADMIN_INITIAL_PASSWORD` alebo `password`; v produkcii účet vytvorí iba ak je `ADMIN_INITIAL_PASSWORD` nastavené,
  inak vypíše pokyn použiť príkaz.
- `/admin` vyžaduje rolu **a** potvrdené dvojfaktorové overenie (`ADMIN_REQUIRE_TWO_FACTOR=true`); bez MFA presmeruje
  na Nastavenia → Zabezpečenie s vysvetlením. Zmeny nastavení a cenníka navyše vyžadujú opätovné potvrdenie hesla
  (`password.confirm`). Rate limit 60 požiadaviek/min.
- Administrácia nezobrazuje obsah receptov, prompty, výsledky ani mená stravníkov – iba ID a názvy domácností,
  e-mail vlastníka a agregáty.

### Meranie AI a náklady

- `ai_jobs` má `profile` (snímka reasoning effort / kvality / rozmeru pri vytvorení), tokeny (vstup, cache, výstup,
  reasoning, obrazový výstup), `estimated_cost_micro_usd`, `cost_rate_id`, `duration_ms`. Usage od poskytovateľa je
  autoritatívne; cena je odhad z verzovaného cenníka `ai_cost_rates` (celé mikro-USD, nikdy float).
- `AiCostRateSeeder` nahrá ceny zo zadania (gpt-6-luna 0,10 / 0,50 USD za 1M tokenov; gpt-image-2 low/medium/high pre
  1024×1024 a 1536×1024). Nová cena = nový riadok s `effective_from`; staré úlohy ostávajú ocenené pôvodnou sadzbou.
- `/admin/ai`: náklady, počty úloh, tokeny a chyby za 7/30/90 dní, rozpad podľa modelu, domácnosti a dňa, posledné
  úlohy s profilom, tokenmi, cenou, trvaním a chybou. Úlohy bez sadzby sú označené („bez ceny“).
- `/admin/ai/settings`: kill switch, textový model, **reasoning effort (default/low/medium/high)**, obrázkový model,
  **kvalita (low/medium/high)** a rozmer, denné limity, mesačný rozpočet (iba alarm). Hodnoty sú v `app_settings`,
  `.env` zostáva predvoľbou; „Vrátiť na predvolené“ zmaže prepisy. Každá zmena má audit s dôvodom.
- Reasoning effort sa posiela iba OpenAI (Responses API `reasoning.effort`) cez `HasProviderOptions` agenta; pre iných
  poskytovateľov sa neposiela nič. Kvalitu a rozmer obrázka určuje server (profil na úlohe), klient ich nemení.
- Prehľad `/admin`: domácnosti/účty/recepty, náklady dnes / mesiac / 30 dní, rozpočet, bežiace a zlyhané AI úlohy,
  stav fronty (`jobs`, `failed_jobs`).

### Vedomé odchýlky od zadania

- Admin je postavený na existujúcom stacku (Livewire 4 single-file komponenty + Flux), nie na Filamente: repozitár
  Filament nemal, a druhý UI framework by zdvojil layout, autentifikáciu a MFA. Moduly zo zadania (kap. 8) sa dopĺňajú
  po etapách do rovnakého `/admin`.
- Účet `support@moje-recepty.sk` s heslom vzniká na výslovnú požiadavku vlastníka; heslo sa nikde neukladá v čitateľnej
  podobe a po prvom prihlásení sa má zmeniť. Zadanie inak preferuje setup link – ten je možné doplniť neskôr.
- Denné limity na domácnosť zostávajú ako dočasná ochrana do etapy 2 (granty 30 textov / 5 obrázkov za obdobie).

### Otvorené vstupy pre launch (nezmenené zo zadania, kap. 18)

Prevádzkovateľ a fakturačné údaje, potvrdenie cien a limitov, výsledky nákladového merania (30 + 30 úloh na reálnom
kľúči), analytický poskytovateľ, hosting/e-mail/zálohy/monitoring, DPH/OSS režim, právne schválenie textov.
