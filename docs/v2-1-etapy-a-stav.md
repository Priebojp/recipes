# v2.1 – rozdelenie na etapy a stav implementácie

Zadanie: `moje-recepty-v2-1-dodatok-ai-vyziva-stripe.md` (dodatok k `moje-recepty-v2-predplatne-admin-pravne.md`).
Táto stránka je **záznam toho, čo je z dodatku hotové** (etapa = samostatná vetva/PR, číslovanie pokračuje po etape 7
z `v2-etapy-a-stav.md`). Pôvodné zoznamy úloh boli po dokončení odstránené; všetko, čo ešte zostáva (nasadenie, platené
overenia, rozhodnutia prevádzkovateľa, právne a Stripe vstupy), je na jednom mieste v `otvorene-ulohy.md`.

Stav k 27. 9. 2026: kód všetkých etáp 8–13 je zlúčený v `master`, testová sada prechádza (310 testov).

| # | Etapa | Stav | Obsah |
|---|---|---|---|
| 8 | **Profily obrázkov a porovnanie low/medium** | ✅ hotové (PR #8) · porovnávací beh a rozhodnutie → `otvorene-ulohy.md` §2–3 | Verzované profily `image_economy_v1` / `image_standard_v1` / `image_high_v1`, snímka kódu profilu na úlohe, druh použitia `image_economy` v ledgeri, `app:ai-compare-images` (10 jedál × 2 low + 2 medium), admin hodnotenie a prehľad nákladov podľa profilu; **žiadna zmena ponuky** |
| 9 | **Databáza potravín a priradenie ingrediencií** | ✅ hotové (PR #9) · `USDA_FDC_API_KEY` a prvý `app:food-sync` → §1 | `FoodSourceRecord` (USDA FoodData Central ako jediný prvý zdroj, cache, licencia), ručný SK/CZ slovník bežných surovín, `IngredientFoodMapping` s prevodom jednotiek a stavom suroviny, admin kurátorstvo a neúspešné priradenia |
| 10 | **Výživové hodnoty receptu** | ✅ hotové (PR #10) | `NutritionCalculation` (revízia receptu, kompletnosť, predpoklady, verzia výpočtu), tok „Vypočítať výživové hodnoty“ s potvrdením priradení, zobrazenie na recept / porciu / 100 g, neaktuálnosť po editácii; bez AI a bez použití |
| 11 | **Rozpoznanie jedla z fotografie** | ✅ hotové (PR #11) · testovacie fotky a `app:ai-measure --meal-analyses` → §2 | `MealAnalysis` + `MealAnalysisItem`, `AiJobKind::MealAnalysis` s obrazovým vstupom gpt-6-luna, druh použitia `meal_analysis` (3 skúšobné na používateľa), obrazovka „Skontroluj jedlo“, súkromné úložisko fotiek s TTL a odstránením EXIF, meranie nákladu analýzy |
| 12 | **Súkromný denník „Zjedol som“** | ✅ hotové (PR #12) | `MealConsumption` + `ConsumptionNutritionSnapshot` (recept / analýza / manuálne jedlo), zjedený podiel a opravy po zložkách, oprávnenia iba pre vlastníka denníka, export/výmaz/čistenie, oddelenie od `CookingEvent` |
| 13 | **Ponuka v2.1, admin, právne a Stripe údaje** | ✅ hotové (PR #12, spolu s etapou 12) · vstupy prevádzkovateľa → §3–5 | Katalóg verzia 2 (Economy obrázky, analýzy jedla, balík analýz) len po rozhodnutí z etapy 8, granty `meal_analysis` z predplatného, admin moduly (profily, náklady analýz, stav kľúčov, kurátorstvo), návrh novej verzie informácií o súkromí a VOP, kap. 11 (Stripe údaje) v identite prevádzkovateľa a launch checkliste |

## Zásady platné pre celý dodatok

- **Každé zobrazené kcal má dohľadateľný zdroj a množstvo.** Odhad je viditeľne odlíšený od potvrdeného/odváženého vstupu,
  chýbajúca hodnota nie je nula, výsledok s chýbajúcimi údajmi je „Čiastočný súčet“. Nič z toho nie je medicínske meranie.
- Platené limity, ceny a právne texty sa etapami 8–12 nezmenili. Všetko, čo mení ponuku, je v etape 13 a je podmienené
  rozhodnutiami prevádzkovateľa (`otvorene-ulohy.md` §3). Existujúce zakúpené nároky sa nikdy nezhoršia.
- Platené testy (porovnanie obrázkov, meranie analýz) sa spúšťajú iba príkazom s potvrdením `--yes` a vopred vypísaným
  odhadom nákladu; nikdy z testovacej sady.

## Etapa 8 – Profily obrázkov a porovnanie low/medium

Cieľ: namiesto jednej globálnej kvality (`ai.image_quality`) mať verzované profily, ktoré si úloha snímkuje, a ledger
rozlišuje Economy od Standard. Ponuka sa nemení, iba sa pripraví experiment a jeho vyhodnotenie.

### Hotové

- `App\Services\Ai\ImageProfile` (enum `image_economy_v1` / `image_standard_v1` / `image_high_v1`): kvalita, 1024 × 1024, počet 1,
  druh použitia (`ImageEconomy` / `ImageStandard`; High ide na Standard nárok, nikdy nie je predvolený ani vystavený), snímka
  `ai_jobs.profile` s `code`. Snímka bez kódu (v2) sa číta ako Standard, uložená kvalita/rozmer sa pri behu úlohy zachovajú.
- `UsageKind::ImageEconomy = 'image_economy'`; `fromAiJobKind()` zrušené – `AiJobLifecycle::create()` a `AiAvailability` pracujú
  s `UsageKind`, obrázok dostane druh z profilu. `AiAvailability::imageProfileFor()` odvodí profil na serveri: predvolený profil,
  ak naň domácnosť má nárok, inak prvý vystavený profil s nárokom (dnes Standard trial/balík); klient nič neposiela.
- Admin nastavenia: „Predvolený profil obrázkov“ (Economy/Standard) namiesto kvality a rozmeru; `.env` `RECIPES_AI_IMAGE_QUALITY`
  ostáva predvoľbou (`low` → Economy, `medium` → Standard), `RECIPES_AI_IMAGE_SIZE` zrušené (rozmer je súčasť profilu).
- `app:ai-compare-images <domácnosť> [--yes] [--report=]` (`ImageProfileComparison`): 10 jedál × (2 low + 2 medium), odhad
  z `ai_cost_rates` vopred, granty `compensation:compare-{beh}-{profil}`, úlohy s `input.comparison_run`, obrázky neaktívne.
  Hodnotenie `/admin/ai/comparisons/{beh}` (áno/nie + poznámka, súčty, kritérium 18/20), rozhodnutie → launch signoff
  `image_profile` (voliteľný – checklist ho hlási ako upozornenie, nie blokádu) + audit `ai.image_comparison.completed`.
- `/admin/ai`: tabuľka „Obrázky podľa profilu“ (`AiUsageReport::byImageProfile`) a zoznam porovnaní; `/admin/ai/settings` ukazuje
  sadzbu každého profilu. Refundácie v `/admin/orders/{id}` odoberajú jednotky ľubovoľného druhu, ktorý objednávka udelila.
  `/settings/usage` zobrazuje Economy až keď domácnosť nejaký taký grant má. `app:ai-measure` meria s predvoleným profilom.
- Testy: `tests/Feature/Ai/ImageProfileTest.php` (scenáre 1 a 2, starý job bez kódu, Economy vs. Standard granty, porovnávací
  beh na fake poskytovateľovi, hodnotenie a rozhodnutie, backfill), upravené `AiSettingsTest`, `RefundAdminTest`, `HouseholdAdminTest`.

### Nasadenie

Bez novej tabuľky ani migrácie. Po nasadení: `php artisan app:ai-backfill-image-profiles` (idempotentné, `--dry-run` iba spočíta),
potom porovnávací beh `php artisan app:ai-compare-images <testovacia domácnosť> --yes` a hodnotenie v admine; rozhodnutie odškrtnúť
v `otvorene-ulohy.md` §2–3.

## Etapa 9 – Databáza potravín a priradenie ingrediencií

Cieľ: jeden overený zdroj výživových dát so snímkami a licenciou, ručne skontrolovaný slovník bežných SK/CZ surovín
a väzba ingrediencie receptu na potravinu s bezpečným prevodom jednotiek. Bez AI.

### Hotové

- Zdroj: rozhranie `App\Services\Food\FoodDataSource` (`search`, `fetch`, `isConfigured`) s implementáciou `UsdaFoodDataCentral`
  (`config/services.php` → `usda.key` z `USDA_FDC_API_KEY`, `base_url`; HTTP klient s timeoutom 5/15 s, retry na výpadok/429/5xx,
  cache odpovedí 7 dní, aplikačný limiter 900 req/h – `config/recipes.php` → `food`). Hodnoty na 100 g, kcal primárne z živiny 1008
  (záloha Atwater 2048/2047, zdroj uložený v snímke), sacharidy s metodikou `by_difference`/`by_summation`, kJ oddelene, `null` = neznáme.
  USDA porcie sa importujú ako prevody `usda_portion` (cup → šálka, tbsp → PL, tsp → ČL, medium/large → ks, clove → strúčik, slice → plátok).
  Bez kľúča: `FoodSourceUnavailableException` s jasnou hláškou, slovník so snímkami funguje. V testoch `Tests\Support\FakeFoodDataSource`.
- Tabuľky `food_source_records` (unikát provider + external_id, licencia, `preparation_state`, `basis`, živiny nullable, `carbohydrate_method`,
  `source_snapshot`, `fetched_at`, `is_curated`, `sync_warning`), `food_aliases` (SK/CZ synonymá, `normalized` ako v nákupnom zozname,
  kurátor), `food_unit_conversions` (gramáž jednotky pre konkrétnu potravinu, `ml` = hustota, `source` usda_portion|manual|label),
  `ingredient_food_mappings` (jedno priradenie na riadok ingrediencie, `grams` + `grams_origin`, `status`, `unresolved_reason`, kto potvrdil).
  Enumy `FoodPreparationState`, `FoodMappingStatus`, `FoodGramsOrigin`.
- `FoodAliasSeeder`: 149 potravín (ID overené voči USDA API 26. 9. 2026, uložené ako `snapshot.seed.expected_name`) a 400 aliasov;
  seed nesie iba ID, názvy a stav – hodnoty stiahne `app:food-sync [--dry-run] [--only=ID…]` (`FoodCatalog::sync`), ktorý zachová
  `name_sk`, stav, kurátorský príznak a ručné prevody, **nedotýka sa priradení**, a označí `sync_warning`, keď sa popis v zdroji
  zmenil alebo záznam zmizol. Súhrn posledného behu je v `app_settings` (`food.sync.last`).
- `IngredientMatcher`: kandidáti zo slovníka (celý názov > dlhší viacslovný alias > jednoslovný; poznámka v zátvorke sa ignoruje),
  voliteľne vyhľadanie v zdroji pre neznáme názvy (hity sa nikdy neukladajú samy); `resolveGrams` prevedie g/dkg/kg priamo,
  ml/dl/l iba cez hustotu potraviny, ks/PL/ČL/šálka/strúčik/… iba cez potvrdený prevod, „podľa chuti“ a neznáma jednotka = nepriradené.
  `FoodMappingService::propose()` (neprepisuje potvrdené/odmietnuté), `confirm()` (ručná gramáž musí byť „zadané“ alebo „odhad“;
  bez gramáže ostáva `unresolved`), `reject()`, `unresolvedNames()` (agregované názvy bez receptov). `FoodCatalog::resolve()` prijme
  iba existujúce interné ID alebo ID overené v zdroji (scenár 6).
- Admin `/admin/food` (password.confirm, menu „Výživa → Potraviny“): stav kľúča bez hodnoty, posledný sync, počty, nepriradené suroviny,
  vyhľadanie v USDA a import, katalóg s filtrom, detail so slovenským názvom/stavom/kurátorstvom, aliasy a prevody. Audit
  `food.record.imported|updated|refreshed`, `food.alias.created|deleted`, `food.conversion.created|updated|deleted`.
- Testy: `tests/Feature/Food/UsdaFoodDataCentralTest.php` (fixtúry z reálnych odpovedí v `tests/Fixtures/usda/`), `FoodCatalogTest.php`
  (seed idempotentný, sync so snímkou/licenciou/porciami, upozornenia, dry-run, bez kľúča, scenár 6), `IngredientMatcherTest.php`
  (scenáre 7 a 8, prevody, „podľa chuti“, návrhy vs. rozhodnutia), `tests/Feature/Admin/FoodAdminTest.php` (403, stav, import, aliasy, prevody, audit).

### Nasadenie

Štyri tabuľky (`2026_09_26_200656_create_food_tables`). Do `.env` doplniť `USDA_FDC_API_KEY` (bezplatný kľúč z https://fdc.nal.usda.gov/api-key-signup),
potom `php artisan db:seed --class=FoodAliasSeeder` a `php artisan app:food-sync --dry-run` → `php artisan app:food-sync`. Bez kľúča funguje
slovník s uloženými snímkami (po prvom synci), vyhľadávanie nových potravín a sync sú vypnuté s jasnou hláškou v admine aj v príkaze.
Pozor: demo kľúč USDA má limit 10 požiadaviek za hodinu – na prvý sync 149 záznamov treba vlastný kľúč.

## Etapa 10 – Výživové hodnoty receptu

Cieľ: používateľ na recepte klikne „Vypočítať výživové hodnoty“, potvrdí nejednoznačné priradenia a dostane kcal +
makrá pre recept a porciu s viditeľnými predpokladmi. Čisto matematika nad etapou 9; nespotrebúva AI ani použitia
a je dostupná aj Free domácnostiam (rozhodnutie o gatingu je v otvorených otázkach – predvolene bez gatingu).

### Hotové

- Tabuľka `nutrition_calculations` (`2026_09_27_082406`): `recipe_id`, `recipe_revision_id`, `calculation_version`, `servings`, `final_weight_g`,
  `totals` / `per_serving` / `per_100g` (JSON per živina, `null` = neznáme), `completeness` (`complete|partial`), `components` (každá zložka so zdrojom
  – provider, external_id, názov, licencia, stav –, gramážou, pôvodom gramáže, podielom a hodnotami na 100 g v čase výpočtu), `missing`, `assumptions`,
  `stale_at`, `created_by`. Model `NutritionCalculation` (+ factory), enum `NutritionCompleteness`, relácia `Recipe::nutritionCalculations()`.
- `App\Services\Nutrition\NutritionCalculator` (čistá matematika, bez DB, `VERSION = 1`): `grams × podiel / 100 × hodnota na 100 g`, súčet len
  započítaných zložiek; kcal vždy z databázy (nie 4/4/9), kJ oddelene; chýbajúca hodnota jadra (kcal, bielkoviny, sacharidy, tuky) = `partial`
  s dôvodom v `missing`, chýbajúca voliteľná živina (kJ, vláknina) = `null` v súčte; zložka bez potraviny alebo bez gramáže = `missing`;
  výnimka: potravina s ≤ 5 kcal/100 g bez množstva (soľ „podľa chuti“) sa vynechá s poznámkou v `assumptions`, nie ako chýbajúca. Podiel < 100 %
  („olej nezjedený celý“) a odhadovaná gramáž sú vždy v `assumptions`. Na porciu = `totals / base_servings`; na 100 g len pri zadanej konečnej hmotnosti
  (nikdy zo súčtu surových množstiev). `NutritionFormatter` zaokrúhľuje až pri zobrazení (kcal na 5, nad 1 000 na 10; gramy pod 10 g na desatinu),
  neznáme = „–“, nikdy 0.
- `RecipeNutrition`: `prepare()` (obnoví gramáže potvrdených priradení podľa aktuálneho množstva v recepte – nové `FoodMappingService::refreshRecipeAmounts()`,
  ručne zadané/odhadnuté gramáže ostávajú – a navrhne nepriradené riadky), `linesNeedingAmount()` (energeticky významná potravina bez gramáže),
  `calculate()` (zvyšné návrhy potvrdí, vypočíta, uloží proti aktívnej revízii; recept bez revízie ju dostane), `current()`, `inputsChanged()` +
  `markStale()`. `RecipeService::snapshotRevision()` označí kalkulácie ako neaktuálne len keď sa zmenili suroviny (názov, množstvo, jednotka) alebo
  počet porcií – zmena názvu receptu, poznámok či postupu nie. Staré behy sa nikdy neprepisujú.
- Livewire `NutritionPanel` na detaile receptu (karta „Výživové hodnoty“, bez gatingu, bez AI a bez použití): krok 1 návrh priradení, krok 2 kontrola
  (výber potraviny/stavu spomedzi kandidátov slovníka alebo „bez potraviny“, gramáž s pôvodom zadané/odhad, „Podľa receptu“, podiel celé/75/50/25/nič,
  voliteľná konečná hmotnosť), krok 3 výsledok (recept / porcia / 100 g, odznak „Čiastočný súčet“, zoznam chýbajúcich a predpokladov, tabuľka zložiek
  so zdrojom a gramážou, upozornenie „nie je medicínske meranie“). Energeticky významná surovina bez gramáže výpočet zastaví s výzvou (gramáž alebo
  „nezapočítať“). Neaktuálny výpočet ukáže „Výpočet je pre staršiu verziu receptu“ + Prepočítať. Člen s právom čítania vidí výsledok, `start`/`compute`/zmeny
  priradení vyžadujú `update` receptu; recept cudzej domácnosti = 404.
- Export schéma 3 (`config/recipes.php`): `ingredients[].food_mapping` (provider + external_id, stav, gramáž, pôvod) a `recipes[].nutrition_calculations`;
  výmaz receptu maže kalkulácie cez FK kaskádu.
- Testy: `tests/Unit/Nutrition/NutritionCalculatorTest.php` (scenáre 7–10, podiel/odhad/vylúčenie, formátovanie) a
  `tests/Feature/Nutrition/RecipeNutritionTest.php` (celý tok cez panel, scenáre 7–11, 15, 16, podiely, oprávnenia, export, kaskáda).

### Nasadenie

Jedna tabuľka; bez nových `.env` premenných. Kalkulácia pracuje len s uloženými snímkami potravín – bez `app:food-sync` (etapa 9) sú hodnoty
`null` a výsledok je „Čiastočný súčet“ s dôvodom „Zdroj nemá hodnotu“. Mlieko v ml a vajcia v ks potrebujú potvrdený prevod (hustota / ks) v `/admin/food`,
inak si panel vyžiada gramáž.

## Etapa 11 – Rozpoznanie jedla z fotografie

Cieľ: fotka → AI návrh zložiek → používateľ opraví a potvrdí → databázový výpočet z etáp 9/10. AI nikdy nevracia
kcal ani ID potravín; iba kandidátov, stav a otázky. Prvá verzia bez automatického vytvorenia receptu z fotky.

### Hotové

- Migrácia `2026_09_27_104258`: `ai_jobs.recipe_id` nullable, `ai_jobs.parent_ai_job_id` (doplnenia pod koreňovou úlohou), tabuľky `meal_analyses`
  (vlastník `user_id`, `ai_job_id`, `status`, `note`, `ai_result`, `ai_status`, `dish_name`, `questions`, `limitations`, `clarification_count`,
  `nutrition` – zmrazený databázový výpočet, `photo_retain_until`, `photo_removed_at`, `confirmed_at`, `expires_at`) a `meal_analysis_items` (`label`,
  `alternatives`, `preparation_state`, `estimated_grams` + `grams` + `grams_origin` `estimated|confirmed|measured`, `portion_basis`, `visible_evidence`,
  `assumptions`, `food_source_record_id` overený serverom, `mapping_status`, `is_unknown`, `included`), `users.meal_photo_notice_accepted_at`.
  Modely `MealAnalysis` (media kolekcia `photo` na disku `recipes.meal_analysis.disk`, predvolene `local`, bez verejnej URL) a `MealAnalysisItem`, enumy
  `MealAnalysisStatus`, `MealAnalysisAiStatus`, `MealGramsOrigin`, `AiJobKind::MealAnalysis`, `UsageKind::MealAnalysis`.
- Ledger: skúšobné 3 analýzy raz na overeného používateľa (`recipes.usage.trial.meal_analysis`, kľúč `trial:meal_analysis:user:{id}`); `TrialGrants`
  rozhoduje po druhoch, takže domácnosti so starším text/obrázok trialom dostanú analýzy raz cez listener alebo `app:usage-backfill-trials`. Rezervácia pri
  vytvorení koreňovej úlohy, `AiJobLifecycle::succeed` (spotreba) len pri `recognized|needs_clarification`; `not_food|unusable` → nové
  `succeedWithoutCharge` (úloha doručená a ocenená, použitie vrátené); definitívna chyba uvoľní, timeout → `reconciling`. Denný limit
  `RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT` (aj v `/admin/ai/settings`) počíta i neúčtované pokusy. `AiAvailability::reasonUnavailable(..., ledger: false)` pre
  doplnenia bez rezervácie; `AiJobLifecycle::create($attrs, null)` = úloha bez rezervácie.
- `App\Services\Ai\MealAnalysisService`: `upload` (normalizácia cez `ImageUploadService::normalise` – GD, bez EXIF/GPS, ≤ 1 536 px), `analyze`
  (idempotentný kľúč `meal|analýza|verzia|pokus`; bežiaca/držaná/doručená úloha sa vracia, nový pokus až po definitívnej chybe), `run`
  (`MealAnalysisAgent` s obrazovým vstupom `Laravel\Ai\Files\Image::fromPath`, prompt = iba poznámka + prípadné doplnenie; `validateOutput` ponechá len polia
  schémy – `food_id`, kcal či „pravdepodobnosti“ zahodí; kandidáti sa hľadajú serverom cez `IngredientMatcher::proposeName` v slovníku etapy 9),
  `clarify` (max. `recipes.meal_analysis.max_clarifications` = 2, dieťa koreňovej úlohy bez rezervácie, náklad meraný), `updateItem`/`addItem`/`removeItem`/
  `chooseFood` (iba ID spomedzi serverových kandidátov), `calculate` (`NutritionCalculator` etapy 10, 1 porcia), `confirm` (zmrazí `nutrition` alebo uloží bez
  kalórií, potvrdí navrhnuté priradenia, `expires_at` null, fotka podľa voľby „ponechať“), `discard`, `removePhoto`, `latestJob`.
- Súkromie: jednorazové vysvetlenie „fotka sa odošle OpenAI“ pred prvým odoslaním (`users.meal_photo_notice_accepted_at`, produktové potvrdenie);
  fotka sa servíruje iba vlastníkovi cez `GET /jedlo/analyza/{analysis}/foto` (`MealPhotoController`, `Cache-Control: no-store, private`), člen domácnosti
  403, `MealAnalysisPolicy` len pre `user_id`. Oprava mimo etapy: `ImageUploadService::normalise` vynucuje GD driver – spatie/image s Imagick
  (predvoľba, keď je rozšírenie nainštalované) EXIF pri prekódovaní kopíroval, takže ani fotky receptov predtým metadáta nestrácali.
- Retencia: `app:meal-analysis-cleanup` (scheduler denne 03:40, `--dry-run`): pracovné fotky po `RECIPES_MEAL_PHOTO_TTL_HOURS` (24) od dokončenia/zlyhania,
  ak si ich používateľ nenechal; nedokončené/zahodené návrhy po `RECIPES_MEAL_DRAFT_TTL_DAYS` (7). `AccountErasure` maže analýzy s fotkami pri výmaze
  domácnosti aj pri odchode člena (sú osobné).
- UI `/jedlo/analyza` (položka „Jedlo“ v navigácii, Livewire `MealPhotoAnalyzer`): upload/odfotenie (`capture="environment"`), poznámka, zostatok použití,
  stav spracovania (poll), „Skontroluj jedlo“ (premenovanie, výber potraviny z kandidátov, gramáž s pôvodom potvrdené/odvážené/odhad a odznakom,
  nezapočítať, neznáma zložka, odobrať, pridať), 1–2 otázky AI s odpoveďou bez ďalšieho odpočtu, predbežný súčet s odznakom „Čiastočný súčet“,
  „Potvrdiť a vypočítať“ / „Uložiť bez kalórií“ / „Ponechať fotku“ / „Zahodiť“, výsledok `not_food|unusable` ako „Nedokážem určiť“ bez čísel, potvrdený
  záznam s tabuľkou zložiek a zdrojov, zoznam posledných fotiek. „Zjedol som“ príde v etape 12.
- Admin: `/admin/ai` pozná druh „Analýza jedla“ (filter, tabuľky po modeli/domácnosti/dňoch, doplnenia označené nadradenou úlohou) – iba metadáta úlohy,
  nikdy fotka ani zložky. Meranie: `app:ai-measure --meal-analyses=N [--fixtures=dir]` beží na vlastných fotkách prevádzkovateľa z `tests/fixtures/meals/`
  s vlastným kompenzačným grantom `meal_analysis`; report obsahuje medián/p95 ceny a trvania celej analýzy, úspešnosť, výsledky, otázky, opravy, doplnenia.
- Testy: `tests/Feature/Ai/MealAnalysisTest.php` (scenáre 3, 4, 5, 6, 13, 14 + tok kontroly a potvrdenia, doplnenia, kill switch/blokovanie/denný limit/trial,
  súhlasná obrazovka a validácia, cleanup, admin bez náhľadu a výmaz) a rozšírené `AiMeasurementTest`, `UsageLedgerTest` (3 skúšobné druhy).
  Export a `ExportService` sa dopĺňajú v etape 12 spolu s denníkom.

### Nasadenie

`php artisan migrate` (ai_jobs + dve tabuľky + users), `.env`: `RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT`, `RECIPES_USAGE_TRIAL_MEAL_ANALYSES`,
`RECIPES_MEAL_PHOTO_DISK`, `RECIPES_MEAL_PHOTO_TTL_HOURS`, `RECIPES_MEAL_DRAFT_TTL_DAYS` (všetky s predvoľbami); scheduler musí bežať
(`app:meal-analysis-cleanup`); `app:usage-backfill-trials` pridelí existujúcim overeným vlastníkom 3 analýzy; `AiCostRateSeeder` bez zmeny (gpt-6-luna
sadzby existujú), obrazové vstupné tokeny sa účtujú podľa usage z endpointu. Pred launch checklistom nahrať vlastné fotky do `tests/fixtures/meals/`
a spustiť `app:ai-measure <domácnosť> --text=0 --images=0 --meal-analyses=10 --yes`.

## Etapa 12 – Súkromný denník „Zjedol som“

Cieľ: osobný, voliteľný denník konzumácie s nemennými snímkami výpočtu; oddelený od `CookingEvent` (varenie zostáva
vstupom generátora opakovaní). Bez cieľov, diét, diagnóz, váhy, detských profilov a bez zdieľania v domácnosti.

### Hotové

- Migrácia `2026_09_27_141114`: `meal_consumptions` (`user_id` vlastník, `household_id` kontext, `eaten_at` UTC + `timezone` + lokálny deň `eaten_on`,
  `source` `recipe|analysis|manual`, `recipe_id` / `recipe_revision_id` / `nutrition_calculation_id` / `meal_analysis_id` nullable s `nullOnDelete`,
  `title_snapshot`, `portion_mode` `fraction|grams|per_component`, `portion_fraction`, `grams`, `note`, soft delete) a `consumption_nutrition_snapshots`
  (`revision` od 1, `calculation_version`, podiel, `component_shares`, zmrazený `basis` – zdroj, jednotkové hodnoty, hmotnosť, verzie –, `totals` null =
  bez kalórií, `completeness`, `components` so zjedenými gramami a zdrojom, `missing`, `assumptions`, `manual_origin` `label|estimate`; riadok sa nikdy
  neaktualizuje, oprava = ďalšia revízia). Modely `MealConsumption` (`snapshot()` = najvyššia revízia, `snapshots()` história), `ConsumptionNutritionSnapshot`,
  enumy `ConsumptionSource`, `PortionMode`, `ManualNutritionOrigin`.
- `App\Services\Diary\ConsumptionCalculator` (čistá matematika, `VERSION = 1`): jednotka = porcia receptu (`components` kalkulácie ÷ `servings`) alebo
  celý tanier z fotky; režim `fraction` (podiel, aj > 100 %), `grams` (podľa `final_weight_g ÷ porcie`, inak súčet započítaných surovín s viditeľným
  predpokladom, bez hmotnosti odmietne), `per_component` (podiel na zložku, „ryžu nie, mäso áno“); chýbajúca hodnota zostáva chýbajúca, čiastočný súčet
  sa dedí a rozširuje; `ConsumptionBasis::fromSnapshot` prestavia základ zo snímky, takže oprava nikdy nečíta živý recept, mapovanie ani databázu.
- `MealDiaryService`: `logRecipe` (vyžaduje aktuálnu kalkuláciu; `stale` je povolená s predpokladom „pre staršiu verziu receptu“ a UI ponúkne prepočet;
  `withNutrition=false` = bez kalórií), `logAnalysis` (iba vlastná potvrdená analýza; uložená bez kalórií → záznam bez kalórií), `logManual` (názov +
  voliteľné hodnoty s povinným pôvodom etiketa/odhad, prázdna hodnota = neznáma, bez hodnôt = bez kalórií), `adjust` (nová revízia z pôvodnej snímky),
  `reschedule`, `delete` (soft), `entriesOn`, `dayTotals` (deň je čiastočný, ak je čiastočný ktorýkoľvek záznam alebo je bez kalórií). Žiadne AI,
  použitia ani Plus – funguje aj po skončení Plus.
- UI `/dennik` (položka „Denník „Zjedol som““ v menu účtu, Livewire `MealDiary`): jeden lokálny deň (zóna domácnosti) s navigáciou, súčet dňa s odznakom
  kompletný/čiastočný a počtom záznamov bez kalórií, formulár z receptu (`?recept=`) / z fotky (`?analyza=`) / ručne, podiel porcie / gramy / po
  zložkách s náhľadom, dátum a čas, poznámka; záznam s detailom snímky (hodnoty, zložky a zdroje, chýba v súčte, predpoklady, číslo revízie), „Opraviť
  podiel“ (nad zmrazenou snímkou) a zmazanie. Bez cieľov, diét a trendov. Vstupy: `CookedPanel` po potvrdení uvarenia iba ponúkne odkaz „Zapísať, čo som
  zjedol(a)“ (konzumáciu nevytvára), `NutritionPanel` pri výsledku má rovnaký odkaz, `MealPhotoAnalyzer` pri potvrdenom jedle tlačidlo „Zjedol som“.
  Reštauračné jedlo nevytvára `CookingEvent`.
- Oprávnenia a súkromie: `MealConsumptionPolicy` iba pre `user_id`; stránka zobrazuje len vlastné záznamy, cudzí záznam cez upravené ID = 404, cudzia
  analýza v `?analyza=` = 403; admin dashboard ani `/admin/ai` denník nezobrazujú. Export účtu (`/settings/privacy/export`) je teraz ZIP `ucet.json`
  (schéma 2: + `meal_analyses` so zložkami a `meal_consumptions` so všetkými revíziami snímok, aj zmazané) + `fotky/` s ponechanými fotkami; export
  domácnosti sa nemení. `AccountErasure` maže denník aj analýzy používateľa pri odchode člena i pri výmaze domácnosti (force delete, `hasContent` ich
  pozná → `app:privacy-reapply-erasures` ich zahrnie). Analytika: allowlist `meal_logged` bez vlastností, vysielaný raz po zápise.
- Testy: `tests/Unit/Diary/ConsumptionCalculatorTest.php` (scenár 9: 250 → 125, gramy voči odváženej porcii aj súčtu surovín, po zložkách s chýbajúcou
  hodnotou, prestavba základu zo snímky) a `tests/Feature/Diary/MealDiaryTest.php` (scenáre 9, 11, 12, 13, 14, 15 + ručný záznam bez pôvodu hodnôt je
  odmietnutý, bez hodnôt sa uloží bez kalórií). `AccountErasureTest` číta export účtu zo ZIP-u.

### Nasadenie

`php artisan migrate` (dve tabuľky); bez nových `.env` premenných, bez zmeny sadzieb, katalógu či právnych textov. Odporúča sa doplniť informácie o súkromí
o účel denníka v etape 13 (text je už v zozname úloh etapy 13).

## Etapa 13 – Ponuka v2.1, admin, právne a Stripe údaje

Cieľ: až po funkčnom overení etáp 8–12 vystaviť platené limity a doplniť právne a obchodné údaje. Etapa má dve časti:
kód (pripravený a testovaný s konfigurovateľnými hodnotami) a vstupy prevádzkovateľa (bez nich sa nič neaktivuje).

### Hotové

- Migrácia `2026_09_27_…_add_v2_1_offer_columns_to_plan_versions`: `plan_versions.image_profile_code` (predvolene `image_standard_v1`) a
  `meal_analysis_uses_per_period` (0). `PlanVersion::usesPerPeriod()` otvára obrázky v druhu profilu (`image_economy` alebo `image_standard`,
  nikdy oba) a analýzy len keď ich verzia obsahuje; snímka objednávky nesie profil a počet analýz; `PlanVersion::describeUses()` je jeden
  popis obsahu pre cenník, zhrnutie objednávky, e-mail (opravený – čítal neexistujúce kľúče snímky) a admin. `CatalogManager::newPlanVersion`
  prijíma profil (iba predajné profily) a analýzy, `createAddon()` zakladá balík pod novým kódom ako návrh v1. `CatalogSeeder` verziu 2 plánov
  **nevytvára**; balík `meal_analyses_100` (100 analýz, návrh 1,99 €) seeduje ako **návrh**, Economy balík vôbec (cena až po teste).
  `.env`: `STRIPE_PRICE_MEAL_ANALYSES_100`, `STRIPE_PRICE_IMAGES_ECONOMY_20`; allowlist analytiky pozná nové kódy ponuky.
- Granty: `UsageProvisioner` číta `usesPerPeriod()` verzie plánu platnej pre dané obdobie (nárok vzniká pri zaplatení obdobia s cenou danej
  verzie), takže aktivácia verzie 2 bežiace obdobie nemení a nové druhy prídu až s obnovou; `StripeEventProcessor` udeľuje nové balíky rovnakou
  cestou (`order:{id}`, `unit_kind`), `RefundService` odoberá nevyužité jednotky nových druhov. `/cennik`, zhrnutie objednávky, `/settings/usage`
  a `/settings/subscription` zobrazujú nové druhy cez `UsageKind::label()`. Po skončení Plus zostáva denník čitateľný a opravy dostupné; nová
  analýza vyžaduje nárok (ledger).
- Admin: `/admin/catalog` – „Nová verzia“ plánu s profilom obrázkov a analýzami za obdobie, „Nový balík“ (kód, druh, počet, cena, Stripe ID);
  `/admin/ai` – karta „Analýzy jedla – náklady“ (`AiUsageReport::mealAnalyses`: rozpoznané, bez jedla/nepoužiteľné, doplnenia, medián/p95 ceny
  celej analýzy, Ø cena rozpoznaného výsledku, medián trvania – iba metadáta úloh) a „Integračné kľúče“ (OpenAI text/obrázky, USDA – stav bez
  hodnôt). `LaunchReadiness` nové kontroly: `ai.profile_rates` (sadzba pre Economy low aj Standard medium; blokuje pri predávanom profile),
  `ai.meal_measurement` (`kinds.meal_analysis` z `app:ai-measure`, ≥ 10 rozpoznaných s aktuálnym modelom; blokuje, keď katalóg analýzy predáva),
  `food.usda` (kľúč), `env.meal_cleanup` (`app:meal-analysis-cleanup` zapisuje `ops.meal_analysis_cleanup_last_run_at`; > 36 h blokuje
  v produkcii), `legal.stripe_identity` (verejné meno a descriptor 5–22 znakov); potvrdenie `image_profile` blokuje, keď je v aktívnom katalógu
  Economy. Ručné položky „Stripe účet aktivovaný (overenie, výplaty)“ a „Daňový režim rozhodnutý (neplatiteľ / § 7a / platiteľ, OSS)“ s hintmi.
- Právne: `LegalDocumentSeeder` má texty v2.1 (VOP: balíky analýz, kap. 4 rozpoznanie fotky, 4a výživa a denník „nie medicínske meranie ani
  záruka alergénov“; súkromie: účel rozpoznania fotky s OpenAI ako príjemcom, retencia 24 h / 7 dní, USDA len všeobecné názvy, denník
  súkromný a mimo analytiky, riadky tabuľky účelov, deti a hostia mimo). Čerstvá inštalácia dostane v1 s týmito textami; inštalácia so staršou
  publikovanou verziou dostane pri `db:seed` **návrh** ďalšej verzie (značka `LegalDocumentSeeder::V21_MARKER`, idempotentne, nikdy sa
  nepublikuje samo; placeholder publikovanie odmietne). `ConsentServiceSeeder`: OpenAI ako príjemca (nevyhnutné, bez cookies).
- Stripe / identita: `OperatorIdentity::FIELDS` + `public_business_name`, `statement_descriptor`, `shortened_descriptor` (nepovinné, editujú sa v
  `/admin/legal`, kontroluje checklist); `runbook-launch.md` 4b a 6.3: tabuľka vyplnenia Stripe, anglický opis podnikania a poznámka, že
  rozpoznanie fotky sa doplní až keď je v ponuke; Stripe Tax bez rozhodnutia o režime nezapínať.
- Testy: `tests/Feature/Billing/CatalogVersion2Test.php` – verzia 2 neudelí nič bežiacemu obdobiu a nové druhy prídu s obnovou (scenár 17,
  staré granty nezmenené), balík analýz sa predáva až po aktivácii, webhook udelí raz, refund odoberie nevyužité; po skončení Plus denník čitateľný
  a oprava funguje, nová analýza nedostupná; checklist blokuje bez sadzieb/merania/potvrdenia a prejde s nimi, USDA/cleanup/Stripe identita;
  seeder vytvorí návrh v2 právnych textov a placeholder sa nepublikuje; admin UI návrhu plánu a nového balíka.

## Mapa akceptačných scenárov (kap. 12 dodatku)

Všetky uvedené testy existujú a prechádzajú (27. 9. 2026).

| Scenár | Etapa | Test |
|---|---|---|
| 1 klient nevynúti drahší profil, Standard grant ostáva Standard | 8 | `ImageProfileTest` |
| 2 low/medium nemení originály ani servírovanie | 8 | `ImageProfileTest` |
| 3 analýza spotrebuje `meal_analysis` | 11 | `MealAnalysisTest` |
| 4 retry = jeden odpočet, zlyhanie uvoľní | 11 | `MealAnalysisTest` |
| 5 fotka bez jedla bez kcal, neznáma zložka | 11 | `MealAnalysisTest` |
| 6 AI nevloží neexistujúce ID, neobíde vlastníka | 9, 11 | `Food/*`, `MealAnalysisTest` |
| 7 surová vs. varená ryža | 9, 10 | `Food/*`, `NutritionCalculatorTest` |
| 8 olej bez prevodu, chýbajúce ≠ 0 | 9, 10 | `Food/*`, `NutritionCalculatorTest` |
| 9 1 000 kcal / 4 = 250, polovica 125 | 10, 12 | `NutritionCalculatorTest`, `ConsumptionCalculatorTest` |
| 10 100 g len s konečnou hmotnosťou | 10 | `NutritionCalculatorTest` |
| 11 editácia → stale, história nemenná | 10, 12 | `RecipeNutritionTest`, `MealDiaryTest` |
| 12 uvarenie ≠ konzumácia, reštaurácia ≠ varenie | 12 | `MealDiaryTest` |
| 13 člen nečíta cudzí denník, admin bez náhľadu | 11, 12 | `MealAnalysisTest`, `MealDiaryTest` |
| 14 fotka mimo analytiky, cleanup/výmaz/export | 11, 12 | `MealAnalysisTest`, `MealDiaryTest`, `AccountErasureTest` |
| 15 matematická oprava bez AI aj po Plus | 10, 12 | `RecipeNutritionTest`, `MealDiaryTest` |
| 16 kalkulácia dokumentuje zdroj/hmotnosť/odhady | 10 | `RecipeNutritionTest` |
| 17 nové limity bez migrácie katalógu nepripíšu, nároky sa nezhoršia | 13 | `Billing/CatalogVersion2Test` |

## Otvorené rozhodnutia (kap. 13) a kde blokujú

Konkrétne kroky k každému rozhodnutiu sú v `otvorene-ulohy.md`; tu je iba mapa, čo ktoré rozhodnutie blokuje a ako sa kód správa, kým nepadne.

| Rozhodnutie | Blokuje | Do rozhodnutia |
|---|---|---|
| Výsledok low/medium a profil nového Plus | etapa 13 (ponuka) | etapa 8 beží s oboma profilmi, predvolený ostáva Standard |
| Počty a ceny (obrázky, analýzy, balíky) | etapa 13 | hodnoty sú v katalógu, verzia 2 sa nezakladá |
| USDA ako prvý zdroj | etapa 9 (implementácia) | plán počíta s USDA; zmena zdroja = iná implementácia `FoodDataSource` |
| Gating výživy receptov (Free vs. Plus) | etapa 10 (UI) | predvolene bez gatingu – výpočet nemá variabilný náklad |
| Retencia fotiek a právny základ nového účelu | etapa 11 (nasadenie), 13 | TTL konfigurovateľné (`RECIPES_MEAL_PHOTO_TTL_HOURS`, `RECIPES_MEAL_DRAFT_TTL_DAYS`), súhlasná obrazovka pred prvým odoslaním je hotová; návrh informácií o súkromí zo seedera čaká na právnu kontrolu a publikovanie |
| Denník len pre dospelého prihláseného používateľa | etapa 12 | MVP presne takto; detské profily a hostia mimo rozsah |
| Identita prevádzkovateľa, admin e-mail, DPH, Stripe aktivácia | etapa 13 a launch (v2 etapa 7) | checkout ostáva vypnutý |
