# Moje recepty – dodatok v2.1: lacnejšie obrázky, výživa a dokončenie Stripe

Dátum: 26. 9. 2026
Základ: `moje-recepty-v2-predplatne-admin-pravne.md`.

## 1. Ako použiť tento dodatok

Agent dostane pôvodné zadanie v2 a tento dodatok. V2 zostáva základom pre Cashier, predplatné, ledger, admin, právne stránky a cookies. Tento dokument dopĺňa diskusiu po vytvorení v2 a určuje zmeny v uvedených oblastiach. Nejde o audit aktuálne nasadeného kódu ani potvrdenie, že predplatné už bolo spustené.

Konkrétne nové počty AI použití a modelové profily sú návrhy, nie už zakúpené nároky zákazníkov. Nevytvárať retroaktívne zmeny cien alebo kvality v existujúcich predplatných.

### Stav rozhodnutí

| Oblasť | Stav |
|---|---|
| Laravel Cashier pre Stripe | Používateľ potvrdil |
| Textový model gpt-6-luna | Používateľ potvrdil, že ho používa |
| Generovanie cez gpt-image-2 | Používateľ potvrdil, že ho používa |
| Použitie kvality low | Odporúčaný experiment; kvalita zatiaľ nebola otestovaná |
| FLUX.1 Schnell ako alternatíva | Návrh, nepridávať automaticky nového poskytovateľa |
| Rozpoznanie jedla a kalórie | Nová požadovaná oblasť plánu; rozsah MVP navrhnutý nižšie |
| 20–30 obrázkov alebo 30 analýz mesačne | Návrhy na nacenenie; neboli finálne potvrdené |
| Stripe registrácia | Používateľ ju začal; dokončenie a aktivácia nie sú potvrdené |
| Prevádzkovateľ | Používateľ uviedol existujúcu živnosť, nie kompletné identifikačné údaje |

## 2. Presné zmeny oproti v2

1. Pevný návrh Standard = medium z v2 rozšíriť na verzované profily obrázkov. Pripraviť porovnanie low/medium a až podľa výsledku vybrať profil novej ponuky.
2. Nemení sa aktuálny model obrázkov. Najprv skúsiť lacnejšiu kvalitu rovnakého modelu.
3. Zaviesť samostatnú operáciu `meal_analysis`, ktorá používa obrazový vstup textového modelu. Nie je to generovanie obrázka a nespotrebúva obrázkové jednotky.
4. Pridať výživové údaje k receptom a súkromný záznam „Zjedol som“.
5. Rozšíriť admin o zdroje výživových údajov, opravy priradení a meranie ceny analýzy.
6. Doplniť verejné a právne údaje Stripe podľa kapitoly 11.
7. Rozšíriť privacy/export/výmaz o fotografie jedál a denník. Ostatné pravidlá v2 zostávajú platné.

## 3. Lacnejšie generovanie obrázkov

### Profily

| Interný kód | Model | Kvalita/rozmer | Použitie |
|---|---|---|---|
| image_economy_v1 | gpt-image-2 | low, 1024 × 1024 | Kandidát pre bežné receptové karty |
| image_standard_v1 | gpt-image-2 | medium, 1024 × 1024 | Pôvodný návrh v2 |
| image_high_v1 | gpt-image-2 | high, 1024 × 1024 | Len budúce rozšírenie, nevystavovať automaticky |

Server určuje model, kvalitu, rozmery a počet výstupov. Client nemôže za cenu Economy vynútiť High. Konfigurácia samotná nestačí: kód musí hodnoty naozaj poslať do podporovaného API. Presné názvy existujúcich env premenných zachovať alebo doplniť s dokumentáciou; nevytvoriť premennú, ktorú aplikácia nikde nečíta.

Cenník z oficiálnej dokumentácie overenej počas tejto diskusie 26. 9. 2026: obrazový výstup 1024 × 1024 pri low približne 0,006 USD, medium 0,053 USD, high 0,211 USD. Vstupy sa pripočítajú. Pre 30 low výstupov je teda orientačný súčet 0,18 USD. Nejde o kompletný prevádzkový náklad ani záruku budúcej ceny. Zdroj [S1].

FLUX.1 Schnell cez DeepInfra bol diskutovaný ako alternatíva: pri uvedenom cenníku 0,0005 USD × šírka/1024 × výška/1024 × počet krokov vychádza 1024 × 1024 pri 4 krokoch na 0,002 USD [S2]. Integráciu nenasadzovať len kvôli porovnaniu cenníka; pribudne poskytovateľ, zmluvné podmienky, spracúvanie dát a prevádzková údržba. Použiteľnosť na konkrétne slovenské jedlá nebola overená.

Staršie gpt-image-1-mini a gpt-image-1.5 boli podľa dokumentácie v čase diskusie plánované na vypnutie 1. 12. 2026 [S3]; nový kód na nich nestavať. Pred implementáciou znovu overiť aktuálnu dostupnosť modelov.

### Malé porovnanie pred zmenou ponuky

Rovnaký prompt a 10 jedál, každé 2 varianty low a 2 medium: napr. halušky, paprikáš bez prílohy, guláš, polievka, rezeň s prílohou, rizoto, šalát, cestoviny, praženica, koláč. Náklad testu vopred ukázať; nespúšťať platený test bez príslušného rozpočtu/prístupu.

Hodnotenie: správnosť jedla a prílohy, prirodzenosť, konzistencia, artefakty, použiteľnosť na karte a pri otvorení detailu. Návrh interného kritéria: aspoň 18 z 20 low obrázkov prijateľných a žiadna systematická zámena jedla/prílohy. Malá vzorka je rozhodovací experiment, nie dôkaz všeobecnej presnosti.

Ak low vyhovuje, vytvoriť novú verziu ponuky s Economy profilom. Ak nevyhovuje, ponechať medium a pôvodné limity. Výsledky testu a rozhodnutie zapísať. Zaplatené Standard balíky sa nesmú bez dohody zmeniť na Economy.

## 4. Nová funkcia: výživové hodnoty receptu

Odporúčaná prvá etapa výživového modulu. Využíva existujúce ingrediencie, ich množstvá a počet porcií. Fotografia receptu, najmä AI ilustrácia, sa na výpočet nepoužíva.

### Tok

1. Používateľ otvorí recept → „Vypočítať výživové hodnoty“.
2. Aplikácia navrhne priradenie ingrediencií k databázovým potravinám.
3. Používateľ potvrdí nejednoznačné priradenia, množstvá a stav suroviny (surová/uvarená).
4. Aplikácia vypočíta kcal, bielkoviny, sacharidy a tuky; vlákninu môže zobraziť, ak sú údaje dostupné.
5. Ukáže výpočet pre celý recept a porciu. Na 100 g hotového jedla len pri známej konečnej jedlej hmotnosti.

Vzorec pre každú zložku a živinu:

`hodnota zložky = jedlá hmotnosť v gramoch / 100 × hodnota na 100 g`

Celý recept je súčet zahrnutých zložiek. Na jednu rovnomernú porciu deliť počtom porcií. Pri odváženej časti hotového pokrmu použiť pomer hmotnosti zjedenej časti ku konečnej hmotnosti pokrmu, iba ak ide o primerane rovnomernú zmes.

### Pravidlá správnosti

- Surovú ryžu nemožno priradiť k uvarenej pri rovnakej gramáži. Uchovať food preparation state a zdroj.
- Kusy a objem previesť len cez potvrdenú gramáž jednotky/hustotu konkrétnej potraviny. Žiadny všeobecný predpoklad 1 ml = 1 g.
- Varením sa mení voda a hmotnosť; neodvodzovať hmotnosť hotového jedla jednoduchým súčtom surových množstiev.
- Olej na panvici, výpek alebo scedená tekutina nemusia byť zjedené celé. Ak to používateľ nevie, jasne uviesť predpoklad; nenastaviť skrytú presnú absorpciu tuku.
- Chýbajúca ingrediencia alebo hodnota znamená neúplný výpočet, nie nulu. Výsledok s významnými chýbajúcimi údajmi označiť „Čiastočný súčet“; neprezentovať ako kompletné kcal.
- „Podľa chuti“ môže pri soli neovplyvniť energiu, ale pri oleji alebo cukre áno. Chýbajúce energeticky významné množstvá vyžiadať.
- Energiu primárne prevziať z vhodného databázového údaja; jednoduché 4/4/9 nemusí zodpovedať údajom pri vláknine či alkohole. Nezamieňať kJ s kcal.
- Po zmene receptu označiť starý výpočet ako neaktuálny. Historicky zjedené porcie zostanú na pôvodnej snímke výpočtu.
- Zobrazenie zaokrúhliť rozumne; 647,238 kcal nie je dôveryhodnejšie než približne 650 kcal.

## 5. Nová funkcia: rozpoznanie jedla z fotografie

Použiť gpt-6-luna s obrazovým vstupom; jeho podpora obrazových vstupov bola potvrdená v dokumentácii [S4]. gpt-image-2 slúži na tvorbu obrázkov, nie tento tok.

### Používateľský tok

1. Tlačidlo „Odfotiť jedlo“ alebo výber fotografie.
2. Voliteľne vlastná poznámka: „kuracie na smotane“, názov reštaurácie netreba.
3. AI vráti návrh viditeľných zložiek a prípadné alternatívy, nie hotový autoritatívny zápis do denníka.
4. Obrazovka „Skontroluj jedlo“: upraviť názvy, pridať/odobrať zložku, potvrdiť gramáž alebo označiť odhad.
5. Pri dôležitej neistote jedna až dve otázky: druh omáčky, spôsob prípravy, príloha, približná porcia.
6. Databázové priradenie a výpočet po potvrdení údajov.
7. „Zjedol som“: dátum/čas, profil, zjedený podiel alebo gramáž a uloženie.

Pri bežnom jedle krátky tok; otázky iba vtedy, ak menia výsledok. Možnosť uložiť jedlo aj bez kalórií musí zostať dostupná.

### Čo AI smie a nesmie tvrdiť

AI identifikuje kandidátov podľa fotky. Gramáž, skrytý tuk, cukor či náplň nie sú z jedinej fotografie spoľahlivo merané. Návrh množstva musí niesť príznak `estimated`. Označenie „potvrdené používateľom“ nie je laboratórne overenie.

Model môže vrátiť `unknown`, `needs_clarification` alebo niekoľko kandidátov. Sebahodnotenie modelu neprezentovať ako kalibrovanú pravdepodobnosť „97 % presnosť“. Fotka bez jedla, nepriehľadná nádoba a výrazne rozmazaný obrázok majú jasný výsledok „Nedokážem určiť“.

Neodvodzovať alergénovú bezpečnosť alebo zdravotné odporúčanie z fotografie. Nevypočítavať kalórie z generovanej ilustračnej fotky receptu. Ak používateľ vyberie už známy recept, uprednostniť jeho ingrediencie a výživový profil.

### Návrh štruktúrovaného výstupu

- `status`: recognized / needs_clarification / not_food / unusable.
- `dish_name`, `components[]`.
- Component: `label`, `alternatives[]`, `preparation_state`, `estimated_grams` nullable, `portion_basis`, `visible_evidence` a `assumptions[]`.
- `questions[]`, `limitations[]`.

Model nesmie vymýšľať databázové ID. Vráti vyhľadávací návrh, server vyhľadá skutočných kandidátov a overí ich existenciu. Výživové hodnoty doplní databázový modul. Ak zhoda chýba, možnosť manuálneho zadania s uvedením zdroja alebo záznam bez výpočtu; žiadny tichý návrat k vymysleným číslam AI.

Ak sa zobrazí rozsah kcal, musí vychádzať z explicitného rozsahu porcií a zloženia. Nepridávať univerzálne ±20 % ani nevymýšľať štatistický interval spoľahlivosti. V MVP stačí odhad s pomenovanými predpokladmi.

## 6. Databázy potravín

| Zdroj | Návrh použitia | Podmienka |
|---|---|---|
| USDA FoodData Central | Prvý zdroj všeobecných surovín | API kľúč, cache, slovenské synonymá, stav suroviny |
| Open Food Facts | Neskôr balené produkty a čiarové kódy | Skontrolovať licenciu ODbL, atribúciu, pravidlá API a úplnosť dát |
| KalorickéTabuľky.sk/.cz | Prípadné budúce lokálne partnerstvo | API a komerčná cena neboli potvrdené; integrácia až po dohode |
| Manuálne údaje z etikety | Oprava alebo chýbajúca potravina | Uložiť zdroj, jednotku a autora; nezamieňať s overenou databázou |

USDA údaje sú podľa API dokumentácie CC0 a bežný limit API je 1 000 požiadaviek/hodinu/IP [S5]. Prevádzka preto môže začať bez nákupu komerčnej databázy, ale implementácia, cache a kontrola dát majú vlastné náklady.

Na začiatok zvoliť jeden zdroj, nie hromadné zlúčenie rôznych databáz. Pri Open Food Facts posúdiť povinnosti pri odvodenom alebo kombinovanom súbore dát a licencie obrázkov samostatne; neoznačovať ho ako CC0. Web Kalorických tabuliek nescrapovať ako náhradu licencie.

Každý údaj má source provider, source food ID, dátum načítania, základ 100 g/100 ml/porcia, jednotku, názov a prípravu. Rozlišovať sacharidy a vlákninu podľa definícií zdroja; neporovnávať či nemiešať rozdielne metodiky bez normalizácie. Začať malým ručne skontrolovaným slovníkom bežných surovín SK/CZ.

## 7. „Uvaril som“ a „Zjedol som“

CookingEvent z pôvodného plánu zostáva udalosťou varenia a vstupom generátora opakovania. Nový MealConsumption je udalosťou konzumácie. Jedno varenie môže viesť k viacerým záznamom konzumácie a zvyškom v ďalší deň.

Potvrdenie uvarenia nesmie automaticky pridať kalórie všetkým stravníkom. Fotka jedla nevytvorí udalosť varenia. Používateľ môže pridať konzumáciu z existujúceho receptu, fotografie alebo manuálneho jedla.

Záznam obsahuje skutočne zjedený podiel, nie len veľkosť naservírovaného taniera. Polovica porcie sa pri rovnomernom zložení počíta ako polovica; pri nezjedenej ryži, ale zjedenom mäse je potrebná oprava po zložkách.

Denník je voliteľný a súkromný. V prvom vydaní bez cieľových kalórií, redukčných plánov, zdravotných diagnóz, váhových trendov a automatického sledovania detí. História plánovania varenia môže ostať spoločná, no osobný jedálenský denník nie je automaticky viditeľný celej domácnosti.

## 8. AI limity a ekonomika

Rozšíriť ledger z v2 o samostatnú jednotku `meal_analysis`. Economy a Standard obrázky rozlíšiť profilom grantu, prípadne samostatným kind, aby nárok na medium nebol spotrebovaný na low bez vedomej voľby.

| Ponuka | Východiskový návrh v2 | Kandidát po úspešnom teste |
|---|---|---|
| Plus texty | 30/mesiac | Bez zmeny |
| Plus obrázky | 5 Standard/mesiac | 20 Economy/mesiac; 30 až po ekonomickom vyhodnotení |
| Plus analýzy jedla | Neboli zahrnuté | 30/mesiac |
| Dokúpené Standard obrázky | 20 za 3,99 € | Zachovať profil a existujúce nároky |
| Economy balík | Neexistuje | Naceniť až po teste; nevytvárať nákup s neurčenou cenou |
| Analýzy navyše | Neexistuje | Návrh 100 za 1,99 €, potvrdiť pred predajom |

Free môže dostať 3 skúšobné analýzy jedla celkovo, oddelene od 3 textových použití. Nie pri každej novej domácnosti. Po skončení Plus ostáva denník čitateľný/exportovateľný a opravy existujúcich množstiev dostupné. Nové platené AI analýzy vyžadujú nárok alebo balík. Staré záznamy nezamknúť.

Jedna analýza = jedna fotka a jeden úspešne doručený rozpoznaný návrh. V rámci tej istej relácie zahrnúť najviac dve krátke doplnenia s AI bez ďalšieho zákazníckeho odpočtu; interné náklady evidovať. Zmena fotky alebo vedomé nové rozpoznanie je nová analýza. Ručné úpravy a matematický prepočet nespotrebúvajú AI. Rate limiting chráni aj neúčtované neúspešné pokusy.

Pôvodný odhad 0,0015 USD za analýzu predpokladal 5 000 vstupných tokenov vrátane obrázka a 2 000 účtovaných výstupných tokenov pri sadzbách gpt-6-luna 0,10/0,50 USD za milión [S4]. Je to ilustračný výpočet, nie nameraná spotreba. Nezahŕňa ďalšie otázky, databázu, storage, retry a dane. Obrazové vstupy účtovať podľa reálnej modality a usage z použitého endpointu, nepovažovať každú fotku za pevne 5 000 tokenov.

Merať náklad celej analýzy vrátane interných callov, medián a p95, úspešnosť, počet opráv a cenu úspešného výsledku. Volanie urobiť asynchrónne s rovnakou rezerváciou/idempotenciou ako vo v2. Nezavádzať produkčný cenový sľub iba z modelového výpočtu.

## 9. Dátový model a admin

Rozšíriť existujúce entity, nevytvárať paralelnú AI infraštruktúru.

| Entita | Účel |
|---|---|
| FoodSourceRecord | Externé ID, zdroj/licencia, názov, preparation state, hodnoty/jednotky, source snapshot |
| IngredientFoodMapping | Väzba ingrediencie na zdroj, potvrdenie, conversion factor a pôvod gramáže |
| NutritionCalculation | Revízia receptu, výsledky, kompletnosť, konečná hmotnosť, assumptions, verzia výpočtu |
| MealAnalysis | Household + vlastník, photo ID, AI job ID, stav, rozpoznané položky a opravy |
| MealAnalysisItem | Viditeľná zložka, kandidáti, food mapping, gramáž, estimated/confirmed/measured |
| MealConsumption | Vlastník denníka, dátum/čas, lokálna zóna, zdroj recept/analysis/manual, zjedený podiel |
| ConsumptionNutritionSnapshot | Nemenné pôvodné údaje výpočtu pri uložení; opravy cez novú revíziu |

AI odhady a potvrdené údaje musia byť rozlíšiteľné. `measured` znamená, že používateľ uviedol odvážené množstvo, nie že ho aplikácia sama zmerala.

Admin doplniť o profily low/medium, náklady analýz, stav integračných kľúčov bez zobrazenia secrets, neúspešné food mappings a kurátorstvo všeobecných surovín. Súčasťou auditu je zmena gramáže alebo výživových údajov v zdieľanom katalógu. Žiadne automatické plošné prepisovanie historických osobných záznamov pri aktualizácii databázy.

Dashboard nezobrazuje konkrétne osobné jedlá, fotografie alebo kalorický denník všetkých ľudí. Technický debugging pracuje primárne s redigovanými metadátami.

## 10. Súkromie a právne doplnenie

Doplniť dokumenty a skutočné procesy z v2 o účel analýzy fotografií, výpočtu živín a voliteľného jedálenského denníka. Výživové funkcie nesmú byť prezentované ako medicínske meranie. Nemožno garantovať presnosť kalórií ani neprítomnosť alergénov podľa fotky.

Jedálenský denník môže v kontexte odhaľovať zdravotné údaje. Pred rozšírením na zdravotné ciele, diagnózy či dietetické odporúčania osobitne posúdiť právny základ a prípadný režim osobitnej kategórie. Samotný všeobecný súhlas s VOP to nerieši. V tejto etape nezavádzať kalórie pre detské profily ani osobný denník hostí bez ich vlastného nastavenia prístupu.

Technické návrhy:
- Pred prvým odoslaním fotky vysvetliť jej odoslanie OpenAI; produktové potvrdenie nie je automaticky GDPR súhlas pre každý účel.
- Odstrániť EXIF/polohu, primerane zmenšiť fotku, validovať upload a používať súkromné storage.
- Do AI posielať jedlo a potrebné poznámky, nie identitu rodiny alebo zdravotný profil.
- Výživovej databáze posielať všeobecný názov/ID potraviny; neposielať používateľskú fotografiu alebo celý denník.
- Návrh: pracovnú fotografiu odstrániť do 24 hodín po dokončení/neúspechu, ak si ju používateľ výslovne neuloží k záznamu; dlhšie spracúvané úlohy majú samostatný TTL a cleanup. Retenciu potvrdiť podľa infraštruktúry a zosúladiť so zálohami.
- Neprijaté návrhy uchovať napr. 7 dní na dokončenie, potom zmazať; zverejniť až po zavedení procesu.
- Zahrnúť nové dáta do exportu a výmazu. Domácnosť neudeľuje automatický prístup k osobnému denníku.
- Neposielať jedlá, gramáže, kcal, fotografie ani zdravotné poznámky do marketingovej analytiky/session replay.

## 11. Stripe a podnikateľské údaje z ďalšej diskusie

Používateľ uviedol tieto existujúce predmety podnikania od 1. 9. 2017:
1. Počítačové služby a služby súvisiace s počítačovým spracovaním údajov.
2. Kúpa tovaru na účely jeho predaja konečnému spotrebiteľovi (maloobchod) alebo iným prevádzkovateľom živnosti (veľkoobchod).

V diskusii bolo vyhodnotené, že opísaná aplikácia zodpovedá IT službám a nevidíme potrebu pridávať predmet iba kvôli predplatnému/AI. Nejde o rozhodnutie živnostenského úradu. Z týchto údajov neodvodzovať IČO, obchodné meno ani registráciu DPH. S. r. o. nebola požadovaná; Stripe slovenské podmienky pripúšťajú živnostníkov [S7].

### Navrhnuté vyplnenie Stripe

| Pole | Hodnota/pravidlo |
|---|---|
| Legal/Registered business name | Presné meno zo živnostenského registra, ešte treba doplniť |
| Public business name / značka | Moje recepty |
| Website | https://moje-recepty.sk |
| Category | Software – zvolená na screenshote registrácie |
| Statement descriptor | MOJE-RECEPTY.SK – návrh, overiť akceptovanie Stripe |
| Shortened descriptor | MOJERECEPT – voliteľný návrh |
| Support email | Skutočný fungujúci kontakt; zatiaľ nebol uvedený |

Oficiálny dodávateľ na dokladoch a vo VOP nie je nahradený samotnou značkou. Stripe rozlišuje verejné údaje a identitu na overenie [S8]. Navrhnutý descriptor nie je potvrdením jeho uloženia v účte a banky môžu názov zobrazovať vlastným spôsobom.

Opis pripravený pre registráciu:

> Moje recepty (moje-recepty.sk) is a web application for saving and organizing personal recipes, managing family food preferences, and planning meals. We offer monthly and annual subscriptions for premium features, including AI-assisted recipe text editing and food image generation. Customers can also purchase one-time packages for additional AI usage. The service is delivered entirely online.

Opis doplniť o food photo recognition až keď bude súčasťou reálnej ponuky. Neprezentovať aplikáciu ako zdravotnícku alebo profesionálnu výživovú službu.

Setup guide nie je povinnosť aktivovať všetky doplnkové Stripe produkty. Dokončiť požadované overenie, výplaty a aktiváciu; test integrácie môže prebiehať pred live režimom. Stripe Tax nezapnúť bez potvrdenia daňového režimu. Nepovažovať úhradu Stripe poplatkov za vybavenie všetkých daňových povinností.

Otvorené ostáva: neplatiteľ / § 7a / platiteľ, cieľové krajiny, nákup zahraničných služieb a fakturačný proces. Na tieto otázky používateľ zatiaľ neodpovedal. Pravidlá v2 o daňovej a právnej finalizácii nemeníme.

## 12. Etapy a akceptácia

### Odporúčané poradie

A. Dokončiť platobný základ v2 a súčasne pridať image profiles + meranie. Lacnejšie obrázky nemusia čakať na výživový modul.

B. Výživové údaje vlastných receptov: jeden zdroj dát, gramáže, potvrdenie priradení, výpočet a jeho revízie.

C. Rozpoznanie fotky: návrh zložiek, oprava a potvrdenie, databázový výpočet. V prvej verzii bez automatického vytvorenia receptu z fotografie.

D. Súkromný denník „Zjedol som“, nové granty a rozšírenie admin/privacy. Platené funkcie vystaviť až po funkčnom overení.

### Akceptačné scenáre

1. Klient nemôže vynútiť drahší image profil; historický Standard grant zostáva Standard.
2. Zmena low/medium nemení zachované originály obrázkov ani pravidlá servírovania.
3. Analýza fotografie spotrebuje meal_analysis, nie image generation ani ďalší textový kredit.
4. Dvojklik/retry vytvorí jeden odpočet. Nedodaná technicky zlyhaná analýza uvoľní rezerváciu.
5. Fotka bez jedla nezíska vymyslené kcal. Pri nejasnej omáčke je možnosť neznámej zložky.
6. AI nemôže vložiť neexistujúce food ID ani obísť kontrolu domácnosti/vlastníka.
7. 100 g surovej a 100 g uvarenej ryže použije zodpovedajúce rozdielne zdrojové záznamy.
8. Lyžica oleja bez potvrdeného prevodu nezíska skrytú univerzálnu gramáž. Chýbajúce hodnoty nie sú nuly.
9. Recept s 1 000 kcal rozdelený na 4 rovnaké porcie dá 250 kcal; zjedená polovica takej porcie 125 kcal. Ide o testovací vstup, nie databázové tvrdenie.
10. Výpočet na 100 g hotového jedla nie je dostupný bez konečnej hmotnosti. Zmena hmotnosti zachová rozlíšenie energie receptu a koncentrácie na 100 g.
11. Po editácii receptu sa jeho výpočet označí neaktuálny, historická konzumácia sa bez vedomej opravy nemení.
12. Uvarenie nevytvorí automatický kalorický zápis; konzumácia z reštaurácie nevytvorí udalosť varenia.
13. Rodinný člen nemôže čítať osobný denník druhého cez upravené ID. Admin nemá štandardný plošný náhľad.
14. Fotografia sa neodosiela analytike; cleanup a výmaz/export zahŕňajú nové entity a médiá.
15. Samostatná matematická oprava porcie nespotrebúva AI a funguje aj po skončení Plus.
16. Kalkulácia zdokumentuje zdroj, hmotnosť a odhady; nevystupuje ako medicínsky presné meranie.
17. Nové limity sa nepripíšu existujúcim zákazníkom bez schválenej migrácie katalógu; pôvodné zakúpené nároky sa nezhoršia.

## 13. Otvorené rozhodnutia

Pred implementáciou netreba zastaviť kvôli všetkým otázkam. Pripraviť konfigurovateľné riešenie a demo/mock testy. Pred komerčným nasadením potvrdiť:
- Výsledok porovnania low/medium a profil nového Plus.
- Počet obrázkov a analýz, cena doplnkov a zásady existujúcich predplatiteľov.
- USDA ako prvý zdroj; Open Food Facts až po licenčnom posúdení.
- Súkromný denník pre dospelého prihláseného používateľa ako MVP.
- Retenciu fotiek a právne doplnenie pre nový účel.
- Kompletnú identitu prevádzkovateľa, admin email, DPH a potvrdenie aktivácie Stripe.

## 14. Zdroje z diskusie a pokyn agentovi

Ceny sú snapshot overený v tejto konverzácii 26. 9. 2026. Pred platenými testami a nasadením overiť aktuálnu dokumentáciu. Presnosť rozpoznávania a low obrázkov nebola v tejto konverzácii meraná.

- [S1: OpenAI Image generation](https://developers.openai.com/api/docs/guides/image-generation)
- [S2: DeepInfra FLUX.1 Schnell](https://deepinfra.com/black-forest-labs/FLUX-1-schnell)
- [S3: OpenAI Deprecations](https://developers.openai.com/api/docs/deprecations)
- [S4: GPT-6 Luna](https://developers.openai.com/api/docs/models/gpt-6-luna) a [Images and vision](https://developers.openai.com/api/docs/guides/images-vision)
- [S5: USDA FoodData Central API](https://fdc.nal.usda.gov/api-guide/)
- [S6: Open Food Facts API](https://openfoodfacts.github.io/openfoodfacts-server/api/)
- [S7: Stripe Services Agreement Slovakia](https://stripe.com/legal/ssa/sk)
- [S8: Stripe account setup](https://docs.stripe.com/get-started/account/set-up) a [Statement descriptors](https://docs.stripe.com/get-started/account/statement-descriptors)

**Pokyn coding agentovi:** Aplikuj tento dodatok spolu s v2. Najprv skontroluj, čo už existuje. Zachovaj Cashier, ledger a oprávnenia. Implementuj image profiles, potom výživu vlastných receptov a až následne fotku a denník. Každé zobrazené kcal musí mať dohľadateľný zdroj a množstvo; odhad viditeľne odlíš od nameraného vstupu. Netvrď, že boli zmerané náklady alebo kvalita, pokiaľ si test nevykonal. Neaktivuj nový platený limit ani právny dokument s nevyplnenými údajmi. Odovzdaj implementáciu, testy, migračný postup a krátky zoznam zostávajúcich rozhodnutí.
