# Recepty – rodinná aplikácia „Čo dnes navarím?“

Súkromná aplikácia pre jednu domácnosť: knižnica vlastných receptov, chute jednotlivých stravníkov, vážený náhodný výber jedla, plán a história varenia. Voliteľne AI úprava textu receptu a AI ilustrácia jedla (Laravel AI SDK). Zadanie je v `docs/recepty-zadanie-pre-coding-agenta.md`.

Stack: Laravel 13, Livewire 4 (single-file komponenty v `resources/views/pages`), Flux UI (Free + Pro deklarované v `composer.json`), Spatie Media Library, Laravel AI SDK, Pest.

## Spustenie

```bash
composer install            # vyžaduje prístup k composer.fluxui.dev (Flux Pro licencia v auth.json / COMPOSER_AUTH)
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed  # demo domácnosť: demo@example.com / password (fiktívne osoby)
npm install && npm run build
php artisan serve
```

Pri vývoji: `composer dev` (server, fronta, logy a Vite naraz). Ak nie je dostupný `fonts.bunny.net`, build spusti s `VITE_SKIP_FONTS=1 npm run build`.

Kontroly: `composer test` (Pint, PHPStan level 7, Pest). Testy AI mockujú poskytovateľa (`RecipeTextAgent::fake()`, `Image::fake()`); skutočného poskytovateľa treba overiť manuálne po dodaní kľúča.

## Fronta a AI

- AI úlohy bežia vo fronte (`QUEUE_CONNECTION=database` je predvolené): spusti `php artisan queue:work`. V testoch a s `QUEUE_CONNECTION=sync` bežia synchrónne.
- Text aj obrázky idú cez Laravel AI SDK; poskytovateľ a model sa nastavujú v `.env` (`RECIPES_AI_TEXT_PROVIDER`, `RECIPES_AI_IMAGE_PROVIDER`, voliteľne `RECIPES_AI_*_MODEL`). Predvolený je OpenAI (`OPENAI_API_KEY`), SDK podporuje aj Anthropic, Gemini a ďalších (`config/ai.php` sa dá publikovať cez `php artisan vendor:publish --tag=ai-config`).
- Bez kľúča je AI označené ako **nenakonfigurované**; ukladanie receptov a výber jedla fungujú bez AI.
- Limity na domácnosť: `RECIPES_AI_DAILY_TEXT_LIMIT`, `RECIPES_AI_DAILY_IMAGE_LIMIT`, `RECIPES_AI_MAX_CONCURRENT`. Vyčerpanie sa ukáže pred ďalším spustením.
- Každá úloha (`ai_jobs`) ukladá prompt, verziu promptu, poskytovateľa/model, vstupnú revíziu, výstup a chybu. `request_key` bráni duplicitnému platenému spusteniu pri opakovanom requeste; „Vygenerovať ďalší variant“ / „Vygenerovať znova“ je vedome nová úloha.
- Tajné kľúče sú iba na serveri; prehliadač nikdy nevolá poskytovateľa priamo.

## Ako funguje výber jedla

Váhy sú v `config/recipes.php` (`selection`), čistá logika v `app/Services/Selection`:

1. Tvrdé filtre (`CandidateFilter`): archivované, „Neponúkať“ pre ktoréhokoľvek stravníka, typ jedla (nezaradené sa predvolene ponúkajú), „Nemá rád“ (ak nie je povolené), iba obľúbené všetkých, časový limit s explicitným správaním pri neznámom čase, prísne „Neopakovať X dní“.
2. Váha (`CandidateScorer`): `G = 0,6·min + 0,4·priemer` skóre chutí (Obľúbené 2,0 / Zje 1,0 / Nehodnotené 0,9 / Nemá rád 0,15), história `H = R·F·H_other` (faktor posledného varenia, frekvencia za 28 dní, oslabená penalizácia za varenie pre iných), plán `P = 0,4` pri kolízii v T ± 3 dni, `W = max(0,02; G·H·P)`.
3. Relácia (`selection_sessions`) uloží kandidátov a váhy; karta sa žrebuje pomerom `W/ΣW` bez opakovania (`WeightedPicker` s injektovateľnou náhodou), Späť obnoví poslednú preskočenú kartu. Pred zobrazením karty sa znovu overí archivácia a výluky.

Vysvetlenie na karte („Obľúbené pre 2 z 3 • 24 dní sa nevarilo“) vzniká z použitých pravidiel, nie z AI.

## Dátový model a pravidlá

- Účet (`users`, `household_memberships` s rolou owner/editor/member) ≠ stravník (`people`, člen/hosť, voliteľne prepojený na účet).
- Chute (`person_recipe_preferences`) a pevná výluka (`person_recipe_exclusions`) sú oddelené; „Teraz nie“ na karte je iba akcia relácie.
- Plán (`meal_plans`) má práve jeden režim: deň, týždeň bez dňa (pondelok) alebo „Niekedy“. Stav planned/cooked/cancelled.
- História (`cooking_events`) vzniká iba potvrdením; jeden aktívny záznam na plán (`active_plan_key`), idempotencia cez `idempotency_key`, oprava omylu = zneplatnenie (`voided_at`).
- Recept má revízie (`recipe_revisions`, nemenné snímky) a `version` na kontrolu súbežnej úpravy. AI návrh sa aplikuje len ak sa revízia medzičasom nezmenila.
- Obrázky: privátny disk (`MEDIA_DISK=local`), servírované cez `/media/{id}` po overení domácnosti; upload sa prekóduje (oprava orientácie, odstránenie EXIF/GPS), povolené JPG/PNG/WebP do 10 MB a 6000 px. Predchádzajúce hlavné fotky sa dajú obnoviť.
- Dátumy jedál sú `DATE` (cast `DateOnly`), technické časy UTC. Časová zóna domácnosti (predvolene Europe/Bratislava) určuje „dnes“, „zajtra“ a hranice týždňa.

## Zálohy, obnova a export

- Zálohuj `database/database.sqlite` (alebo DB) a `storage/app/private` (médiá). Obnova: obnov oba, potom `php artisan migrate` a `php artisan media-library:regenerate` ak chýbajú náhľady.
- Export ZIP (Nastavenia domácnosti → Export): `export.json` so `schema_version` (recepty, pôvodné texty, revízie, chute, výluky, plány, história) a priečinok `media/` s originálmi; väzby cez ID.

## Rozsah

Hotové (etapy A–E zadania): domácnosť a profily bez prihlasovania, recepty iba názvom aj úplné, médiá, chute a výluky, generátor s reláciou a kartami, plán v troch režimoch, potvrdenie/odvolanie varenia, história a jej vplyv na váhy, AI text s revíziami a konfliktmi, AI obrázok s rozhodovaním o nádobe a schvaľovaním, rodinné účty s pozvánkami a rolami, export, demo dáta, testy akceptačných scenárov.

Odložené (podľa zadania nezačaté): nákupný zoznam, import z webu/fotky, zásoby, automatický týždenný jedálniček, prílohy ako samostatné recepty, zvyšky, verejné zdieľanie, obmedzenia podľa surovín s normalizovaným zoznamom (kap. 11), PWA/offline.

Odchýlky a poznámky:
- Fotografie krokov, ktoré AI návrh zlúči alebo rozdelí, vyžadujú potvrdenie; nepriradené fotky sa po potvrdení presunú na posledný krok.
- Jednorazový hosť sa archivuje po ukončení výberu tlačidlom „Hotovo“.
- Flux Pro komponenty (date-picker, tabs…) zatiaľ nie sú použité; UI je postavené z Free komponentov, Pro zostáva v závislostiach.
