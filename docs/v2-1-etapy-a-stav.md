# v2.1 – rozdelenie na etapy a stav implementácie

Zadanie: `moje-recepty-v2-1-dodatok-ai-vyziva-stripe.md` (dodatok k `moje-recepty-v2-predplatne-admin-pravne.md`).
Táto stránka drží dohodnuté rozdelenie dodatku na menšie kusy (etapa = samostatná vetva/PR, číslovanie pokračuje po
etape 7 z `v2-etapy-a-stav.md`) a čo je z nej hotové. Poradie sleduje kapitolu 12 dodatku: najprv lacnejšie obrázky
a meranie, potom výživa vlastných receptov, potom rozpoznanie fotky, nakoniec denník, nové granty a komerčné
vystavenie. Nič z v2 sa neprerába – Cashier, ledger, oprávnenia a admin sa iba rozširujú.

| # | Etapa | Stav | Obsah |
|---|---|---|---|
| 8 | **Profily obrázkov a porovnanie low/medium** | ✅ kód hotový (vetva `v2-1-etapa-8-image-profiles`) · ⏳ porovnávací beh a rozhodnutie prevádzkovateľa | Verzované profily `image_economy_v1` / `image_standard_v1` / `image_high_v1`, snímka kódu profilu na úlohe, druh použitia `image_economy` v ledgeri, rozšírené `app:ai-measure` o porovnávací beh 10 jedál × (2 low + 2 medium), admin prehľad nákladov podľa profilu; **žiadna zmena ponuky** |
| 9 | **Databáza potravín a priradenie ingrediencií** | ✅ kód hotový (vetva `v2-1-etapa-9-food-database`) · ⏳ `USDA_FDC_API_KEY` a prvý `app:food-sync` na serveri | `FoodSourceRecord` (USDA FoodData Central ako jediný prvý zdroj, cache, licencia), ručný SK/CZ slovník bežných surovín, `IngredientFoodMapping` s prevodom jednotiek a stavom suroviny, admin kurátorstvo a neúspešné priradenia |
| 10 | **Výživové hodnoty receptu** | ✅ kód hotový (vetva `v2-1-etapa-10-nutrition`) | `NutritionCalculation` (revízia receptu, kompletnosť, predpoklady, verzia výpočtu), tok „Vypočítať výživové hodnoty“ s potvrdením priradení, zobrazenie na recept / porciu / 100 g, neaktuálnosť po editácii; bez AI a bez použití |
| 11 | **Rozpoznanie jedla z fotografie** | ✅ kód hotový (vetva `v2-1-etapa-11-meal-analysis`) · ⏳ testovacie fotky v `tests/fixtures/meals/` a `app:ai-measure --meal-analyses` na serveri | `MealAnalysis` + `MealAnalysisItem`, `AiJobKind::MealAnalysis` s obrazovým vstupom gpt-6-luna, druh použitia `meal_analysis` (3 skúšobné na používateľa), obrazovka „Skontroluj jedlo“, súkromné úložisko fotiek s TTL a odstránením EXIF, meranie nákladu analýzy |
| 12 | **Súkromný denník „Zjedol som“** | ✅ kód hotový (vetva `v2-1-etapa-12-meal-diary`) | `MealConsumption` + `ConsumptionNutritionSnapshot` (recept / analýza / manuálne jedlo), zjedený podiel a opravy po zložkách, oprávnenia iba pre vlastníka denníka, export/výmaz/čistenie, oddelenie od `CookingEvent` |
| 13 | **Ponuka v2.1, admin, právne a Stripe údaje** | ✅ kód hotový (vetva `v2-1-etapa-12-meal-diary`, spolu s etapou 12) · ⏳ vstupy prevádzkovateľa | Katalóg verzia 2 (Economy obrázky, analýzy jedla, balík analýz) len po rozhodnutí z etapy 8, granty `meal_analysis` z predplatného, admin moduly (profily, náklady analýz, stav kľúčov, kurátorstvo), nová verzia informácií o súkromí, kap. 11 (Stripe údaje) do identity prevádzkovateľa a launch checklistu |

## Ako k tomu pristúpime

- Každá etapa = vetva `v2-1-etapa-N-…` a PR proti `master`; pred kódom prečítať `.ai/rules` (ak existuje) a sibling
  súbory, testy písať podľa skillu `testing-best-practices`, po zmene PHP spustiť `vendor/bin/pint --dirty`.
- Etapy 8 a 9 sú nezávislé a môžu ísť paralelne. 10 stavia na 9, 11 na 9 + 10, 12 na 10 (+ 11 pre zdroj „analýza“),
  13 na všetkých.
- Pravidlo z dodatku pre celý plán: **každé zobrazené kcal má dohľadateľný zdroj a množstvo**, odhad je viditeľne
  odlíšený od potvrdeného/odváženého vstupu, chýbajúca hodnota nie je nula, výsledok s chýbajúcimi údajmi je
  „Čiastočný súčet“. Nič z toho nie je medicínske meranie.
- Platené limity, ceny a právne texty sa v etapách 8–12 **nemenia**. Všetko, čo mení ponuku, je až etapa 13 a je
  podmienené rozhodnutiami v kapitole „Otvorené rozhodnutia“ nižšie. Existujúce zakúpené nároky sa nikdy nezhoršia.
- Platené testy (porovnanie obrázkov, meranie analýz) sa spúšťajú iba príkazom s potvrdením `--yes` a vopred
  vypísaným odhadom nákladu; nikdy z testovacej sady.

## Etapa 8 – Profily obrázkov a porovnanie low/medium

Cieľ: namiesto jednej globálnej kvality (`ai.image_quality`) mať verzované profily, ktoré si úloha snímkuje, a ledger
rozlišuje Economy od Standard. Ponuka sa nemení, iba sa pripraví experiment a jeho vyhodnotenie.

### Čo dnes existuje (na čom staviame)

- `AiSettings::imageProfile()` vracia `quality/size/pixel_size/count` a `AiImageService::run()` ich posiela do SDK
  (`Image::of()->size()->quality()`), takže kvalita naozaj odchádza do API. Chýba kód profilu.
- `UsageKind` má iba `text` a `image_standard`; `UsageKind::fromAiJobKind()` mapuje každý obrázok na Standard
  (volá sa v `AiJobLifecycle::create` a `AiAvailability`). `AiCostRateSeeder` už má sadzby gpt-image-2 low/medium/high.
- `app:ai-measure` (`AiMeasurement`) vie spustiť N obrázkov na reálnom kľúči s vlastným kompenzačným grantom.

### Úlohy

1. **Profily ako kód**: `App\Services\Ai\ImageProfile` (enum alebo value object) s `image_economy_v1` (low, 1024×1024),
   `image_standard_v1` (medium), `image_high_v1` (high, nevystavený). Profil určuje model, kvalitu, rozmer a počet;
   `AiSettings::imageProfile()` vracia aj `code`. Admin nastavenie „kvalita“ sa nahradí výberom **predvoleného profilu**
   pre nové bezplatné/skúšobné použitia; `.env` `RECIPES_AI_IMAGE_QUALITY` zostáva ako predvoľba (nezaviesť premennú,
   ktorú nič nečíta).
2. **Druh použitia**: `UsageKind::ImageEconomy = 'image_economy'`. Mapovanie úloha → druh sa robí z profilu
   (`ImageProfile::usageKind()`), nie z `AiJobKind`; `fromAiJobKind()` nahradiť volaním s profilom. Nárok Standard sa
   nikdy nespotrebuje na low bez vedomej voľby: úloha s profilom Economy čerpá iba `image_economy` granty; ak domácnosť
   žiadne nemá (dnes nikto), ponúkne sa Standard profil s jeho grantom – t. j. **klient nevyberá profil, server ho
   odvodí z dostupných nárokov a katalógu** (akceptačný scenár 1).
3. **Kompatibilita**: existujúce `ai_jobs.profile` bez `code` sa čítajú ako `image_standard_v1`; skúšobný grant
   `image_standard` zostáva; `RefundService`, `AiUsageReport`, `/settings/usage` a admin filtre poznajú nový druh.
4. **Porovnávací beh**: `app:ai-measure` doplniť o `--compare-image-profiles` (alebo samostatný `app:ai-compare-images`):
   pevný zoznam 10 jedál zo zadania (halušky, paprikáš, guláš, polievka, rezeň s prílohou, rizoto, šalát, cestoviny,
   praženica, koláč), pre každé 2× low + 2× medium s rovnakým promptom, vopred vypísaný odhad nákladu z `ai_cost_rates`
   (orientačne 20 × 0,006 + 20 × 0,053 USD), potvrdenie `--yes`, výsledky ako bežné `ai_jobs` s `input.comparison_run`
   a obrázky v neaktívnej cover kolekcii testovacej domácnosti (nič sa neaktivuje).
5. **Hodnotenie**: stránka `/admin/ai/comparisons/{run}` (password.confirm) so 40 obrázkami vedľa seba, poľom
   „prijateľný áno/nie“ + poznámka (správnosť jedla/prílohy, prirodzenosť, artefakty) a súčtom; kritérium ≥ 18/20 low
   prijateľných a žiadna systematická zámena. Rozhodnutie sa zapíše ako launch signoff `launch.signoff.image_profile`
   (kto, kedy, výsledok) – tým je podklad pre etapu 13. Hodnotí administrátor, nie AI.
6. **Admin**: `/admin/ai` rozpad nákladov a počtu úloh podľa `profile.code`; nastavenia zobrazujú profily a ich sadzby.
7. **Testy** (`tests/Feature/Ai/ImageProfileTest.php`, rozšírenie `AiImageTest`, `UsageLedgerTest`): úloha snímkuje kód
   profilu; zmena predvoleného profilu nemení zaradenú úlohu; Economy úloha nečerpá Standard grant a naopak; klientský
   pokus poslať `quality`/`profile` sa ignoruje (scenár 1); starý job bez `code` sa vyhodnotí ako Standard; zachované
   originály a servírovanie sa profilom nemenia (scenár 2); porovnávací beh na `FakeImageProvider` vytvorí 40 úloh
   s správnym profilom a jedným grantom na druh.

### Čo je hotové (kód)

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

### Migrácia a nasadenie

Bez novej tabuľky ani migrácie. Po nasadení: `php artisan app:ai-backfill-image-profiles` (idempotentné, `--dry-run` iba spočíta),
potom porovnávací beh `php artisan app:ai-compare-images <testovacia domácnosť> --yes` a hodnotenie v admine; výsledok zapísať do
tejto stránky (sekcia „Otvorené rozhodnutia“).

## Etapa 9 – Databáza potravín a priradenie ingrediencií

Cieľ: jeden overený zdroj výživových dát so snímkami a licenciou, ručne skontrolovaný slovník bežných SK/CZ surovín
a väzba ingrediencie receptu na potravinu s bezpečným prevodom jednotiek. Bez AI.

### Úlohy

1. **Zdroj**: rozhranie `FoodDataSource` (`search(string $query)`, `fetch(string $externalId)`) s implementáciou
   `UsdaFoodDataCentral` (API kľúč `USDA_FDC_API_KEY` v `config/services.php`, HTTP klient s timeoutom, retry a cache
   odpovedí; limit 1 000 req/h/IP ošetriť rate limiterom na strane aplikácie). V testoch `FakeFoodDataSource`
   s fixtúrami. Open Food Facts a Kalorické tabuľky **nezakladať** (len rozhranie, ktoré ich neskôr umožní).
2. **`FoodSourceRecord`** (`food_source_records`): `provider`, `external_id`, `license` (USDA = CC0), `name`,
   `name_sk`, `preparation_state` (`raw|cooked|dry|…`), `basis` (`100g|100ml|serving`), živiny v pevných stĺpcoch
   `energy_kcal`, `protein_g`, `carbohydrate_g`, `fat_g`, `fiber_g` (nullable = neznáme, nikdy 0 ako náhrada),
   `energy_kj` oddelene, `source_snapshot` (JSON originálu), `fetched_at`, `is_curated`. Unikát `provider + external_id`.
   Poznámka: USDA uvádza sacharidy „by difference“ – uložiť metodiku do `carbohydrate_method`, nemiešať s inými zdrojmi.
3. **Slovník surovín** (`food_aliases`): SK/CZ synonymá → `food_source_record_id`, `preparation_state`, ručne
   kurátorované (`curated_by`, `curated_at`); seeder `FoodAliasSeeder` s ~100 bežnými surovinami (múka, cibuľa, ryža
   surová/varená, olej, smotana 33 %, …) a ich USDA ID – seed obsahuje iba ID a názvy, dáta sa stiahnu príkazom
   `app:food-sync` (idempotentné, s `--dry-run`).
4. **Prevody jednotiek** (`food_unit_conversions`): pre konkrétnu potravinu gramáž jednotky (`ks`, `PL`, `ČL`, `šálka`,
   `ml` cez hustotu), `source` (`usda_portion|manual|label`), `confirmed_by`. Žiadny globálny predpoklad 1 ml = 1 g;
   bez potvrdeného prevodu je množstvo `unresolved` (scenár 8).
5. **`IngredientFoodMapping`** (`ingredient_food_mappings`): `ingredient_line_id`, `food_source_record_id`,
   `preparation_state`, `grams` (nullable), `grams_origin` (`unit_conversion|user_entered|estimated`),
   `conversion_id`, `status` (`suggested|confirmed|rejected|unresolved`), `confirmed_by/at`. Služba
   `IngredientMatcher` navrhne priradenie zo slovníka (normalizovaný názov, ako v nákupnom zozname) a z vyhľadávania
   v zdroji; nikdy nevymýšľa ID (overuje existenciu záznamu).
6. **Admin** `/admin/food`: neúspešné/`unresolved` priradenia (agregované názvy bez receptov), kurátorstvo aliasov
   a prevodov (audit `food.alias.*`, `food.conversion.*` – zmena gramáže v zdieľanom katalógu je auditovaná), stav kľúča
   USDA bez zobrazenia secretu, posledný `app:food-sync`. Aktualizácia zdroja **neprepisuje** historické osobné
   výpočty (tie majú snímky – etapa 10/12).
7. **Testy** (`tests/Feature/Food/*`, `tests/Unit/Food/*`): sync s fake zdrojom ukladá snímku a licenciu; alias
   „ryža“ ponúkne surovú aj varenú ako rôzne záznamy (scenár 7); lyžica oleja bez prevodu ostáva `unresolved`
   (scenár 8); ml → g len cez hustotu potraviny; priradenie s neexistujúcim ID sa odmietne (scenár 6); admin bez roly 403;
   sync nemení existujúce mapovania.

### Čo je hotové (kód)

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

### Migrácia a nasadenie

Štyri tabuľky (`2026_09_26_200656_create_food_tables`). Do `.env` doplniť `USDA_FDC_API_KEY` (bezplatný kľúč z https://fdc.nal.usda.gov/api-key-signup),
potom `php artisan db:seed --class=FoodAliasSeeder` a `php artisan app:food-sync --dry-run` → `php artisan app:food-sync`. Bez kľúča funguje
slovník s uloženými snímkami (po prvom synci), vyhľadávanie nových potravín a sync sú vypnuté s jasnou hláškou v admine aj v príkaze.
Pozor: demo kľúč USDA má limit 10 požiadaviek za hodinu – na prvý sync 149 záznamov treba vlastný kľúč.

## Etapa 10 – Výživové hodnoty receptu

Cieľ: používateľ na recepte klikne „Vypočítať výživové hodnoty“, potvrdí nejednoznačné priradenia a dostane kcal +
makrá pre recept a porciu s viditeľnými predpokladmi. Čisto matematika nad etapou 9; nespotrebúva AI ani použitia
a je dostupná aj Free domácnostiam (rozhodnutie o gatingu je v otvorených otázkach – predvolene bez gatingu).

### Úlohy

1. **`NutritionCalculation`** (`nutrition_calculations`): `recipe_id`, `recipe_revision_id`, `calculation_version`
   (konštanta v kóde, bump pri zmene vzorca), `servings`, `final_weight_g` (nullable, ručne), `totals` (JSON per
   živina: hodnota alebo null), `per_serving`, `per_100g` (len ak `final_weight_g`), `completeness`
   (`complete|partial`), `missing` (zoznam ingrediencií bez hodnoty/gramáže), `assumptions` (napr. „olej započítaný
   celý“), `stale_at`, `created_by`. Jedna aktuálna kalkulácia na revíziu, staré zostávajú.
2. **`NutritionCalculator`** (čistá služba, bez DB volaní počas výpočtu): pre každú zložku
   `grams / 100 × hodnota na 100 g`, súčet len zahrnutých zložiek; `null` živina → `partial`; kcal primárne z databázy
   (nie 4/4/9), kJ sa nezamieňa; rozumné zaokrúhlenie až pri zobrazení (`NutritionFormatter`, ~650 kcal, nie 647,238).
3. **Tok v UI** (Livewire `NutritionPanel` na detaile receptu): krok 1 návrh priradení (`IngredientMatcher`),
   krok 2 potvrdenie nejednoznačných položiek, gramáže a stavu suroviny, voliteľné „výpek/olej nezjedený celý“
   s explicitným podielom, krok 3 výsledok (recept / porcia / 100 g pri hmotnosti) s odznakom „Čiastočný súčet“
   a zoznamom chýbajúcich a predpokladov. „Podľa chuti“ pri energeticky významných surovinách vyžiada množstvo.
4. **Neaktuálnosť**: pri uložení novej revízie receptu (`RecipeService::snapshotRevision`) sa kalkulácia označí
   `stale_at`; detail ukáže „Výpočet je pre staršiu verziu“ s prepočtom (scenár 11). Škálovanie porcií
   (`ServingScaler`) mení iba `per_serving`, nie snímku.
5. **Export**: `ExportService` schéma 3 – kalkulácie s priradeniami; výmaz cez FK kaskádu receptu.
6. **Testy** (`tests/Unit/Nutrition/NutritionCalculatorTest.php`, `tests/Feature/Nutrition/RecipeNutritionTest.php`):
   1 000 kcal / 4 porcie = 250 (scenár 9); bez konečnej hmotnosti nie je 100 g, zmena hmotnosti mení iba
   koncentráciu (scenár 10); chýbajúca hodnota → partial, nie 0 (scenár 8); surová vs. varená ryža (scenár 7);
   editácia receptu → stale (scenár 11); výpočet nevytvorí AI úlohu ani rezerváciu (scenár 15); kalkulácia obsahuje
   zdroj a gramáž každej zložky (scenár 16); člen s právom čítania vidí výsledok, upraviť priradenie môže editor.

### Čo je hotové (kód)

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

### Migrácia a nasadenie

Jedna tabuľka; bez nových `.env` premenných. Kalkulácia pracuje len s uloženými snímkami potravín – bez `app:food-sync` (etapa 9) sú hodnoty
`null` a výsledok je „Čiastočný súčet“ s dôvodom „Zdroj nemá hodnotu“. Mlieko v ml a vajcia v ks potrebujú potvrdený prevod (hustota / ks) v `/admin/food`,
inak si panel vyžiada gramáž.

## Etapa 11 – Rozpoznanie jedla z fotografie

Cieľ: fotka → AI návrh zložiek → používateľ opraví a potvrdí → databázový výpočet z etáp 9/10. AI nikdy nevracia
kcal ani ID potravín; iba kandidátov, stav a otázky. Prvá verzia bez automatického vytvorenia receptu z fotky.

### Čo dnes existuje

- `AiTextService` používa štruktúrovaný výstup (`StructuredAgentResponse`) a `AiJobLifecycle` (rezervácia, `succeed`,
  `fail`, `reconciling`). `laravel/ai` 1.0 má `Laravel\Ai\Files\Image` pre obrazový vstup promptu.
- `ai_jobs.recipe_id` je **NOT NULL** – analýza jedla recept nemá, preto migrácia na nullable (a index).
- `ImageUploadService` validuje uploady receptov (mimes, rozmery); rozšíriť o odstránenie EXIF a zmenšenie.

### Úlohy

1. **Ledger**: `UsageKind::MealAnalysis = 'meal_analysis'`, `AiJobKind::MealAnalysis`, skúšobné 3 analýzy
   raz na overeného používateľa (`recipes.usage.trial.meal_analysis`, kľúč `trial:meal_analysis:user:{id}`, rovnaký
   listener/backfill ako v etape 2), `AiAvailability` a denný limit `RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT` ako
   frekvenčná ochrana (chráni aj neúčtované neúspešné pokusy). Rezervácia pri vytvorení, spotreba až po doručení
   **rozpoznaného** návrhu (`recognized|needs_clarification`); výsledok `not_food|unusable` použitie uvoľní (interný
   náklad ostáva na `ai_jobs`), definitívna chyba uvoľní, nejasný výsledok → `reconciling` (scenár 4).
2. **`MealAnalysis`** (`meal_analyses`): `household_id`, `user_id` (vlastník, nie domácnosť), `ai_job_id`,
   `status` (`uploaded|analyzing|needs_review|confirmed|unusable|discarded`), `note`, `ai_result` (surový
   štruktúrovaný výstup), `ai_status` (`recognized|needs_clarification|not_food|unusable`), `dish_name`,
   `questions`, `limitations`, `clarification_count` (max. 2 doplnenia v relácii bez ďalšieho odpočtu, interný náklad sa
   eviduje na ďalších `ai_jobs` s `parent_ai_job_id`), `photo_retain_until`, `confirmed_at`, `expires_at`.
   Fotka v media kolekcii `photo` na súkromnom disku (`MEDIA_DISK=local`), nikdy verejná URL – servírovanie cez
   podpísanú/policy-chránenú routu.
3. **`MealAnalysisItem`** (`meal_analysis_items`): `label`, `alternatives`, `preparation_state`, `estimated_grams`
   (nullable), `grams`, `grams_origin` (`estimated|confirmed|measured` – `measured` = používateľ uviedol odvážené),
   `portion_basis`, `visible_evidence`, `assumptions`, `food_source_record_id` (nullable, overené serverom),
   `mapping_status`, `included`. Používateľ môže zložku pridať, odobrať, premenovať, označiť „neznáma zložka“.
4. **AI služba** `MealAnalysisService` (vzor `AiTextService`): prompt verzia `meal_analysis_prompt_version`, obrazový
   vstup + poznámka používateľa (bez identity, profilu, zdravotných údajov), JSON schéma podľa kap. 5 dodatku
   (`status`, `dish_name`, `components[]`, `questions[]`, `limitations[]`), validácia výstupu na serveri, `unknown`
   povolené, žiadne pravdepodobnosti v UI. Ak používateľ vyberie existujúci recept, AI sa nevolá a použije sa etapa 10.
5. **Upload a súkromie**: validácia (mimes/veľkosť/rozmer), odstránenie EXIF a polohy, zmenšenie na ≤ 1 536 px,
   pred prvým odoslaním jednorazové vysvetlenie „fotka sa odošle OpenAI“ (uložené ako potvrdenie na používateľovi,
   nie ako GDPR súhlas na iné účely). Príkaz `app:meal-analysis-cleanup` (scheduler denne): pracovné fotky zmazať
   24 h po dokončení/zlyhaní, ak si ich používateľ výslovne neuložil k záznamu; nepotvrdené návrhy zmazať po 7 dňoch;
   TTL sú v `config/recipes.php` (`meal_analysis.photo_ttl_hours`, `draft_ttl_days`).
6. **UI** (`/jedlo/analyza`, Livewire `MealPhotoAnalyzer`): upload/odfotenie, poznámka, stav spracovania, obrazovka
   „Skontroluj jedlo“ (položky, gramáže s odznakom odhad/potvrdené/odvážené, 1–2 otázky iba ak menia výsledok),
   výsledok „Nedokážem určiť“ pre `not_food|unusable` bez akýchkoľvek kcal (scenár 5), možnosť uložiť bez kalórií,
   a tlačidlo „Zjedol som“ (od etapy 12). Zmena fotky = nová analýza s novým odpočtom (jasne oznámené).
7. **Meranie**: `app:ai-measure --meal-analyses=N` s testovacími fotkami z `tests/fixtures/meals/` (vlastné fotky
   prevádzkovateľa, nie dáta používateľov): náklad celej analýzy vrátane doplnení, medián a p95, úspešnosť, počet
   opráv; výsledok pre launch checklist. Skutočné usage z endpointu, nie pevných 5 000 tokenov.
8. **Policy** `MealAnalysisPolicy`: iba `user_id` (vlastník záznamu) číta/upravuje; člen domácnosti cez upravené ID
   dostane 404/403 (scenár 13); admin nemá plošný náhľad – v `/admin/ai` iba metadáta úlohy (stav, tokeny, cena,
   redigovaná chyba), nikdy fotku ani zložky.
9. **Testy** (`tests/Feature/Ai/MealAnalysisTest.php` s fake agentom): spotrebuje `meal_analysis`, nie obrázok ani
   text (scenár 3); dvojklik/retry = jedna rezervácia, zlyhanie uvoľní (scenár 4); `not_food` → žiadne kcal, použitie
   sa uvoľní a interný náklad je na úlohe (scenár 5); AI vrátené `food_id` sa ignoruje, kandidáti sa hľadajú serverom
   (scenár 6);
   fotka má odstránené EXIF a nie je verejne dostupná; cleanup maže po TTL; cudzí používateľ 403 (scenár 13);
   skúšobné 3 analýzy raz na používateľa; kill switch a blokovanie domácnosti platia.

### Čo je hotové (kód)

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

### Migrácia a nasadenie

`php artisan migrate` (ai_jobs + dve tabuľky + users), `.env`: `RECIPES_AI_DAILY_MEAL_ANALYSIS_LIMIT`, `RECIPES_USAGE_TRIAL_MEAL_ANALYSES`,
`RECIPES_MEAL_PHOTO_DISK`, `RECIPES_MEAL_PHOTO_TTL_HOURS`, `RECIPES_MEAL_DRAFT_TTL_DAYS` (všetky s predvoľbami); scheduler musí bežať
(`app:meal-analysis-cleanup`); `app:usage-backfill-trials` pridelí existujúcim overeným vlastníkom 3 analýzy; `AiCostRateSeeder` bez zmeny (gpt-6-luna
sadzby existujú), obrazové vstupné tokeny sa účtujú podľa usage z endpointu. Pred launch checklistom nahrať vlastné fotky do `tests/fixtures/meals/`
a spustiť `app:ai-measure <domácnosť> --text=0 --images=0 --meal-analyses=10 --yes`.

## Etapa 12 – Súkromný denník „Zjedol som“

Cieľ: osobný, voliteľný denník konzumácie s nemennými snímkami výpočtu; oddelený od `CookingEvent` (varenie zostáva
vstupom generátora opakovaní). Bez cieľov, diét, diagnóz, váhy, detských profilov a bez zdieľania v domácnosti.

### Úlohy

1. **`MealConsumption`** (`meal_consumptions`): `user_id` (vlastník denníka – prihlásený dospelý používateľ, nie
   `Person`), `household_id` (kontext), `eaten_at` + `timezone`, `source` (`recipe|analysis|manual`), `recipe_id` /
   `recipe_revision_id` / `nutrition_calculation_id` / `meal_analysis_id` (nullable podľa zdroja), `title_snapshot`,
   `portion_mode` (`fraction|grams|per_component`), `portion_fraction` / `grams`, `note`, `deleted_at`.
2. **`ConsumptionNutritionSnapshot`** (`consumption_nutrition_snapshots`): nemenný výsledok pri uložení (`totals`,
   `components` s gramážou, zdrojom a pôvodom gramáže, `completeness`, `assumptions`, `calculation_version`,
   `revision`); oprava = nová revízia snímky, stará zostáva. Neskoršia zmena receptu, databázy alebo kalkulácie
   historický záznam nemení (scenár 11, admin pravidlo „žiadne plošné prepisovanie“).
3. **Podiel a opravy po zložkách**: polovica porcie rovnomernej zmesi = polovica hodnôt; pri „ryžu nezjedol, mäso
   áno“ režim `per_component` s vlastným podielom na zložku. Prepočet je čistá matematika (`ConsumptionCalculator`),
   nespotrebúva AI a funguje aj po skončení Plus (scenár 15).
4. **Vstupy**: z receptu (vyžaduje existujúcu kalkuláciu; ak je `stale`, ponúknuť prepočet), z potvrdenej analýzy
   (etapa 11), manuálne jedlo (názov + voliteľne ručné hodnoty s uvedeným zdrojom „etiketa/odhad“ – nikdy tiché
   čísla). Uloženie bez kalórií je vždy možné. Potvrdenie uvarenia (`CookedPanel`) **nevytvára** konzumáciu; ponúka
   iba odkaz „Zapísať, čo som zjedol“ pre prihláseného používateľa (scenár 12).
5. **UI** `/dennik` (Livewire `MealDiary`): zoznam po dňoch v lokálnej zóne, denný súčet s označením čiastočných
   súčtov, detail so snímkou (zdroj, gramáže, predpoklady), oprava podielu, mazanie. Bez cieľových kalórií a trendov.
6. **Oprávnenia a súkromie**: `MealConsumptionPolicy` – výlučne `user_id`; domácnosť ani vlastník domácnosti denník
   nevidia (scenár 13); admin dashboard ho neagreguje po jedlách. Export: **export účtu** (`/settings/privacy`,
   nie export domácnosti) dostane `meal_consumptions` + snímky + analýzy + uložené fotky; `AccountErasure` maže
   denník a analýzy používateľa aj keď je iba členom (dnes `purgeHouseholdContent` rieši domácnosť – doplniť vetvu pre
   používateľa), `app:privacy-reapply-erasures` ich zahrnie. Analytika: `AnalyticsEvents` allowlist doplniť najviac
   o `meal_logged` bez vlastností (žiadne jedlá, gramáže, kcal, fotky – scenár 14).
7. **Testy** (`tests/Feature/Diary/MealDiaryTest.php`, `tests/Unit/Diary/ConsumptionCalculatorTest.php`): 250 kcal
   porcia → polovica 125 (scenár 9); oprava po zložkách; snímka sa nemení po editácii receptu ani po `app:food-sync`
   (scenár 11); uvarenie nevytvorí záznam, reštaurácia nevytvorí `CookingEvent` (scenár 12); člen domácnosti 403/404
   na cudzí záznam (scenár 13); export účtu a výmaz zahŕňajú denník a fotky (scenár 14); prepočet bez AI po skončení
   Plus (scenár 15).

### Čo je hotové (kód)

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

### Migrácia a nasadenie

`php artisan migrate` (dve tabuľky); bez nových `.env` premenných, bez zmeny sadzieb, katalógu či právnych textov. Odporúča sa doplniť informácie o súkromí
o účel denníka v etape 13 (text je už v zozname úloh etapy 13).

## Etapa 13 – Ponuka v2.1, admin, právne a Stripe údaje

Cieľ: až po funkčnom overení etáp 8–12 vystaviť platené limity a doplniť právne a obchodné údaje. Etapa má dve časti:
kód (pripravený a testovaný s konfigurovateľnými hodnotami) a vstupy prevádzkovateľa (bez nich sa nič neaktivuje).

### Úlohy – kód

1. **Katalóg**: `plan_versions` doplniť `image_profile_code` (dnes implicitne `image_standard_v1`) a
   `meal_analysis_uses_per_period`; `addon_versions.unit_kind` už je string – nové balíky `meal_analyses_100`
   (návrh 1,99 €) a `images_economy_N` (cena až po teste). `CatalogSeeder` **nevytvára verziu 2 automaticky**;
   administrátor ju založí v `/admin/catalog` („Nová verzia“) s hodnotami po rozhodnutí a aktivuje. Aktivácia
   novej verzie sa týka len nových a obnovených období – `UsageProvisioner` otvára granty podľa verzie plánu
   platnej pre dané obdobie a existujúce granty nemení (scenár 17, test 14 z v2).
2. **Granty**: `UsageProvisioner` otvára `meal_analysis` a `image_economy`/`image_standard` podľa verzie plánu;
   `StripeEventProcessor` udeľuje nové balíky rovnakou cestou (`order:{id}`); `RefundService` odoberá nevyužité
   jednotky nových druhov; `/cennik`, `/settings/usage` a `/settings/billing` ukazujú nové druhy. Po skončení Plus
   zostáva denník čitateľný a opravy dostupné; nová analýza vyžaduje nárok.
3. **Admin**: `/admin/ai` – profily a náklady analýz (medián/p95, úspešnosť, opravy, cena úspešného výsledku);
   `/admin/food` z etapy 9; stav integračných kľúčov (OpenAI, USDA) bez secrets; `LaunchReadiness` doplniť kontroly:
   sadzby pre všetky aktívne profily, meranie analýz, TTL cleanup beží, USDA kľúč, signoff porovnania obrázkov.
4. **Právne**: nová **draft** verzia informácií o súkromí (účel: analýza fotografií, výpočet živín, voliteľný denník,
   príjemca OpenAI, retencia fotiek) cez `LegalDocumentSeeder`/admin – publikuje prevádzkovateľ po kontrole; VOP
   doplniť o nové balíky a upozornenie „nie je medicínske meranie ani záruka alergénov“; register služieb
   (`/admin/services`) doplniť OpenAI ako príjemcu pre nový účel (bez cookies – informačne). Rozšírenie na zdravotné
   ciele/diagnózy je mimo rozsah a vyžaduje samostatné posúdenie.
5. **Stripe / identita (kap. 11)**: `OperatorIdentity` doplniť polia `public_business_name` („Moje recepty“),
   `statement_descriptor` (návrh `MOJE-RECEPTY.SK`, overiť v Stripe), `shortened_descriptor`; `runbook-launch.md`
   doplniť pripravený anglický opis podnikania a poznámku, že rozpoznanie fotky sa do opisu pridá až keď je v ponuke;
   launch signoffs doplniť „Stripe účet aktivovaný (overenie, výplaty)“ a „daňový režim (neplatiteľ / § 7a / platiteľ)“.
6. **Testy**: verzia 2 plánu neudelí nové granty existujúcemu obdobiu (scenár 17); nový balík cez webhook raz;
   refund nových druhov; gating novej analýzy po skončení Plus vs. čitateľnosť denníka; launch checklist blokuje bez
   sadzieb/merania/signoffu; publikovanie právneho textu s placeholderom je odmietnuté (existujúci mechanizmus).

### Čo je hotové (kód)

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

### Vstupy prevádzkovateľa (bez nich sa nič nevystavuje)

- Výsledok porovnania low/medium (etapa 8) a rozhodnutie o profile nového Plus (20 Economy/mesiac, alebo ponechať
  5 Standard).
- Počty obrázkov a analýz v Plus, cena Economy balíka a balíka analýz, zásady pre existujúcich predplatiteľov.
- Potvrdenie USDA ako prvého zdroja; Open Food Facts až po licenčnom posúdení (ODbL, atribúcia).
- Retencia fotiek (24 h / 7 dní) potvrdená podľa infraštruktúry a záloh.
- Právna kontrola a publikovanie novej verzie informácií o súkromí a VOP.
- Stripe: presné obchodné meno zo živnostenského registra, support e-mail, aktivácia účtu, descriptor, daňový režim,
  cieľové krajiny; Stripe Tax nezapínať bez potvrdenia.

## Mapa akceptačných scenárov (kap. 12 dodatku)

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
| 17 nové limity bez migrácie katalógu nepripíšu, nároky sa nezhoršia | 13 | `Billing/CatalogVersion2Test` ✅ |

## Otvorené rozhodnutia (kap. 13) a kde blokujú

| Rozhodnutie | Blokuje | Do rozhodnutia |
|---|---|---|
| Výsledok low/medium a profil nového Plus | etapa 13 (ponuka) | etapa 8 beží s oboma profilmi, predvolený ostáva Standard |
| Počty a ceny (obrázky, analýzy, balíky) | etapa 13 | hodnoty sú v katalógu, verzia 2 sa nezakladá |
| USDA ako prvý zdroj | etapa 9 (implementácia) | plán počíta s USDA; zmena zdroja = iná implementácia `FoodDataSource` |
| Gating výživy receptov (Free vs. Plus) | etapa 10 (UI) | predvolene bez gatingu – výpočet nemá variabilný náklad |
| Retencia fotiek a právny základ nového účelu | etapa 11 (nasadenie), 13 | TTL konfigurovateľné (`RECIPES_MEAL_PHOTO_TTL_HOURS`, `RECIPES_MEAL_DRAFT_TTL_DAYS`), súhlasná obrazovka pred prvým odoslaním je hotová; text informácií o súkromí až v etape 13 |
| Denník len pre dospelého prihláseného používateľa | etapa 12 | MVP presne takto; detské profily a hostia mimo rozsah |
| Identita prevádzkovateľa, admin e-mail, DPH, Stripe aktivácia | etapa 13 a launch (v2 etapa 7) | checkout ostáva vypnutý |
