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
| 5 | **Právne stránky, cookies, súkromie** | ✅ hotové (vetva `v2-etapa-5-pravne-sukromie`) | `/vop`, `/ochrana-osobnych-udajov`, `/cookies`, `/odstupenie-od-zmluvy`, `/kontakt`, verzie a akceptácie, identita prevádzkovateľa a blokovanie checkoutu, register služieb, cookie lišta podľa kap. 10, consent receipt, zhrnutie pred platbou a e-mail s VOP, online odstúpenie, export/výmaz účtu, žiadosti (testy 15–21) |
| 6 | **Plus funkcie** | ✅ hotové (vetva `v2-etapa-6-plus-funkcie`) | Návrh týždenného jedálnička existujúcim generátorom (bez AI), nákupný zoznam z plánu s explicitným zlučovaním, uložené skupiny a filtre výberu; gating podľa zaplateného obdobia, dáta zostávajú po skončení Plus |
| 7 | **Staging a launch** | ✅ kód hotový (vetva `v2-etapa-7-staging-launch`) · ⏳ vstupy prevádzkovateľa | Launch checklist (`app:launch-check`, `/admin/launch`) s overením cien a webhooku v Stripe, ručné potvrdenia s auditom, prepínač platieb `RECIPES_CHECKOUT_ENABLED`, test clock simulácie (`app:billing-test-clock`), meranie 30+30 AI úloh (`app:ai-measure`), jeden zoznam Stripe udalostí pre `cashier:webhook`, runbook `runbook-launch.md` |

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

## Etapa 5 – čo je hotové

### Právne dokumenty (`LegalDocumentVersion`, `/admin/legal`)

- Štyri typy (`terms` → `/vop`, `privacy` → `/ochrana-osobnych-udajov`, `cookies` → `/cookies`, `withdrawal` →
  `/odstupenie-od-zmluvy`), Markdown obsah, verzia, checksum, stav `draft → published → archived`, `effective_at`,
  kto a s akou poznámkou schválil. Publikovaná verzia je nemenná; zmena = nová verzia (kópia poslednej), publikovanie
  v jednej transakcii archivuje predchádzajúcu. Archív `/{slug}/archiv`, konkrétna verzia `/{slug}/{n}`.
- `LegalDocumentSeeder` nahrá **pracovné texty zo zadania (kap. 10) ako návrhy** s placeholdermi
  `[OBCHODNÉ MENO, SÍDLO, IČO, REGISTER]`, `[EMAIL]`, `[PRIVACY EMAIL]`, `[ARS]`, `[DOPRACOVAŤ PRED PUBLIKÁCIOU …]`.
  Publikovanie textu s placeholderom je odmietnuté; „Doplniť údaje prevádzkovateľa“ nahradí známe placeholdery
  z identity, zvyšné musí administrátor dopísať. Verejná stránka bez publikovanej verzie hlási „Dokument sa
  pripravuje“; návrh vidí len administrátor s výrazným banerom. Audit `legal.document.{draft_created|draft_updated|published|archived}`.
- **Identita prevádzkovateľa** (`OperatorIdentity`, `app_settings` kľúče `operator.*`): obchodné meno, právna forma,
  sídlo, IČO, register, DIČ/IČ DPH, e-maily podpory / reklamácií / súkromia, telefón, ARS, cieľové krajiny, daňový
  režim. Nič sa nevymýšľa – prázdne polia sú blokerom. Zobrazuje sa v pätičke, na `/kontakt`, v zhrnutí objednávky
  a v e-mailoch.
- **Blokovanie checkoutu** (`CheckoutReadiness`, test 20): kým chýba povinná identita alebo publikované VOP,
  informácie o súkromí a odstúpenie, `CheckoutService` odmietne nákup, cenník a nastavenia ukazujú „Platby ešte nie
  sú zapnuté“ (tlačidlá vypnuté). Recepty a bezplatné funkcie to neobmedzuje. Stav a blokery sú na `/admin/legal`.

### Registrácia, objednávka a akceptácie (`LegalAcceptance`, testy 18, 20)

- Registrácia vyžaduje **samostatné prijatie publikovaných VOP** (checkbox s verziou), nič iné – žiadny „súhlas
  s GDPR“ ani marketing; informácie o súkromí sú odkazom pri formulári. Kým VOP nie sú publikované, checkbox sa
  nezobrazuje a akceptácia sa nezapisuje (blokovaný je len platený checkout).
- **Zhrnutie pred platbou** `checkout/review?plan=…|addon=…`: identita predávajúceho, obsah balíka, konečná suma,
  periodicita a automatická obnova, spôsob zrušenia, odkaz na odstúpenie, verzia VOP s odkazom; tlačidlo
  „Objednať s povinnosťou platby“. Formulár posiela `terms` + `terms_version` (musí sedieť s aktuálnou verziou, inak
  chyba) a **samostatnú** voľbu „žiadam o začatie plnenia pred uplynutím lehoty na odstúpenie“, ktorá sa ukladá ako
  `acknowledgements.early_performance_requested` – oddelene od marketingu a cookies.
- Objednávka má `terms_version_id` (snímka); neskoršia verzia VOP ju nemení. Po zaplatení (`OrderConfirmations`,
  volané z webhook procesora po prechode do `paid`) ide **e-mail s potvrdením objednávky a priloženým textom VOP**
  (`vop-v{n}.md`) – trvalé médium, nie iba odkaz; odosiela sa raz (`orders.confirmation_sent_at`), zlyhanie odoslania
  neblokuje spracovanie webhooku a pri ďalšom pokuse sa pošle znova.

### Cookies a súhlas (`ConsentService`, `ConsentReceipt`, `ConsentPolicy`, testy 15–17)

- **Register služieb** `/admin/services`: kľúč, názov, poskytovateľ, kategória (`necessary`/`analytics`/`marketing`),
  účel, uchovanie, miesto/prenos, inventár cookies a úložísk (názov, druh, doména, trvanie, účel), loader
  (`{"type":"ga4","measurement_id":…}` alebo `{"type":"script","src":…}`), zapnutá/vypnutá, `consent_version`.
  `ConsentServiceSeeder` nahrá overený inventár (session cookie, `XSRF-TOKEN`, `remember_web_*`, `mr_consent`,
  `flux.appearance`, Stripe Checkout bez skriptu) a **vypnutú šablónu GA4** – poskytovateľ analytiky nie je vybraný,
  integrácia je vypnutá; zapnutie je rozhodnutie prevádzkovateľa v administrácii (audit `consent.service.*`).
- Stránka `/cookies` generuje tabuľku z registra (iba zapnuté služby). Lišta podľa kap. 10 (text, tri rovnocenné
  tlačidlá **Prijať voliteľné / Odmietnuť voliteľné / Nastavenia**, žiadny vopred zapnutý prepínač) sa zobrazí **len
  keď existuje zapnutá voliteľná služba**; bez nej sa prázdny súhlas nežiada a odkaz „Nastavenia cookies“ v pätičke
  vedie na `/cookies`. Marketing sa ponúka až keď má konkrétnu službu.
- **Gating na serveri**: stránka vloží loader voliteľnej služby (`#mr-consent-config`) iba pre kategórie, ktoré
  návštevník povolil pod **aktuálnou verziou účelov** (hash zapnutých služieb + `consent_version` + verzia dokumentu
  cookies). Bez rozhodnutia, po odmietnutí a po odvolaní nie je v HTML žiadny analytický kód ani požiadavka;
  zatvorenie lišty nie je súhlas. Zmena účelu / nová služba = nová verzia → stará voľba neplatí a lišta sa pýta znova.
  `resources/js/consent.js` načíta služby až po súhlase, pri `wire:navigate` neinicializuje dvakrát, neodosiela
  udalosti nazbierané pred súhlasom, pri odvolaní zmaže spravované cookies/localStorage a vypne GA (`ga-disable-*`).
  GA4 beží s `send_page_view:false`; `page_view` posiela cestu bez ID a query (`/recipes/:id`).
- V administrácii, na právnych stránkach, v checkoute, nastaveniach predplatného/súkromia a auth formulároch je
  voliteľná analytika **potlačená** (`ConsentPolicy::SUPPRESSED_ROUTES`) aj so súhlasom; admin layout lištu vôbec
  nevkladá. Bez session replay a pixelov.
- **Consent receipt**: pseudonymné `visitor_id` (UUID v cookie), verzia účelov, verzia dokumentu cookies, akcia
  (`accept_all|reject_all|custom|withdraw`), kategórie, čas; bez IP. Funguje aj bez prihlásenia; prihlásený má
  `user_id`. Cookie `mr_consent` má životnosť `RECIPES_CONSENT_LIFETIME_DAYS` (180 dní – produktové nastavenie).
  Endpoint `POST /consent` (JSON alebo obyčajný formulár, throttle).
- **Allowlist udalostí** `AnalyticsEvents` (`recipe_created`, `selection_started`, `meal_planned`,
  `checkout_started`, `subscription_started`, `addon_purchased`) s enumerovanými hodnotami – žiadne názvy receptov,
  e-maily, mená ani sumy. Na strane klienta `window.mrAnalytics.track(name, props)` s rovnakým zoznamom; stránka
  úspešnej platby vyšle `subscription_started`/`addon_purchased` raz na objednávku. Ostatné udalosti sú pripravené,
  ale v receptovom module zatiaľ nevolané (modul sa neprerábal).

### Odstúpenie od zmluvy (`WithdrawalRequest`, `WithdrawalService`, test 19)

- Verejný formulár `/odstupenie-od-zmluvy/formular` (aj bez prihlásenia): e-mail, číslo objednávky (prihlásený
  vlastník vyberá zo svojich zaplatených objednávok), správa, potvrdenie úmyslu. Ihneď vznikne žiadosť s číslom
  `ODS-RRRR-XXXXXX` a odíde **e-mail s potvrdením prijatia** (trvalé médium). Objednávka sa páruje podľa čísla +
  domácnosti prihláseného alebo e-mailu platcu; nespárované žiadosti vidí administrátor a objednávku priradí ručne.
  Nič sa zákazníkovi neprezrádza. Odstúpenie je oddelené od „Zrušiť obnovovanie“ (to zostáva v nastaveniach).
- Administrácia `/admin/privacy`: „Vrátiť celú sumu“ volá `RefundService::request()` s druhom `withdrawal`, celou
  zostávajúcou sumou, odobratím **všetkých nevyužitých** jednotiek a Plus obdobia, idempotentným kľúčom
  `withdrawal-{id}`; zlyhanie v Stripe zostane viditeľné a žiadosť otvorená. Zamietnutie vyžaduje odôvodnenie.
  Obe rozhodnutia posielajú e-mail a majú audit `legal.withdrawal.{refunded|rejected|order_attached}`.

### Súkromie: export, výmaz, žiadosti (`PrivacyRequest`, `AccountErasure`, test 21)

- Nastavenia → **Súkromie** (`/settings/privacy`): dokumenty a vlastné akceptácie, stav voľby cookies (zmena /
  odvolanie), export domácnosti (ZIP, existujúci) a **export účtu** (JSON: profil, členstvá, akceptácie, consent
  receipts, žiadosti), vlastné žiadosti s lehotou, **vymazanie účtu** s dopadom pred potvrdením (Plus do kedy,
  nevyužité dokúpené jednotky, ďalší členovia, účtovné doklady) a voľbou pre vlastnenú domácnosť s členmi:
  zrušiť alebo **previesť na člena**. Potvrdenie heslom.
- `AccountErasure::erase()`: obsah domácnosti (recepty s obrázkami, profily, plány, história, AI úlohy, pozvánky,
  členstvá) sa zmaže; domácnosť s objednávkami/refundáciami sa **anonymizuje** (`erased_at`, názov „Zrušená
  domácnosť #id“) a účet sa anonymizuje (e-mail `erased-{id}@erased.invalid`, meno, náhodné heslo, MFA, passkeys,
  relácie) – FK `households.owner_user_id` kaskáduje, preto sa riadok účtu pri účtovných záznamoch nezmaže;
  bez finančných záznamov sa účet aj domácnosť zmažú úplne. Aktívne obnovovanie predplatného sa vypne cez bránu.
  Člen bez vlastníctva: členstvo skončí, profil stravníka sa anonymizuje, recepty ostávajú vlastníkovi. Vznikne
  `PrivacyRequest` (`erasure`, `completed`, evidencia čo sa zmazalo), audit `privacy.account.erased`, e-mail
  o vybavení. Pôvodný dialóg v profile smeruje na stránku súkromia; `delete-user-modal` používa tú istú službu.
- **Zálohy** (test 21): `php artisan app:privacy-reapply-erasures` prejde vybavené žiadosti o výmaz a domácnosti,
  ktorým sa po obnove zálohy vrátil obsah alebo meno, znova vyčistí (audit `privacy.erasure.reapplied`). Do runbooku
  obnovy patrí ako povinný krok po každom restore.
- `/admin/privacy` eviduje žiadosti (export / výmaz / oprava / iné) s lehotou 30 dní
  (`recipes.privacy.request_deadline_days`), stavmi `received → in_progress → completed | rejected`, dôkazom
  vybavenia a auditom `privacy.request.updated`. Iné než samoobslužné žiadosti (e-mailom) zakladá administrátor
  cez `PrivacyRequests::open()` – UI na ručné založenie zatiaľ nie je.

### Vedomé rozhodnutia a hranice

- Consent UI je malý Alpine komponent + vanilla JS bez knižnice (zadanie kap. 13: podstatné je blokovanie
  a evidencia). Sieťové blokovanie garantuje server tým, že loader nevloží; klient navyše nič nespúšťa bez
  konfigurácie. Pokrytie e2e (skutočná neprítomnosť požiadaviek v prehliadači) sa overí ručne v etape 7.
- Právne texty sú **pracovné návrhy v stave draft**; aplikácia ich neoznačuje za schválené. Publikuje ich
  administrátor s poznámkou o schválení až po právnej kontrole. Retencia akceptácií, consent receiptov a žiadostí je
  otvorená politika (kap. 11) – zatiaľ sa neuchovávajú „nekonečne“ iba v zmysle, že nemajú automatický výmaz;
  doplniť po schválení lehôt.
- Stripe zákazník sa pri výmaze účtu v Stripe neruší (účtovné záznamy, refundácie); fakturačný e-mail na
  `billing_accounts` ostáva ako súčasť dokladov. Prenos do etapy 7: overiť s účtovníkom.
- Objednávka posiela VOP e-mailom; registrácia (bezplatná zmluva) samostatný e-mail s VOP neposiela – link a verzia
  sú v Nastavenia → Súkromie.

### Testy

`tests/Feature/Legal/{LegalPagesTest, WithdrawalTest}`, `tests/Feature/Auth/RegistrationTermsTest`,
`tests/Feature/Billing/CheckoutLegalTest`, `tests/Feature/Consent/ConsentTest`, `tests/Unit/Consent/AnalyticsEventsTest`,
`tests/Feature/Privacy/AccountErasureTest`, `tests/Feature/Admin/{LegalAdminTest, ServicesAdminTest, PrivacyAdminTest}`
pokrývajú akceptačné testy 15, 16, 17, 18, 19, 20, 21 (a 13 pre nové moduly). `Tests\Support\LegalScenario::ready()`
identifikuje prevádzkovateľa a publikuje seedované texty; `BillingScenario::start()` ju volá, lebo bez toho je
checkout zablokovaný (test 20). Celá sada: 221 testov.

### Nasadenie a lokálny vývoj

`php artisan migrate`, `php artisan db:seed --class=LegalDocumentSeeder`, `php artisan db:seed --class=ConsentServiceSeeder`
(obe sú aj v `db:seed`), `npm run build` (nový `resources/js/consent.js`). Potom v `/admin/legal` vyplniť
prevádzkovateľa a publikovať dokumenty – **bez toho je platený checkout aj lokálne vypnutý**. E-maily sú
`ShouldQueue`: pri `QUEUE_CONNECTION=database` ich odošle až `php artisan queue:work` (lokálne do Mailpitu).
Voliteľná analytika: až po výbere poskytovateľa zapnúť službu v `/admin/services` s loaderom.

## Etapa 6 – čo je hotové

Všetky tri funkcie, ktoré cenník a `CatalogSeeder` (`features` plánu) sľubujú, sú dokončené; Plus sa tak nepredáva
s nehotovými funkciami (kap. 2, 16). Nič z nich nepoužíva platenú AI – týždenný návrh beží na existujúcom
`SelectionEngine` (kap. 7 zadania v1), nákupný zoznam na `ServingScaler`/`IngredientAmountParser`.

### Gating (`PlusAccess`, `PlusFeature`)

- Prístup = `PlanStatus::isPlus()` (zaplatené obdobie alebo 3-dňová tolerancia po neúspešnej obnove; kompenzačný
  Plus z administrácie funguje rovnako). Úspešná URL checkoutu nič neodomyká.
- Free domácnosť vidí stránku s vysvetlením (`x-plus-gate`): čo funkcia robí, odkaz na cenník, pre vlastníka odkaz na
  predplatné, pre člena informácia, že objednáva vlastník. Žiadny nátlak; recepty, ručný plán a história fungujú ďalej.
  Akcie na serveri vracajú 403. Na stránke Plán majú odkazy pre Free badge „Plus“.
- **Po skončení Plus nič nezmizne** (kap. 2: existujúce dáta nesmú byť neprístupné): uložené šablóny zostávajú
  viditeľné a použiteľné (aplikovanie iba vyplní formulár), existujúci nákupný zoznam sa dá čítať a odškrtávať.
  Plus vyžaduje iba samotná Plus akcia – nový návrh týždňa, nové zostavenie zoznamu, uloženie/prepis šablóny.
- Blokovanie domácnosti (etapa 4) Plus funkcie neobmedzuje – blokuje AI a nákupy, nie ručné plánovanie.

### Návrh týždenného jedálnička (`/plan/navrh`, `WeeklyMenuPlanner`)

- Vstupy: týždeň (tento/budúci, prednastavený z Plánu cez `?week=`), stravníci (predvolená skupina domácnosti alebo
  šablóna), typy jedál (raňajky/obed/večera alebo „jedno jedlo denne, čokoľvek“), dni, porcie (1 osoba = 1 porcia),
  rovnaké voliteľné filtre ako pri jednom jedle.
- Pre každý slot (deň × typ) beží engine s referenčným dňom daného dňa (história, plánované jedlá ±3 dni, výluky,
  chute skupiny) a vážený náhodný výber. **Recept je v týždni najviac raz** – ani dvakrát v návrhu, ani ak už je
  v tom týždni naplánovaný ručne. Slot, ktorý už má plán, zostáva označený „Už naplánované“ a nemení sa.
  Keď nezostane kandidát, slot je prázdny s dôvodom (filtre, výluky, všetko použité) – nikdy sa neopakuje potichu.
- Návrh žije iba v UI: každý slot možno vymeniť (iba ten jeden slot, ostatné zostávajú) alebo vynechať; až
  „Uložiť do plánu“ vytvorí bežné `meal_plans` cez `MealPlanningService::create` v jednej transakcii. Recepty, ktoré
  sa medzičasom archivovali alebo dostali výluku, sa vynechajú a vypíšu. Uvarenie sa potvrdzuje ako doteraz.
- Po uložení sa (pri súhlase s analytikou) vyšle povolená udalosť `meal_planned` s `mode=week`.

### Nákupný zoznam z plánu (`/plan/nakup`, `ShoppingListBuilder`, tabuľky `shopping_lists`, `shopping_list_items`)

- Zoznam na týždeň zo všetkých **naplánovaných** (nie zrušených/uvarených) jedál s dňom v týždni alebo „tento týždeň
  – bez dňa“. Množstvá sa prepočítajú podľa porcií plánu, keď recept pozná základné porcie; inak sa použijú
  pôvodné množstvá a zdroj je označený ako neprepočítaný.
- **Zlučovanie je explicitné** (zadanie v1, kap. 16): sčítajú sa iba riadky s rovnakým normalizovaným názvom
  a jednotkou (`cibuľa|ks`); „g“ a „kg“ zostávajú dve položky, „podľa chuti“ sa uvádza samostatne s názvom
  receptu, jednotky sa nekonvertujú. Pri každej položke vidno, z ktorých receptov je.
- Odškrtávanie (každý člen), vlastné položky (rovnaký názov + jednotka sa pripočíta, nezdvojí), „Kopírovať ako text“.
  Opätovné zostavenie nahradí generované riadky, **zachová odškrtnutie** položiek, ktoré v pláne ostali, a vlastné
  položky; riadky, ktoré z plánu vypadli, zmizne. Stránka upozorní, keď sa plán od zostavenia zmenil.
- Jeden zoznam na domácnosť a týždeň (unikátny index); položky z receptu sa mažú iba zmenou plánu, nie ručne.

### Uložené skupiny a filtre (`SelectionPresets`, tabuľka `selection_presets`)

- Na stránke „Vyber mi jedlo“ blok **Šablóny**: uloženie aktuálnych stravníkov, typu jedla a filtrov pod názvom
  („Rodina“, „Návšteva“), aplikovanie jedným klikom (aj v týždennom návrhu), odstránenie s potvrdením. Rovnaký názov
  šablónu prepíše (unikátny index domácnosť + názov). Archivovaní stravníci sa pri aplikovaní vynechajú.
- Viditeľný limit 30 šablón na domácnosť (`SelectionPresets::LIMIT`) – hlási sa až pri dosiahnutí, nie skrytý.
- Ukladať a mazať môže vlastník/spolupracovník (`edit` domácnosti); člen s rolou iba na čítanie šablóny používa.

### Export, výmaz, zásady

- `ExportService` schéma **2**: pribudli `selection_presets` a `shopping_lists` (staré kľúče nezmenené).
- `AccountErasure::purgeHouseholdContent` maže aj šablóny a nákupné zoznamy (FK kaskáda pokrýva úplný výmaz,
  anonymizovaná domácnosť s dokladmi potrebuje explicitné mazanie).
- Politiky `SelectionPresetPolicy`, `ShoppingListPolicy` (člen číta/odškrtáva, editor spravuje); všetky dotazy sú
  ohraničené aktuálnou domácnosťou.

### Testy

`tests/Feature/Plus/{WeeklyMenuTest, ShoppingListTest, SelectionPresetTest}.php` (10 testov) s deterministickým
`WeightedPicker` a helperom `Tests\Support\PlusScenario::activate()/expire()` (zaplatené obdobie bez objednávky ako pri
kompenzácii). Pokrývajú: žiadne opakovanie v týždni a rešpekt k ručným plánom, typy jedál/výluky/filtre, výmenu jedného
slotu, vynechanie archivovaného receptu pri potvrdení, gating (403 + stránka) a tok z Livewire stránky; zlučovanie
podľa názvu a jednotky, prepočet porcií, neprepočítaný recept, zachovanie odškrtnutia a vlastných položiek pri
regenerácii, čitateľnosť zoznamu po skončení Plus; uloženie/prepis/aplikovanie/mazanie šablón, Free a člen bez práv,
limit. `PagesRenderTest` renderuje nové stránky. Celá sada: 231 testov.

### Nasadenie

`php artisan migrate` (tri nové tabuľky). Bez nových závislostí, bez zmeny JS bundlu (kopírovanie do schránky je
inline Alpine). Cenník a `CatalogSeeder` už funkcie uvádzali; teraz sú skutočne dostupné.

## Etapa 7 – čo je hotové

Etapa nepridáva tabuľky (stav drží `app_settings`) ani závislosti. Postup krok za krokom je v `runbook-launch.md`.

### Tri brány pred prvou platbou

- **Prepínač platieb** `RECIPES_CHECKOUT_ENABLED` (`recipes.billing.checkout_enabled`, predvolene **false**): `CheckoutReadiness::blockers()`
  ho hlási ako prvý blocker, `legalBlockers()` sú právne položky samostatne. Zapnutie je samostatné nasadenie (zadanie kap. 16 bod 7);
  vypnutie okamžite zastaví nové nákupy, nároky, granty a portál fungujú ďalej. V testoch je zapnutý (`phpunit.xml`), v `.env.example` `true`.
- **Právna pripravenosť** – nezmenená z etapy 5.
- **Launch checklist** (`LaunchReadiness`): prostredie (APP_ENV/DEBUG/URL, fronta, pošta, posledný beh `app:billing-reconcile`, zlyhané
  úlohy), Stripe (kľúče a ich režim – živé kľúče mimo produkcie sú vždy chyba, webhook secret, endpoint a jeho udalosti), katalóg (aktívne
  verzie s price ID, zhoda s `STRIPE_PRICE_*`, s `--stripe` každá cena proti Stripe: suma, mena, interval, aktívna, live/test), právne
  (blockery, cookies), administrácia (admin s 2FA, `ADMIN_REQUIRE_TWO_FACTOR`, zabudnuté `ADMIN_INITIAL_PASSWORD`), AI (kľúč, kill switch,
  modely so sadzbou v cenníku, meranie 30+30, rozpočet), ručné potvrdenia a na záver stav prepínača (zapnuté s chybami = blokujúca chyba).
  Prísne prevádzkové kontroly sú mimo produkcie iba upozornením, aby bol príkaz použiteľný aj na stagingu.
- `php artisan app:launch-check [--stripe] [--json]` – exit 1, kým niečo blokuje (deploy pipeline). `/admin/launch` (password.confirm) ukazuje
  to isté, tlačidlo „Overiť v Stripe“ volá Stripe API cez `StripeInspector` (`CashierStripeInspector`, v testoch `FakeStripeInspector`).

### Ručné potvrdenia (`LaunchSignoffs`)

Ceny a limity, Stripe účet, doklady s účtovníkom, DPH/OSS režim, právne schválenie, test clock, meranie AI, prevádzka (hosting, e-mail,
zálohy, monitoring, príjemcovia dát). Potvrdenie = kto, kedy, povinná poznámka; uložené v `app_settings` (`launch.signoff.*`), audit
`launch.signoff.confirmed` / `launch.signoff.withdrawn`. Odvolanie vráti položku medzi blokujúce. Potvrdenie je vyhlásenie prevádzkovateľa,
nie právne posúdenie.

### Test clock (`TestClockSimulation`, `app:billing-test-clock`)

`start <domácnosť> --plan --at` vytvorí v sandboxe test clock, zákazníka na ňom (Cashier, testovacia karta `pm_card_visa`) a predplatné
z katalógovej price ID (`error_if_incomplete`); domácnosť musí byť bez Stripe zákazníka a predplatného. Webhooky prídu bežnou cestou
(`invoice.paid` → `PaidEntitlement` aj bez objednávky). `advance clock --at` posunie čas, počká na `ready` a otvorí mesačný grant pre
obdobie so zmrazeným časom (to, čo by urobil scheduler – aplikácia sama ide v reálnom čase). `status --sync`, `list`, `delete`.
Register clockov je v `app_settings` (`billing.test_clocks`), akcie sú v audite. So živými kľúčmi príkaz odmietne čokoľvek.
Rozhranie `StripeTestClocks` (`CashierStripeTestClocks`, v testoch `FakeStripeTestClocks`); scenáre A–F sú v runbooku.

### Meranie AI (`AiMeasurement`, `app:ai-measure`)

`app:ai-measure <domácnosť> [--text=30] [--images=30] [--scope] --yes` spustí úlohy synchrónne cez bežné `ai_jobs` (`AiTextService::create`
/ `AiImageService::create` s `rateLimits: false` – denné limity a súbeh sa neuplatnia, kľúč, kill switch, blokovanie domácnosti a ledger áno).
Použitia kryje samostatný kompenzačný grant `compensation:measure-<beh>-<druh>`; úlohy majú `input.measurement_run`. Report: doručené,
Ø cena, rozpätie, trvanie, tokeny a projekcia (mesiac Plus 30+5 vs. 2,49 €/2,00 €, balíky vs. 3,99 € a 1,99 €) v USD, s kurzom aj v EUR.
Súhrn ide do `app_settings` (`launch.ai_measurement`), audit `ai.measurement.completed`; `--report=<beh>` ho vypíše znova. `request()`
oboch služieb ostal rovnaký (create + dispatch).

### Webhook udalosti a prevádzka

- `StripeWebhookEvents::required()` = Cashier + `StripeEventProcessor`; `config('cashier.webhook.events')` ho používa, takže
  `php artisan cashier:webhook` zaregistruje presný zoznam (doteraz iba Cashier default). Checklist porovná endpoint so zoznamom.
- `app:billing-reconcile` zapisuje čas posledného behu (`ops.billing_reconcile_last_run_at`) – checklist z toho číta stav scheduleru.

### Testy

`tests/Feature/Launch/LaunchChecklistTest.php` (prepínač blokuje checkout aj s právnou pripravenosťou; blockery na prázdnej inštalácii → OK po
infraštruktúre, meraní a potvrdeniach; overenie cien/endpointu proti falošnému Stripe vrátane nesprávnej sumy, intervalu, live/test a chýbajúcich
udalostí; admin stránka s potvrdením/odvolaním a auditom), `tests/Feature/Billing/TestClockSimulationTest.php` (ročný plán s anchorom 31.,
grant po posune bez duplicít, odmietnutie druhej simulácie a živých kľúčov, delete), `tests/Feature/Ai/AiMeasurementTest.php` (meranie proti
vlastnému grantu bez dotyku skúšobných použití, ignorovanie denných limitov, uložený súhrn, potvrdenie v príkaze).

### Nasadenie

Bez migrácie. Do `.env` doplniť `RECIPES_CHECKOUT_ENABLED` (lokálne `true`, produkcia `false` až do launchu). Ďalej podľa `runbook-launch.md`.

## Otvorené vstupy pre launch (nezmenené zo zadania, kap. 18)

Prevádzkovateľ a fakturačné údaje, potvrdenie cien a limitov, výsledky nákladového merania (30 + 30 úloh na reálnom
kľúči), analytický poskytovateľ, hosting/e-mail/zálohy/monitoring, DPH/OSS režim, právne schválenie textov. Každý vstup má
riadok v `/admin/launch`; kým chýba, `app:launch-check` končí chybou a platby ostávajú vypnuté.
