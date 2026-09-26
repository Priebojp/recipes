# Runbook – staging a launch (v2, etapa 7)

Postup, ako z aplikácie s hotovými etapami 1–6 urobiť živú službu s platbami. Zadanie: `moje-recepty-v2-predplatne-admin-pravne.md`
(kap. 7, 16 bod 7, 18), dodatok `moje-recepty-v2-1-dodatok-ai-vyziva-stripe.md` (kap. 11). Stav etáp: `v2-etapy-a-stav.md`.

Tri nezávislé brány, ktoré musia byť otvorené, aby zákazník zaplatil:

1. **Launch checklist** – `php artisan app:launch-check [--stripe]` / `/admin/launch`: automatické kontroly + ručné potvrdenia. Exit 1, kým niečo blokuje.
2. **Právna pripravenosť** (etapa 5, akceptačný test 20) – identita prevádzkovateľa a publikované VOP / súkromie / odstúpenie bez placeholderov.
3. **Prepínač platieb** – `RECIPES_CHECKOUT_ENABLED=true`. Predvolene vypnutý; zapína sa samostatným nasadením až po 1 a 2.

Recepty, bezplatné funkcie a už zaplatené nároky nezávisia od žiadnej z brán.

## 1. Čo sa odovzdáva

| Položka | Kde |
|---|---|
| Migrácie | `database/migrations` (etapa 7 nepridáva tabuľky; používa `app_settings`) |
| Konfigurácia bez secrets | `.env.example`, `config/recipes.php`, `config/cashier.php`, `config/admin.php` |
| Zoznam Stripe udalostí | `App\Services\Billing\StripeWebhookEvents::required()` – registruje ich `php artisan cashier:webhook` |
| Postup pre admina | README „Administrácia“, `php artisan app:grant-platform-admin` |
| Právne texty | `/admin/legal` (drafty zo seedera, publikované verzie s poznámkou o schválení) |
| Testy | `php artisan test --compact` (akceptačné testy 1–23 podľa `v2-etapy-a-stav.md`) |
| Otvorené rozhodnutia | kap. 8 nižšie a ručné potvrdenia v `/admin/launch` |

## 2. Prostredie a secrets (bez hodnôt)

| Premenná | Poznámka |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…` | checklist kontroluje všetky tri |
| `APP_KEY` | vygenerovať raz, zálohovať; zmena zneplatní šifrované dáta a relácie |
| `DB_*` | produkčná DB; denná záloha s otestovanou obnovou |
| `QUEUE_CONNECTION=database` (alebo redis) | Stripe udalosti, e-maily, exporty – **beží worker** (`php artisan queue:work --tries=3`, supervisor/systemd) |
| `MAIL_*` | produkčný SMTP, odosielateľ v doméne; potvrdenia objednávok a odstúpení |
| `MEDIA_DISK`, `FILESYSTEM_DISK` | úložisko obrázkov; súčasť záloh |
| `OPENAI_API_KEY`, `RECIPES_AI_TEXT_MODEL`, `RECIPES_AI_IMAGE_MODEL` | reálny kľúč; modely `gpt-6-luna`, `gpt-image-2` (musia sedieť s cenníkom `ai_cost_rates`) |
| `RECIPES_AI_MONTHLY_BUDGET_USD` | alarm na prehľade (voliteľné, odporúčané) |
| `STRIPE_KEY`, `STRIPE_SECRET` | **živé** kľúče iba v produkcii; mimo produkcie checklist živé kľúče odmietne |
| `STRIPE_WEBHOOK_SECRET` | z endpointu vytvoreného `cashier:webhook` v živom režime |
| `STRIPE_PRICE_PLUS_MONTHLY`, `STRIPE_PRICE_PLUS_YEARLY`, `STRIPE_PRICE_IMAGES_20_STANDARD`, `STRIPE_PRICE_TEXT_100` | živé price ID; katalóg ich preberie `CatalogSeeder`om iba do prázdneho poľa – inak nová verzia katalógu |
| `ADMIN_EMAIL`, `ADMIN_REQUIRE_TWO_FACTOR=true` | `ADMIN_INITIAL_PASSWORD` iba na prvé prihlásenie, potom odstrániť |
| `RECIPES_BILLING_USD_EUR_RATE` | voliteľný kurz pre odhad príspevku a prepočet merania |
| `RECIPES_CHECKOUT_ENABLED` | **false** až do kroku 7 |

Cron: `* * * * * php artisan schedule:run` (denne 3:15 `app:billing-reconcile`: mesačné granty, opustené objednávky, zlyhané udalosti; jeho beh vidí checklist).

## 3. Staging v Stripe sandboxe

1. `.env` so sandbox kľúčmi (`sk_test_…`), `php artisan migrate --force`, `php artisan db:seed --force` (admin, cenník AI, katalóg, právne drafty, register služieb).
2. Admin: `php artisan app:grant-platform-admin <email>`; prihlásiť sa, zapnúť 2FA (bez toho `/admin` nepustí).
3. V sandboxe vytvoriť produkty a ceny (2,49 €/mes., 24 €/rok, 3,99 €, 1,99 €), ID do `.env`, `php artisan db:seed --class=CatalogSeeder --force`.
4. Webhook: lokálne `stripe listen --forward-to <APP_URL>/stripe/webhook` (secret z CLI do `STRIPE_WEBHOOK_SECRET`); na staging serveri `php artisan cashier:webhook` – zaregistruje presný zoznam udalostí z `StripeWebhookEvents`.
5. `/admin/legal`: vyplniť identitu prevádzkovateľa, doplniť a publikovať VOP, súkromie, odstúpenie, cookies (aj na stagingu, inak je checkout blokovaný).
6. `RECIPES_CHECKOUT_ENABLED=true` (na stagingu áno), `php artisan app:launch-check --stripe` – ceny a webhook proti sandboxu musia sedieť.
7. Bežný nákup cez aplikáciu testovacou kartou `4242 4242 4242 4242`: mesačný plán, ročný plán, balík; `checkout/success` iba zobrazuje stav, aktivuje webhook. Skontrolovať `/admin/stripe-events`, `/admin/orders`, Nastavenia → Predplatné, doručený e-mail s VOP.

### Test clock (simulácie v čase)

Test clock zmrazí Stripe čas pre jedného zákazníka; aplikácia ide ďalej v reálnom čase. Preto `advance` po posune otvorí mesačný grant
pre obdobie, v ktorom je zmrazený čas (to isté, čo by urobil denný scheduler), a `status` ukáže lokálne nároky a granty.

```bash
php artisan app:billing-test-clock start <household> --plan=plus_yearly --at="2026-01-31 10:00"   # nová domácnosť bez Stripe zákazníka
php artisan app:billing-test-clock status clock_… --sync
php artisan app:billing-test-clock advance clock_… --at="+1 month"      # alebo absolútny dátum; Stripe povolí max. 2 intervaly naraz
php artisan app:billing-test-clock list
php artisan app:billing-test-clock delete clock_…                       # zmaže zákazníka aj predplatné v Stripe; lokálne riadky ostanú
```

Scenáre, ktoré treba prejsť a potvrdiť v `/admin/launch` („Simulácia test clock prebehla“):

| # | Scenár | Očakávanie (akceptačné testy 8, 9, 11) |
|---|---|---|
| A | mesačný plán, posun o 1 mesiac + 1 hodinu | `invoice.paid` → nové zaplatené obdobie, jeden grant textov/obrázkov, žiadny duplikát |
| B | ročný plán s anchorom 31. 1., posun na 1. 3. a 1. 4. | mesačné granty 31. 1. → 28. 2. → 31. 3., ročný nárok jeden; `status` ukáže granty bez duplicít |
| C | zrušenie obnovovania (Nastavenia → Predplatné) a posun za koniec obdobia | obdobie dobehne, `customer.subscription.deleted`, nový grant sa neotvorí, recepty a dokúpené balíky ostávajú |
| D | neúspešná obnova: v Stripe dashboarde zmeniť predvolenú kartu zákazníka na `4000 0000 0000 0341`, posun o mesiac | `invoice.payment_failed`, `past_due`, 3-dňová tolerancia Plus bez nových grantov, e-mail Stripe o zlyhaní |
| E | refundácia z dashboardu (celá aj čiastočná) | `RefundCase` `needs_review` v `/admin/refunds`, nároky sa neodoberú automaticky; posúdiť ručne |
| F | spor o platbu (`4000 0000 0000 0259`) | `charge.dispute.created` → pozastavené nevyužité jednotky, case `disputed` |

Limity Stripe: 3 zákazníci na clock, posun najviac o 2 intervaly, faktúra obnovy je hodinu v stave `draft` (posunúť o ďalšiu hodinu).
Test clock nefunguje so živými kľúčmi – príkaz to odmietne.

## 4. Meranie AI nákladov (30 + 30)

```bash
php artisan app:ai-measure <household> --yes                 # 30 textov + 30 obrázkov na reálnom kľúči
php artisan app:ai-measure <household> --text=30 --images=30 --scope=full --yes
php artisan app:ai-measure --report=<kľúč behu>              # zopakovať report
```

- Beží na domácnosti s receptami (odporúčaná vlastná testovacia), synchrónne, cez bežné `ai_jobs`; denné limity sa neuplatnia,
  použitia kryje samostatný kompenzačný grant `compensation:measure-…` (audit `usage.compensation.granted`, `ai.measurement.completed`).
- Report: doručené/zlyhané, Ø cena, rozpätie, Ø trvanie, Ø tokeny; projekcia plného mesiaca Plus (30 textov + 5 obrázkov) proti
  2,49 € / 2,00 €, balíka 20 obrázkov proti 3,99 € a 100 textov proti 1,99 €. Ceny poskytovateľa v USD, bez poplatkov Stripe a daní.
- Obrázky merania bežia s predvoleným profilom (`/admin/ai/settings`, dnes Standard = medium); grant merania je toho istého druhu.

### 4a. Porovnanie profilov obrázkov low/medium (v2.1 etapa 8, voliteľné pre launch v2)

```bash
php artisan app:ai-compare-images <testovacia domácnosť>          # vypíše odhad z cenníka (≈ 20 × 0,006 + 20 × 0,053 USD) a pýta si potvrdenie
php artisan app:ai-compare-images <testovacia domácnosť> --yes
php artisan app:ai-compare-images --report=<kľúč behu>
```

- V testovacej domácnosti vytvorí (alebo znovu použije) recepty 10 jedál zo zadania a pre každé vygeneruje 2 × Economy (low) + 2 × Standard
  (medium) s rovnakým promptom. Obrázky ostávajú v neaktívnej cover kolekcii, nič sa neaktivuje; použitia kryjú granty
  `compensation:compare-…` (jeden na druh `image_economy` / `image_standard`).
- Hodnotenie v `/admin/ai/comparisons/{beh}` (password.confirm): pri každom obrázku „prijateľný áno/nie“ + poznámka. Kritérium ≥ 18/20
  Economy prijateľných a žiadna systematická zámena jedla/prílohy. Rozhodnutie sa zapíše ako launch potvrdenie `image_profile`
  (neblokuje launch v2) a je vstupom etapy 13; ponuka, ceny ani existujúce nároky sa tým nemenia.
- Výsledok sa uloží do `app_settings` (`launch.ai_measurement`) a zobrazuje v `/admin/launch`; checklist chce ≥ 30 + 30 s aktuálnym modelom.
- Ak výsledok nesedí s cenníkom: zmena kvality obrázkov (`/admin/ai/settings`), nová verzia katalógu, alebo profily z dodatku v2.1.

## 5. Doklady (overiť s účtovníkom pred launchom)

Jeden autoritatívny proces: doklady vystavuje Stripe (faktúry za predplatné, faktúra pri jednorazovom balíku vďaka `invoice_creation`).
Pred launchom v Stripe dashboarde (Settings → Billing → Invoice template / Customer portal) overiť:

- Údaje dodávateľa na faktúre: presné obchodné meno, adresa, IČO, DIČ; text o DPH podľa rozhodnutého režimu (napr. „Nie sme platiteľom DPH“).
- **Číslovanie**: nastaviť číslovanie na úrovni účtu (sekvenčné), nie na úrovni zákazníka – účtovník rozhodne o prefixe.
- Dobropisy pri refundácii (Stripe vytvára credit note); refundácie a spory zostávajú dohľadateľné v `/admin/refunds`.
- Customer portal: história faktúr a platobná metóda dostupné zákazníkovi (Nastavenia → Predplatné → „Správa platby a dokladov“).
- Jazyk faktúry a e-mailov (`locale: sk`), odosielanie e-mailových potvrdení Stripe.
- Stripe Tax nezapínať bez daňových registrácií a rozhodnutia o režime (kap. 7 zadania).

Výsledok potvrdiť v `/admin/launch` („Doklady zo Stripe overené s účtovníkom“, „DPH / OSS režim rozhodnutý“).

## 6. Produkcia – príprava (platby ešte vypnuté)

1. Server: PHP 8.4, DB, fronta, cron, HTTPS; nasadiť kód, `php artisan migrate --force`, `php artisan db:seed --force`
   (`ADMIN_INITIAL_PASSWORD` iba na prvé prihlásenie, potom zmeniť a odstrániť). `npm run build`.
2. Admin + 2FA, `/admin/legal` – identita, publikovanie schválených verzií (poznámka o schválení = kto a kedy).
3. Stripe živý účet: aktivácia, výplaty, verejné meno „Moje recepty“, statement descriptor, support e-mail (dodatok v2.1 kap. 11);
   produkty a ceny v živom režime, ID do `.env`, `CatalogSeeder` (alebo nová verzia katalógu, ak už majú sandbox ID).
4. `php artisan cashier:webhook` so živými kľúčmi → secret do `STRIPE_WEBHOOK_SECRET`.
5. `php artisan app:launch-check --stripe` – všetko okrem ručných potvrdení a prepínača má byť OK.
6. `/admin/launch`: potvrdiť ručné položky (ceny, Stripe účet, doklady, DPH, právne, test clock, meranie, prevádzka) s poznámkou.
7. **Zapnutie platieb – samostatné nasadenie**: `RECIPES_CHECKOUT_ENABLED=true`, `php artisan config:cache`, `php artisan app:launch-check --stripe`
   musí skončiť bez blokujúcich položiek (inak hlási „platby sú ZAPNUTÉ, hoci checklist má chyby“).
8. Kontrolný nákup vlastným účtom: mesačný plán skutočnou kartou za 2,49 €, overiť `/admin/stripe-events`, nárok, granty, e-mail, faktúru;
   potom refundovať cez `/admin/orders` (workflow s idempotentným kľúčom) a skontrolovať dobropis.
9. Prvých 48 h sledovať: `/admin` (zlyhané udalosti, fronta), `failed_jobs`, log poskytovateľa AI, rozpočet.

Rollback: `RECIPES_CHECKOUT_ENABLED=false` + `config:cache` zastaví nové nákupy okamžite; existujúce nároky, granty a portál fungujú ďalej.
Zásah do AI: kill switch v `/admin/ai/settings`.

## 7. Stripe udalosti, ktoré endpoint musí posielať

`StripeWebhookEvents::required()` = Cashier (`customer.subscription.created|updated|deleted`, `customer.updated|deleted`,
`payment_method.automatically_updated`, `invoice.payment_action_required`, `invoice.payment_succeeded`) + aplikácia
(`checkout.session.completed|async_payment_succeeded|async_payment_failed|expired`, `invoice.paid`, `invoice.payment_failed`,
`charge.refunded`, `charge.dispute.created`). Checklist s `--stripe` porovná endpoint s týmto zoznamom.

## 8. Otvorené vstupy (blokujú iba zapnutie platieb)

Nezmenené zo zadania kap. 18: presná identita prevádzkovateľa a fakturačné údaje, potvrdenie cien a limitov, výsledok merania 30 + 30,
analytický poskytovateľ (kým nie je, integrácia vypnutá), hosting / e-mail / zálohy / monitoring / príjemcovia dát, DPH/OSS režim,
právne schválenie textov. Každý z nich má riadok v `/admin/launch`.
