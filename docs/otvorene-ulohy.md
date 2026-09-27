# Otvorené úlohy (stav k 27. 9. 2026)

Kód všetkých etáp 1–13 (v2 aj dodatok v2.1) je zlúčený v `master` (PR #1–#12), testová sada prechádza (310 testov).
Táto stránka je **jediný zoznam toho, čo ešte nie je urobené** – nahrádza pôvodné zoznamy „Úlohy“ v `v2-etapy-a-stav.md`
a `v2-1-etapy-a-stav.md`, ktoré teraz držia iba záznam hotového. Poradie sekcií je poradie, v akom dáva zmysel postupovať.
Väčšinu položiek hlási aj `php artisan app:launch-check` / `/admin/launch`; zvyšok sú rozhodnutia prevádzkovateľa.

Ak sa niečo dokončí, riadok sa odškrtne alebo zmaže tu; do stavových stránok sa nepíše.

## 1. Nasadenie kódu v2.1 (jednorazovo po deployi etáp 8–13)

- [ ] `php artisan migrate` – potravinové tabuľky, `nutrition_calculations`, `ai_jobs` (nullable recept, nadradená úloha), `meal_analyses`,
  `meal_analysis_items`, `users.meal_photo_notice_accepted_at`, `meal_consumptions`, `consumption_nutrition_snapshots`, stĺpce ponuky v `plan_versions`.
- [ ] `php artisan app:ai-backfill-image-profiles --dry-run` → bez `--dry-run` (staré `ai_jobs.profile` bez kódu dostanú `image_standard_v1`; idempotentné).
- [ ] `php artisan app:usage-backfill-trials` – existujúci overení vlastníci dostanú 3 skúšobné analýzy jedla.
- [ ] `php artisan db:seed --class=FoodAliasSeeder` (149 potravín, 400 aliasov – iba ID a názvy).
- [ ] `php artisan db:seed --class=LegalDocumentSeeder` a `--class=ConsentServiceSeeder` – vznikne **návrh** ďalšej verzie VOP a informácií
  o súkromí s účelmi v2.1 a OpenAI v registri služieb. Nič sa nepublikuje samo (publikovanie je v sekcii 4).
- [ ] `.env`: `USDA_FDC_API_KEY` (vlastný kľúč z https://fdc.nal.usda.gov/api-key-signup – demo kľúč má 10 požiadaviek za hodinu, na prvý sync
  nestačí); voliteľne `RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT`, `RECIPES_USAGE_TRIAL_MEAL_ANALYSES`, `RECIPES_MEAL_PHOTO_DISK`,
  `RECIPES_MEAL_PHOTO_TTL_HOURS`, `RECIPES_MEAL_DRAFT_TTL_DAYS` (všetky majú predvoľby); `RECIPES_AI_MONTHLY_BUDGET_USD` (checklist varuje, keď chýba).
  Lokálne dnes chýba USDA kľúč aj rozpočet.
- [ ] `php artisan app:food-sync --dry-run` → `php artisan app:food-sync` (checklist „USDA FoodData Central kľúč“).
- [ ] Cron `php artisan schedule:run` každú minútu – spúšťa `app:billing-reconcile` (03:15) a `app:meal-analysis-cleanup` (03:40). Bez cleanupu
  neplatí sľub retencie fotiek v informáciách o súkromí; checklist v produkcii blokuje, keď nebežal > 36 h. Lokálne „zatiaľ nebežal“.
- [ ] V `/admin/food` potvrdiť prevody jednotiek pre bežné suroviny v ml a ks (mlieko – hustota, vajce – ks, …); bez prevodu si panel
  výživy vyžiada gramáž ručne.

## 2. Platené overenia na reálnom kľúči (jednorazovo, vždy s `--yes` a vypísaným odhadom)

- [ ] **Porovnanie profilov low/medium** (etapa 8): `php artisan app:ai-compare-images <testovacia domácnosť>` (odhad ≈ 20 × 0,006 + 20 × 0,053 USD),
  potom `--yes`; hodnotenie 40 obrázkov v `/admin/ai/comparisons/{beh}` (kritérium ≥ 18/20 Economy prijateľných, žiadna systematická zámena);
  rozhodnutie sa zapíše ako potvrdenie `image_profile`. Je to podklad pre sekciu 3.
- [ ] **Meranie 30 + 30 AI úloh**: `php artisan app:ai-measure <domácnosť> --yes` (checklist „Meranie 30 + 30 AI úloh“ dnes FAIL) a potom ručné
  potvrdenie „Meranie AI nákladov vyhodnotené“ – skutočná cena mesiaca Plus a balíkov dáva s cenníkom zmysel.
- [ ] **Meranie analýz jedla**: nahrať vlastné fotky prevádzkovateľa do `tests/fixtures/meals/` (adresár je dnes prázdny) a spustiť
  `php artisan app:ai-measure <domácnosť> --text=0 --images=0 --meal-analyses=10 --yes`. Bez ≥ 10 rozpoznaných výsledkov s aktuálnym modelom
  checklist blokuje predaj analýz.
- [ ] **Test clock**: `php artisan app:billing-test-clock` – obnova mesačného aj ročného plánu, zrušenie obnovovania, anchor 31. 1. → február,
  neúspešná obnova; výsledky sedia s ledgerom a nárokmi → potvrdenie „Simulácia test clock prebehla“.

## 3. Rozhodnutia prevádzkovateľa o ponuke v2.1 (bez nich sa nič nevystaví)

- [ ] Profil nového Plus podľa výsledku porovnania: 20 Economy obrázkov/mesiac, alebo ponechať 5 Standard.
- [ ] Počty obrázkov a analýz v Plus, cena balíka Economy (`images_economy_20`, cena až po teste) a balíka analýz (`meal_analyses_100`,
  návrh 1,99 €), zásady pre existujúcich predplatiteľov (nároky sa nikdy nezhoršia – kód to garantuje).
- [ ] Potvrdiť USDA ako prvý zdroj výživových dát; Open Food Facts až po licenčnom posúdení (ODbL, atribúcia).
- [ ] Retencia fotiek 24 h / 7 dní potvrdiť voči zálohám a infraštruktúre (TTL sú konfigurovateľné).
- [ ] Gating výživy receptov: predvolene bez gatingu (výpočet nemá variabilný náklad) – potvrdiť alebo zmeniť.
- [ ] Po rozhodnutí: v Stripe založiť ceny a doplniť `STRIPE_PRICE_MEAL_ANALYSES_100` / `STRIPE_PRICE_IMAGES_ECONOMY_20`; v `/admin/catalog`
  „Nová verzia“ plánu (profil, obrázky a analýzy za obdobie, price ID) → „Aktivovať“; aktivovať návrh balíka `meal_analyses_100`; „Nový balík“
  Economy. Aktivácia sa týka len nových a obnovených období. Overiť `php artisan app:launch-check --stripe`.
- [ ] Ručné potvrdenie „Ceny a limity potvrdené“ (2,49 €/mes., 24 €/rok, 30 textov + 5 obrázkov; balíky 3,99 € a 1,99 €; prípadná zmena = nová verzia katalógu).

## 4. Právne texty a identita prevádzkovateľa (checklist „Právne a prevádzkovateľ“ dnes FAIL)

- [ ] `/admin/legal` – identita: obchodné meno (presne podľa živnostenského registra), právna forma, sídlo, IČO, registrácia, e-mail podpory,
  e-mail pre reklamácie a odstúpenie, kontakt pre ochranu osobných údajov, subjekt ARS, cieľové krajiny.
- [ ] Verejné meno „Moje recepty“, statement descriptor `MOJE-RECEPTY.SK` (5–22 znakov, overiť v Stripe), skrátený descriptor.
- [ ] Právna kontrola a publikovanie bez placeholderov: VOP, informácie o súkromí (návrh v2.1 zo seedera), odstúpenie od zmluvy, stránka o cookies
  (lišta na ňu odkazuje); potvrdenie „Právne texty schválené právnikom“ pri publikovaní.
- [ ] Daňový režim (neplatiteľ / § 7a / platiteľ, OSS) a miesto dodania elektronickej služby → pole v identite; **Stripe Tax nezapínať skôr**;
  potvrdenie „Daňový režim rozhodnutý“.
- [ ] Doklady zo Stripe s účtovníkom (náležitosti, číslovanie, text o DPH, dobropisy pri refundácii – runbook kap. 5); potvrdenie „Doklady zo Stripe overené“.
- [ ] Analytický poskytovateľ – kým nie je vybraný, integrácia ostáva vypnutá.

## 5. Stripe účet, produkčné prostredie a zapnutie platieb

- [ ] Aktivácia Stripe účtu (overenie identity, výplaty), support e-mail, anglický opis podnikania z runbooku 4b/6.3 (rozpoznanie fotky doplniť
  až keď je v ponuke); potvrdenie „Stripe účet aktivovaný (overenie, výplaty) a vyplnený“.
- [ ] Živé kľúče `sk_live_` / `pk_live_`, `php artisan cashier:webhook` na produkčnej URL (registruje presný zoznam udalostí), potom
  `php artisan app:launch-check --stripe` – overí endpoint, udalosti a všetky ceny (sumu, interval, live/test).
- [ ] Produkčné prostredie podľa runbooku kap. 6: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://moje-recepty.sk`, skutočný
  odosielateľ pošty (lokálne `hello@example.com`), fronta a scheduler ako služby, zálohy, monitoring, príjemcovia dát.
- [ ] Administrácia: administrátor si zapne 2FA (checklist FAIL), `ADMIN_REQUIRE_TWO_FACTOR=true`, po zmene hesla odstrániť `ADMIN_INITIAL_PASSWORD`.
- [ ] Až keď `app:launch-check` skončí bez blokád: samostatné nasadenie s `RECIPES_CHECKOUT_ENABLED=true`.

## 6. Technický dlh (neblokuje launch)

- [ ] PHPStan (level 7, `composer types:check`) hlási 46 nálezov. Sú to typové anotácie, nie behové chyby: `LaunchCheck::$hint` dostáva
  `__()` (array|string) – 20 miest v `LaunchReadiness`; generiká `Collection<…>&stdClass` v `AiUsageReport`; tvary polí v `NutritionCalculator`,
  `RecipeNutrition`, `ImageProfileComparison`, `NutritionPanel`, `WeeklyMenuPlanner`; zbytočné `?->` (`AiCostCalculator`, `FinanceReport`,
  `RefundService`, `NutritionPanel`, `PlatformAdminSeeder`); `AiImageService::quality()` string vs. literal; `MediaController::recipeFor()`
  návratový typ; `CarbonImmutable` do `email_verified_at`. PHPStan nie je súčasť CI (`composer ci:check` spúšťa iba testy). Rozhodnúť:
  opraviť a pridať `types:check` do CI, alebo znížiť level.
- [x] CI workflow (`.github/workflows/tests.yml`) spúšťal push iba pre vetvu `main`, repo používa `master` – opravené 27. 9. 2026.
- [x] `.env.example` nemal `STRIPE_PRICE_MEAL_ANALYSES_100` a `STRIPE_PRICE_IMAGES_ECONOMY_20` – doplnené 27. 9. 2026.
