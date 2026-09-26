# v2 – rozdelenie na etapy a stav implementácie

Zadanie: `moje-recepty-v2-predplatne-admin-pravne.md`. Táto stránka drží dohodnuté rozdelenie na menšie kusy
(etapa = samostatná vetva/PR) a čo je z nej hotové. Poradie sleduje kapitolu 16 zadania: najprv merateľná AI
a bezpečná administrácia, potom ledger a Cashier, potom právne stránky, súhlas a súkromie, nakoniec Plus funkcie.

| # | Etapa | Stav | Obsah |
|---|---|---|---|
| 1 | **Administrácia a meranie AI** | ✅ hotové (PR #1) | Rola administrátora platformy, `/admin`, MFA, audit, meranie usage a odhad nákladov AI, prepínanie modelu / reasoning effort / kvality obrázkov, kill switch, cenník sadzieb, účet `support@moje-recepty.sk` |
| 2 | **Ledger a granty použití** | ✅ hotové (PR #2) | `UsageGrant`, `UsageReservation`, `UsageLedgerEntry`, rezervácia pod zámkom, spotreba/uvoľnenie, skúšobné granty, `reconciling`, súbežné testy (akceptačné testy 6, 7, 22, 23) |
| 3 | **Cashier a Stripe** | ✅ hotové (vetva `v2-etapa-3-cashier`) | `BillingAccount`, katalóg `PlanVersion`/`AddonVersion`, Checkout, webhook inbox, `PaidEntitlement`, mesačné granty pri ročnej platbe, portal, refund workflow (testy 1–5, 8–12, 14) |
| 4 | **Admin – finančné moduly** | ✅ hotové (vetva `v2-etapa-4-admin-financie`) | Dashboard MRR/inkaso/refundácie/príspevok, detail domácnosti s kompenzáciami a blokovaním, predplatné so synchronizáciou, objednávky s refund workflow, posúdenie refundácií zo Stripe, ledger použití, verzovaný katalóg, inbox Stripe udalostí (test 13, 14) |
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

## Etapa 3 – čo je hotové

### Cashier a fakturačný účet

- `laravel/cashier` 16.8 (stripe-php 21). Billable je **`BillingAccount`** (1:1 s domácnosťou, `payer_user_id`, Stripe
  customer, fakturačné údaje), nie používateľ; `Cashier::useCustomerModel()`. Cashier tabuľky `subscriptions` /
  `subscription_items` sú vlastnou migráciou s FK `billing_account_id`. Mena EUR, locale sk.
- Cashier routy sú vypnuté (`Cashier::ignoreRoutes`) a registrované v `routes/web.php`: `stripe/payment/{id}` a
  `stripe/webhook` → `StripeWebhookController`; CSRF výnimka `stripe/*`.
- Všetky volania do Stripe idú cez rozhranie `StripeGateway` (`CashierStripeGateway`); testy používajú
  `Tests\Support\FakeStripeGateway`, takže sada beží bez siete a bez kľúčov.

### Katalóg a objednávky

- `plan_versions` (`plus_monthly` 2,49 €, `plus_yearly` 24 €, 30 textov / 5 obrázkov za mesačné obdobie) a
  `addon_versions` (`images_20_standard` 3,99 €, `text_100` 1,99 €) – `CatalogSeeder`, verzia 1, stav `active`.
  Stripe price ID prichádzajú z `.env` (`STRIPE_PRICE_*`); bez ID je tlačidlo danej ponuky vypnuté. Nová cena =
  nová verzia; `import_starter` a `images_high` sa v prvej verzii nezakladajú.
- `orders`: nemenný snapshot produktu a ceny, Stripe checkout session / payment intent / invoice / subscription ID,
  stavy `pending → paid | failed | canceled | expired | refunded | partially_refunded`. Klient posiela iba kód ponuky
  (`POST checkout/plan {plan}`, `POST checkout/addon {addon}`); cena je zo serverového katalógu. Kupovať a otvárať
  portál môže iba vlastník domácnosti (policy `manage`); druhé predplatné popri živom sa odmietne.
- Návratová stránka `checkout/success` iba zobrazuje stav objednávky („Platbu overujeme“ → „Zaplatené“) a nikdy
  nič neaktivuje; `checkout/cancel/{order}` uzavrie čakajúcu objednávku.

### Webhooky a nároky

- Inbox `stripe_events`: udalosť sa najprv trvalo uloží (unikátne `event_id`), potom prebehne Cashier synchronizácia
  a doménové spracovanie ide do fronty (`ProcessStripeEvent`). Duplicitné doručenie odpovie 200 bez opakovania;
  zlyhané spracovanie sa opakuje denne (max. 5 pokusov). Neplatný podpis → 403, nič sa neuloží.
- `StripeEventProcessor`: `checkout.session.completed` / `async_payment_succeeded` (balík sa udelí až pri
  `payment_status = paid`, grant s kľúčom `order:{id}`), `async_payment_failed` / `expired`, `invoice.paid` +
  `invoice.payment_succeeded` (zaplatené obdobie iba pre faktúry s riadkom nášho plánu – `PaidEntitlement`
  s kľúčom `invoice:{id}`), `invoice.payment_failed`, `charge.refunded`, `charge.dispute.created`. Rôzne udalosti
  o tej istej platbe pridajú granty raz; stará udalosť nikdy neobnoví refundovaný nárok.
- `PaidEntitlement` = zaplatené obdobie `[starts_at, ends_at)`; `PlanStatus::isPlus()` = aktívne obdobie alebo
  3-dňová tolerancia po neúspešnej obnove (subscription `past_due`), ktorá nepridáva AI granty. Zrušenie obnovovania
  obdobie neskracuje; refundácia ho revokuje.
- Mesačné granty: `MonthlyGrantSchedule` počíta z anchoru n kalendárnych mesiacov v `RECIPES_BILLING_TIMEZONE`
  s orezaním na koniec mesiaca (31. 1. → 28./29. 2. → 31. 3.), intervaly `[start, end)` v UTC, DST zachováva
  lokálny čas. `UsageProvisioner` otvára **len aktuálny** interval (kľúč `sub:{household}:{kind}:{start}`) – lenivo
  pri AI požiadavke, na stránkach nastavení a denne v `app:billing-reconcile`; výpadok scheduleru nevytvorí
  minulé granty. Zmena plánu na hranici obdobia (rovnaký `period.start`) nepridá druhý grant.
- Nastavenia → **Predplatné**: plán, zaplatené do, ďalšia platba, zrušiť/obnoviť obnovovanie (cez bránu), správa
  platby a dokladov (Stripe Customer Portal), dokúpenie balíkov, zoznam objednávok. Verejný cenník `/cennik`
  s prepínačom mesačne/ročne a textom „24 € účtovaných raz ročne; zodpovedá 2 € mesačne“.

### Refundácie a spory

- `RefundService::request()` (CLI `app:billing-refund {order} {amount} --kind= --reason= --text= --images=
  --revoke-plus --key=`): idempotentný kľúč, refund cez Stripe, potom odobratie iba **nevyužitých** jednotiek grantov
  danej objednávky v počte, ktorý určí administrátor (nikdy z iného balíka), voliteľne revokácia zaplateného obdobia
  (ročný plán tým zastaví ďalšie mesačné granty). Kontrola jednotiek prebieha pred pohybom peňazí; zlyhanie Stripe
  zostane ako `failed` case bez odobratia. Audit `billing.refund.processed` / `billing.refund.failed`.
- Refundácia vykonaná v Stripe dashboarde vytvorí `RefundCase` kind `external`, stav `needs_review` – nároky sa
  neodoberajú automaticky. `charge.dispute.created` pozastaví (revokuje) nevyužité jednotky a obdobie danej
  objednávky, vytvorí case `disputed`, účet zostáva.
- Admin UI pre refundácie, objednávky a katalóg je etapa 4; online odstúpenie od zmluvy etapa 5.

### Testy

`tests/Feature/Billing/*` a `tests/Unit/Billing/MonthlyGrantScheduleTest.php` pokrývajú akceptačné testy 1, 2, 3,
4, 5, 8, 9, 10, 11, 12 a 14 (18 feature + 4 unit testov) s falošnou Stripe bránou a podpísanými webhook payloadmi.
Cashierova vlastná synchronizácia (`customer.subscription.updated`) volá Stripe API, preto sa zrušenie obnovovania
testuje cez stránku nastavení a bránu; proti reálnemu test účtu sa overí v etape 7 (test clock).

### Nasadenie

`php artisan migrate`, `php artisan db:seed --class=CatalogSeeder`, doplniť `STRIPE_KEY/SECRET`,
`STRIPE_WEBHOOK_SECRET` (`php artisan cashier:webhook` alebo Stripe CLI; k Cashier udalostiam pridať
`checkout.session.*`, `invoice.paid`, `invoice.payment_failed`, `charge.refunded`, `charge.dispute.created`) a
`STRIPE_PRICE_*`; scheduler spúšťa `app:billing-reconcile` denne o 3:15.

### Etapa 4 – čo je hotové

### Prehľad a finančné ukazovatele (`FinanceReport`)

- **Platiace domácnosti** = domácnosti s aktívnym zaplateným obdobím, ktoré má objednávku (kompenzačný Plus sa počíta
  zvlášť). **MRR** je normalizované mesačne: mesačný plán celou cenou, ročný ÷ 12; z konečných cien, DPH režim nie je
  určený (kap. 18). Stavy Cashier predplatných a počet „bez obnovy“ sú vedľa.
- Za mesiac sa oddelene ukazuje **inkaso** (zaplatené objednávky podľa `paid_at`, rozdelené na predplatné a balíky),
  **refundácie** (prípady `processed`/`needs_review`/`reviewed` podľa `processed_at`), **tržba časovo rozlíšená**
  (zaplatené obdobia rozpočítané na dni, revokované len do revokácie; balíky v deň úhrady) a **AI náklady** v USD
  z odhadov úloh. **Príspevok po variabilných nákladoch** = inkaso − refundácie − AI náklady sa počíta len keď je
  nastavený kurz `RECIPES_BILLING_USD_EUR_RATE`; v UI je výslovne označený ako odhad, nie zisk (bez poplatkov Stripe,
  daní a fixných nákladov).
- Blok **„Vyžaduje pozornosť“**: zlyhané a >1 h nespracované Stripe udalosti, refundácie na posúdenie, otvorené spory,
  AI úlohy `reconciling`, rezervácie držané >24 h, objednávky čakajúce >1 h – každý riadok vedie na prefiltrovaný modul.

### Domácnosti a detail (`/admin/households/{id}`)

- Zoznam má plán (Plus/Free, zaplatené do, kompenzácia), overenie vlastníka, badge „blokovaná“ a filter
  všetky/Plus/Free/blokované.
- Detail (za `password.confirm`): plán a zaplatené do, Cashier predplatné s odkazmi do Stripe dashboardu (test/live
  podľa kľúča – `StripeDashboard`), zostatky použití a AI za 30 dní, zaplatené obdobia, granty s počítadlami,
  objednávky, refundácie. Bez receptov a mien stravníkov.
- **Kompenzačné použitia** (`Compensations::grantUses`): samostatný grant `compensation:{kľúč|uuid}`, dôvod povinný,
  voliteľná expirácia a idempotentný kľúč (tiket); audit `usage.compensation.granted`. CLI `app:usage-compensate`
  používa tú istú službu.
- **Časovo obmedzený Plus** (`Compensations::grantPlus`): `PaidEntitlement` bez objednávky
  (`compensation:plus:{uuid}`) pre zvolený aktívny plán a obdobie; `UsageProvisioner` k nemu otvorí mesačné granty
  plánu. Nevzniká objednávka, doklad ani stav `paid`; audit `billing.plus.granted`, predčasné ukončenie
  `billing.plus.revoked` (zaplatené obdobie sa takto ukončiť nedá – to je refundácia).
- **Blokovanie zneužitia** (`HouseholdModeration`, stĺpce `households.blocked_at/blocked_reason`): zastaví nové AI
  úlohy (`AiAvailability`) a nákupy (`CheckoutService`), nemaže recepty ani nároky, ručná editácia funguje; obe akcie
  s dôvodom, audit `household.blocked` / `household.unblocked`.
- Predplatné: **synchronizácia zo Stripe** (`StripeGateway::syncSubscription` → Cashier `syncStripeStatus` +
  `cancel_at_period_end`), zrušenie / obnovenie obnovovania cez bránu s povinným dôvodom; audit
  `billing.subscription.synced|renewal_canceled|renewal_resumed`.

### Predplatné, objednávky, refundácie

- `/admin/subscriptions`: Cashier riadky s domácnosťou, stavom, zaplatené do, filtrom podľa stavu a „bez obnovy“;
  akcie sync / zrušiť / obnoviť ako v detaile.
- `/admin/orders` + detail: snímka produktu a verzie, Stripe ID s odkazmi, zaplatené obdobia, udelené použitia
  s nevyužitými jednotkami. **Refund workflow** z UI volá `RefundService::request()` – druh (odstúpenie / reklamácia /
  dobrovoľná), suma v EUR, počet odoberaných nevyužitých textov/obrázkov (max = nevyužité jednotky tejto objednávky),
  voliteľne odobratie Plus obdobia, idempotentný kľúč, dôvod. Zlyhanie v Stripe zostáva `failed` bez odobratia.
- **Posúdenie refundácie zo Stripe dashboardu** (`RefundService::review()`): prípad `needs_review` uzavrie administrátor
  rozhodnutím, koľko jednotiek (aj 0) a či Plus obdobie sa odoberie → stav `reviewed`, audit `billing.refund.reviewed`.
  Nový stav `RefundStatus::Reviewed` sa počíta ako refundovaný pre stav objednávky aj report.
- `/admin/refunds`: všetky prípady s filtrom podľa stavu a druhu.

### Použitia, katalóg, Stripe udalosti

- `/admin/usage`: súčty platných grantov, granty s filtrami (domácnosť / zdroj / druh), rozbalený append-only ledger
  grantu (čas, dôvod, pohyb, rezervácia, kto, kľúč), otvorené rezervácie >24 h (prepínač na všetky). Jediný zápis je
  **prepočet počítadiel z ledgeru** (`UsageLedger::reconcile`, audit len pri oprave) – žiadna editácia zostatku.
- `/admin/catalog` (`CatalogManager`): verzie plánov a balíkov so stavom návrh / aktívna / stiahnutá. „Nová verzia“
  skopíruje poslednú verziu kódu do návrhu (názov, cena, Stripe price ID, limity); **aktivácia** návrhu v jednej
  transakcii stiahne predchádzajúcu aktívnu verziu; stiahnutie prestane predávať. Audit
  `catalog.{plan|addon}.{version_created|activated|retired}`. Stripe ceny sa v Stripe nevytvárajú – ID sa zadáva.
  Akceptačný test 14: kúpené snímky, `plan_version_id` objednávok a nárokov aj `planForStripePrice` starej ceny ostávajú.
- `/admin/stripe-events`: inbox s filtrom stavu, hľadaním, payloadom a **ručným opakovaním** zlyhanej / zaseknutej
  udalosti (`StripeEventProcessor::process`, idempotentné; audit `billing.stripe_event.retried`).

### Bezpečnosť

- Všetky moduly sú za rolou + MFA (etapa 1); stránky s finančnými akciami (detail domácnosti, predplatné, detail
  objednávky, katalóg, Stripe udalosti) navyše za `password.confirm`, ktorý Livewire drží aj pre následné akcie.
  Každá akcia navyše volá `authorize('platform-admin')`. Test 13: bežný vlastník má 403, finančné a kompenzačné akcie
  majú audit s aktérom a dôvodom.

### Testy

`tests/Feature/Admin/{AdminFinancePagesTest, HouseholdAdminTest, RefundAdminTest, CatalogAdminTest,
StripeEventsAdminTest}.php` (13 testov) s falošnou bránou (`FakeStripeGateway::syncSubscription`) a helperom
`actingAsPlatformAdmin()` v `tests/Pest.php`. Celá sada: 201 testov.

### Nasadenie

`php artisan migrate` (stĺpce blokovania), voliteľne `RECIPES_BILLING_USD_EUR_RATE`. Synchronizácia predplatného volá
Stripe API – na reálnom účte sa overí v etape 7.

## Otvorené vstupy pre launch (nezmenené zo zadania, kap. 18)

Prevádzkovateľ a fakturačné údaje, potvrdenie cien a limitov, výsledky nákladového merania (30 + 30 úloh na reálnom
kľúči), analytický poskytovateľ, hosting/e-mail/zálohy/monitoring, DPH/OSS režim, právne schválenie textov.
