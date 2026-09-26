# v2 – rozdelenie na etapy a stav implementácie

Zadanie: `moje-recepty-v2-predplatne-admin-pravne.md`. Táto stránka drží dohodnuté rozdelenie na menšie kusy
(etapa = samostatná vetva/PR) a čo je z nej hotové. Poradie sleduje kapitolu 16 zadania: najprv merateľná AI
a bezpečná administrácia, potom ledger a Cashier, potom právne stránky, súhlas a súkromie, nakoniec Plus funkcie.

| # | Etapa | Stav | Obsah |
|---|---|---|---|
| 1 | **Administrácia a meranie AI** | ✅ hotové (PR #1) | Rola administrátora platformy, `/admin`, MFA, audit, meranie usage a odhad nákladov AI, prepínanie modelu / reasoning effort / kvality obrázkov, kill switch, cenník sadzieb, účet `support@moje-recepty.sk` |
| 2 | **Ledger a granty použití** | ✅ hotové (vetva `v2-etapa-2-ledger`) | `UsageGrant`, `UsageReservation`, `UsageLedgerEntry`, rezervácia pod zámkom, spotreba/uvoľnenie, skúšobné granty, `reconciling`, súbežné testy (akceptačné testy 6, 7, 22, 23) |
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
- Denné limity na domácnosť zostali aj po etape 2, už len ako frekvenčná ochrana (kap. 4, krok 1 „limity frekvencie“);
  platenú kvótu určuje ledger.

## Etapa 2 – čo je hotové

### Dátový model (migrácia `create_usage_ledger_tables`)

- `usage_grants`: domácnosť, `kind` (`text` | `image_standard`), `source` (`trial` | `subscription` | `addon` |
  `compensation`), `quantity`, počítadlá `reserved/consumed/revoked_quantity`, `valid_from`, `expires_at` (null =
  dokúpené bez kalendárnej expirácie), `revoked_at`, unikátny `source_key` (idempotencia: skúšobný grant, faktúra,
  refund…). Počítadlá sú cache ledgeru; `UsageLedger::reconcile()` ich prepočíta z rezervácií a overí súčet pohybov.
- `usage_reservations`: jedna rezervácia na AI úlohu (unikátny `ai_job_id`), stav `reserved` → `consumed` | `released`.
- `usage_ledger_entries`: append-only pohyby so signovaným `movement` a unikátnym `source_key`; súčet pohybov grantu
  = jeho dostupné množstvo. Opravy sú nové kompenzačné riadky.

### Transakčné účtovanie (kap. 4)

- `AiJobLifecycle::create()` vytvorí úlohu a rezerváciu v jednej transakcii; bez voľného použitia úloha nevznikne.
  Zhodný vstup (rovnaký `request_key`) vráti existujúcu úlohu ešte pred kontrolou zostatku – retry nikdy neplatí dvakrát.
- Poradie čerpania: najskôr grant s najbližšou expiráciou (skúšobný, mesačný), potom najstarší dokúpený. Výber
  prebieha pod `lockForUpdate` a navyše podmieneným `UPDATE … WHERE dostupné ≥ 1`, takže ani bez zámkov (SQLite)
  nemôžu dve úlohy vziať to isté použitie.
- Úspech: výsledok a spotreba sa ukladajú v jednej transakcii (`succeed()`); spotreba je idempotentná.
- Definitívna chyba (odmietnutie, validácia, odmietnuté spojenie): úloha `failed`, použitie sa uvoľní raz.
- Nejasný výsledok (timeout po odoslaní požiadavky, worker zabitý časovým limitom – `failed()` na queue jobe):
  stav **`reconciling`**, rezervácia zostáva. Rozhodnutie robí operátor: `php artisan app:ai-reconcile` vypíše
  úlohy, `--fail` (s ID alebo `--all`, `--reason=`) ich uzavrie, uvoľní použitie a zapíše audit
  `ai.job.reconciled_failed`. SDK nevie výsledok obnoviť, externý náklad ostáva prevádzkovateľovi.
- Expirácia grantu počas rezervovanej úlohy nebráni dokončeniu; uvoľnené použitie sa do nového obdobia neprenáša.
- Revokácia (`UsageLedger::revoke()`, pripravené pre refund workflow etapy 3): iba nevyužité jednotky jedného grantu,
  idempotentná podľa `source_key`, nikdy z iného balíka.

### Skúšobné granty a rollout

- 3 textové operácie a 1 obrázok Standard (`RECIPES_USAGE_TRIAL_TEXT/IMAGES`), **raz na overeného vlastníka a raz
  na domácnosť** (`source_key = trial:{kind}:user:{id}`); druhá domácnosť toho istého používateľa skúšku neobnoví.
- Udelenie: listener na `Verified`, lenivo pri prvej AI požiadavke (účty overené pred ledgerom) a
  `php artisan app:usage-backfill-trials` pre rollout – všetko idempotentné, minulé AI úlohy sa spätne nepočítajú.
- Kompenzácia (do etapy 4 bez UI): `php artisan app:usage-compensate {household} {kind} {n} --reason= [--key= --expires=]`
  vystaví samostatný auditovaný grant (`usage.compensation.granted`).

### Feature flag a UI

- `RECIPES_USAGE_ENFORCE=false` vypne rezervácie (pôvodné správanie s dennými limitmi); `consume/release` sú vtedy no-op.
- Nastavenia → **AI použitia** (`/settings/usage`): „Skúšobné 3/3 · platí do …“, dokúpené, spolu; zoznam balíkov
  a kompenzácií v poradí čerpania.
- Pri AI tlačidlách: „Spotrebuje 1 použitie · zostáva N – skúšobné: x/y, dokúpené: z“. Po vyčerpaní jasná správa bez
  automatickej platby (odkaz na kúpu pribudne v etape 3). Stav `reconciling` má vlastné hlásenie a nepolluje.
- Admin → AI použitie: filter a badge pre `reconciling`.

### Testy

`tests/Feature/Usage/UsageLedgerTest.php` pokrýva akceptačné testy 6 (súbeh o jedno použitie, retry workera),
7 (uvoľnenie raz / atomická spotreba), 22 (kill switch nemaže granty, ručná editácia funguje) a 23 (backfill
neopakuje skúšku), plus poradie čerpania, expiráciu počas rezervácie, `reconciling` s CLI rozhodnutím, revokáciu,
rekonštrukciu počítadiel, kompenzačný príkaz, stránku nastavení a vypnutý flag. Súbežnosť sa v testoch (SQLite,
jedna transakcia) overuje sekvenčne; v produkcii ju kryje `lockForUpdate` + podmienený update + unikátne kľúče.

### Otvorené vstupy pre launch (nezmenené zo zadania, kap. 18)

Prevádzkovateľ a fakturačné údaje, potvrdenie cien a limitov, výsledky nákladového merania (30 + 30 úloh na reálnom
kľúči), analytický poskytovateľ, hosting/e-mail/zálohy/monitoring, DPH/OSS režim, právne schválenie textov.
