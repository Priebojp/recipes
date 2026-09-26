# Moje-recepty.sk – v2: monetizácia, Cashier, AI, administrácia a právne stránky

Implementačné zadanie pre coding agenta • 26. 9. 2026

## 1. Kontext a záväzné rozhodnutia

Existujúca Laravel aplikácia je nasadená na https://moje-recepty.sk/. Toto je nadväzujúce zadanie, nie pokyn aplikáciu vytvoriť znova. Aktuálny kód, verzie, schému a nasadené funkcie treba preskúmať v repozitári; v tejto príprave neboli auditované.

Používateľ potvrdil:
- Textový model: `RECIPES_AI_TEXT_MODEL=gpt-6-luna`.
- Obrázkový model: `RECIPES_AI_IMAGE_MODEL=gpt-image-2`.
- Stripe integráciu cez Laravel Cashier.
- Malé predplatné, limity AI, doplnkové balíky, administrátorský prístup.
- VOP, cookies, informácie o spracúvaní osobných údajov a budúcu analytiku.

Ceny, počty použití, expirácie, pravidlá refundácií a výber analytiky nižšie sú odporúčané produktové nastavenia. Agent ich implementuje ako verzovaný konfigurovateľný katalóg. Pred prijímaním platieb ich musí prevádzkovateľ potvrdiť spolu s právnymi a daňovými údajmi.

Použiť existujúci stack a autentifikáciu. Nenahrádzať modely inými, automaticky neaktualizovať hlavné verzie Laravelu a neprerábať funkčný receptový modul.

## 2. Obchodný model

Predáva sa pohodlnejšie plánovanie varenia pre domácnosť. AI je doplnok, nie jediný dôvod pravidelne platiť. Existujúce recepty, fotografie a história nesmú byť po skončení predplatného neprístupné.

### Navrhnutý verejný cenník

| Funkcia | Free | Plus |
|---|---|---|
| Cena | 0 € | 2,49 € mesačne alebo 24 € ročne |
| Vlastné recepty, vlastné fotografie | Áno | Áno |
| Rodinné profily, chute, náhodný výber | Áno | Áno |
| Ručný plán a história | Áno | Áno |
| Export vlastných dát | Áno | Áno |
| AI textové operácie | 3 skúšobné celkovo | 30 za mesačné obdobie |
| AI obrázky v kvalite Standard | 1 skúšobný celkovo | 5 za mesačné obdobie |
| Návrh týždenného jedálnička | Nie | Áno |
| Nákupný zoznam z plánu | Nie | Áno |
| Uložené skupiny a filtre výberu | Nie | Áno |

Cena je za domácnosť, nie za človeka. Uvádzať konečnú cenu pre spotrebiteľa podľa daňového režimu prevádzkovateľa; nepredpokladať automaticky, že je platiteľ DPH. Pri ročnej variante jasne: „24 € účtovaných raz ročne; zodpovedá 2 € mesačne“. Zobraziť automatické obnovovanie a ďalší termín platby.

Plus nemá automaticky neobmedzené úložisko. Interný technický limit fotografie, počet uploadov za minútu a kvóta úložiska sú oddelené od AI; používateľské limity musia byť viditeľné, ak obmedzujú bežné používanie. Nevymýšľať spätne skryté limity.

Premium funkcie propagovať len po ich skutočnom dokončení. Ak už niektorá funguje zdarma, jej presun do plateného balíka vyžaduje vedomé rozhodnutie a oznámenie existujúcim používateľom. Žiadne mazanie existujúcich dát ani tiché spätné účtovanie.

### Doplnkové balíky – jednorazové, bez obnovovania

| Kód | Obsah | Navrhnutá cena | Dostupnosť |
|---|---|---:|---|
| images_20_standard | 20 obrázkov Standard | 3,99 € | Free aj Plus |
| text_100 | 100 textových operácií | 1,99 € | Free aj Plus |
| import_starter | Neskôr 30 importov receptu | Určiť až po meraní | Neaktivovať v prvej verzii |
| images_high | Neskôr samostatné HQ obrázky | Určiť podľa nákladov | V prvej verzii skryté |

Kúpa balíka nezakladá Plus. Zakúpené použitia možno čerpať aj bez aktívneho predplatného. Odporúčanie: zakúpené balíky bez kalendárnej expirácie počas prevádzky služby; zánik služby, refundácie a zrušenie účtu vyriešiť vo VOP. Nesmú sa prezentovať ako prísľub večnej prevádzky. Žiadna automatická ďalšia kúpa pri vyčerpaní.

Mesačné zahrnuté použitia sa neprenášajú. Poradie spotreby: použitia s najbližšou expiráciou (skúšobné/mesačné), potom najstaršie zakúpené. Skúšobný grant je iba raz na overeného používateľa a raz na domácnosť, aby tvorba domácností neobnovovala skúšku. Nevyužité skúšobné použitia pri prechode na Plus ponechať, nevytvárať nové.

Textový balík implementovať, no nemusí mať výraznú marketingovú pozíciu. Pri týchto cenách môže byť jednoduchšie ponúknuť Plus. Začať jedným plateným plánom, nie tromi podobnými úrovňami.

## 3. AI náklady a nastavenie kvality

Oficiálna dokumentácia načítaná 26. 9. 2026 uvádza pre gpt-6-luna základnú cenu 0,10 USD za milión vstupných a 0,50 USD za milión výstupných tokenov [S1]. Nezamieňať viditeľný text s celým účtovaným výstupom vrátane reasoning podľa použitého endpointu. Meranie usage je autoritatívne.

Ilustračná textová operácia s 3 000 vstupnými a 2 000 celkovými účtovanými výstupnými tokenmi stojí približne 0,0013 USD, teda 30 takých operácií 0,039 USD. Je to výpočet predpokladanej veľkosti, nie garantovaná cena skutočných receptov.

Pre gpt-image-2 oficiálny sprievodca uvádza nasledujúci odhad obrazového výstupu, ku ktorému sa pripočítajú vstupy [S2]:

| Rozmer | Low | Medium | High |
|---|---:|---:|---:|
| 1024 × 1024 | 0,006 USD | 0,053 USD | 0,211 USD |
| 1536 × 1024 | 0,005 USD | 0,041 USD | 0,165 USD |

Pre launch odporúčam profil **Standard = medium, 1024 × 1024, jeden obrázok**. Na karte použiť zobrazenie/orez bez deformácie; zachovať originál. Používateľ nemení kvalitu ani rozmery cez klientský payload. Pred nasadením porovnať reprezentatívne jedlá; podporu a parametre overiť pre existujúci SDK. Nenastavovať kvalitu `auto` tam, kde má byť predvídateľný náklad. HQ sa nesmie potichu zapnúť za jednu Standard jednotku.

Päť Standard výstupov predstavuje približne 0,265 USD plus vstupy. Dvadsať približne 1,06 USD plus vstupy. Zahrnúť aj neúspešné platené pokusy a opakovania poskytovateľa. Cena v USD nie je totožná s EUR nákladom.

### Ekonomika predplatného

Stripe cenník pre štandardné EHP karty: 1,5 % + 0,25 €; Billing pay-as-you-go 0,7 % [S3, S4]. Orientačne:
- 2,49 € mesačne: Payments + Billing asi 0,305 € na platbu.
- 24 € ročne: asi 0,778 € za ročnú platbu.
- Jednorazový balík 3,99 €: Payments asi 0,310 €; nejde automaticky o Billing predplatné.

Ďalšie Stripe služby, kurzové rozdiely, prípadné dane na poplatkoch a obchodníkov daňový režim nie sú v týchto výpočtoch. Výnos bez DPH, ak sa DPH uplatňuje, počítať z konečnej ceny podľa relevantnej sadzby, nie odčítaním percenta z hrubej sumy.

Navrhnutý interný cieľ: AI do 0,50 € mesačne na plne využitú Plus domácnosť pri Standard kvalite. Je to kontrolný cieľ, nie limit, ktorým sa potichu skráti zaplatený balík. Ak meranie nevychádza, upraviť ponuku pre nové nákupy pred launchom. Existujúce zaplatené obdobia dodržať.

Zmerať aspoň 30 rôznych textových a 30 obrazových úloh na schválenom testovacom rozpočte; časť nejasných receptov a retry scenárov. Bez API kľúča pripraviť mock testy a merací príkaz, nevymýšľať výsledky. Finálnu cenu profilov aktualizovať podľa aktuálneho cenníka a skutočného usage.

## 4. Čo sa počíta ako jedno použitie

Textová operácia = jeden úspešne doručený návrh pre jeden recept, napr. úprava textu alebo explicitne vyžiadané doplnenie. Automatické interné formátovanie promptu k zaplatenému obrázku nie je druhý zákaznícky odpočet, jeho náklad však evidovať.

Obrázková operácia = jeden úspešne doručený Standard obrázok. Nový variant na požiadanie spotrebuje ďalšie použitie. Neprijatie úspešne doručeného obrázka automaticky nevracia použitie. Technická chyba, prázdny výsledok alebo odmietnutie bez doručeného výstupu použitie uvoľní; opakované zneužívanie rieši rate limit.

Pri úprave textu zachovať originál a schválenie návrhu. Pri „doplniť recept“ označiť nové domyslené informácie. Nemení sa pravidlo, že AI obrázok je ilustrácia a nie dôkaz zloženia jedla.

### Transakčné účtovanie

1. Overiť domácnosť, oprávnenie, dostupný zostatok, limity frekvencie a serverový profil AI.
2. V databázovej transakcii a pod zámkom rezervovať jednotku zo zvoleného grantu; vytvoriť idempotentnú úlohu.
3. Queue worker vykoná externú požiadavku mimo DB transakcie.
4. Po bezpečnom uložení a sprístupnení výsledku rezerváciu raz spotrebuje. Pri definitívnej chybe ju raz uvoľní.
5. Nejasný timeout dať do `reconciling`, nie okamžite znova volať platenú službu. Ak poskytovateľ neumožní obnoviť výsledok, po rozhodnutí označiť zlyhanie a účtovať prípadný externý náklad prevádzkovateľovi.
6. Používateľské request_key a interné job UUID zabránia dvojklikom. Nesľubovať presne-jeden externý request cez sieť, ak ho poskytovateľ negarantuje.

Expirácia grantu počas už rezervovanej úlohy nezruší oprávnené dokončenie. Pri neskorom zlyhaní expirovaného mesačného grantu neprenášať starú jednotku do nového mesiaca automaticky; prípadnú kompenzáciu vystaviť ako samostatný auditovaný grant.

## 5. Laravel Cashier a Stripe

Cashier je integračný základ pre predplatné, Stripe zákazníka, faktúry a billing portal [S5]. Doménová logika nárokov, balíkov a AI zostatkov zostane v aplikácii. Stripe customer balance nepoužívať ako AI kreditovú peňaženku.

### Fakturačný vlastník

Preferované: samostatný `BillingAccount` viazaný 1:1 na household, implementujúci Cashier Billable a zaregistrovaný ako customer model. Alternatívne existujúci Household, ak to kód podporuje. Upravovať Cashier migrácie a väzby podľa zvolenej kompatibilnej verzie, nie kopírovaním predpokladu user_id.

Vlastník domácnosti spravuje fakturáciu; člen nepozná celé fakturačné údaje a nemôže otvoriť cudzí portal. Fakturačný zákazník sa pri odchode používateľa nemení automaticky. Prenos vlastníctva musí zachovať účet a vyžiadať oprávnené potvrdenie.

### Stripe katalóg

- Produkt Plus: recurring Price mesačne a ročne.
- Jednorazové produkty/prices: images_20_standard a text_100.
- Ceny a Stripe IDs mapovať na serverové verzie katalógu. Klient posiela iba interný kód ponuky.
- Nová cena vytvorí novú verziu; historické objednávky a nároky zostávajú na zakúpenej verzii.
- Samostatné test/live IDs, kľúče aj webhook secrets. Nikdy kľúče do frontendu či admin audit logov.
- Hosted Checkout a Customer Portal; platené nároky nevytvára návratová URL úspechu.

### Webhook a synchronizácia

Použiť overovanie Stripe podpisu a zachovať spracovanie potrebné pre Cashier. Vlastné spracovanie napojiť tak, aby sa Cashier synchronizácia neobišla. Prijatie rýchlo potvrdiť až po trvalom zaevidovaní udalosti; doménové spracovanie cez queue.

Udalosti na mapovanie podľa skutočnej verzie API: zmena/zánik subscription, zaplatenie/neúspech invoice, dokončenie/async výsledok Checkout, refund a dispute. Pri dokúpení grantovať až po potvrdenom zaplatení; samotné completed pri odloženej metóde nestačí. `invoice.paid` pre prvé a obnovené predplatné je podklad zaplateného obdobia, nie každá faktúra bez kontroly položiek.

Unikátny index Stripe event ID nestačí: grant musí mať aj business key typu invoice + príslušná položka/obdobie alebo order/payment ID. Rôzne eventy o rovnakej platbe nesmú pridať použitia viackrát. Stará udalosť nesmie vrátiť stav predplatného späť; porovnať aktuálny autoritatívny stav zo Stripe a zaplatené intervaly.

Scheduler denne zosúladí predplatné, nevybavené objednávky, refundácie a zlyhané webhooky. Kritické rozdiely hlási administrátorovi. Návratová stránka môže ukázať „Platbu overujeme“.

### Stavový model nárokov

| Situácia | Správanie |
|---|---|
| Free / žiadna platba | Free + dostupné zakúpené/skúšobné použitia |
| Prvá platba neúplná alebo SCA | Bez nových platených nárokov, ponúknuť dokončenie |
| Zaplatené obdobie | Plus do paid_through; mesačné granty podľa rozpisu |
| Zrušené obnovovanie | Plus do konca zaplateného obdobia, bez budúcej platby |
| Neúspešná obnova | Navrhnutá 3-dňová tolerancia na funkcie Plus, bez nových mesačných AI grantov; potom Free |
| Dodatočne úspešná úhrada | Zosúladiť pôvodné fakturačné obdobie, nevytvoriť druhý grant |
| Plná refundácia obdobia | Odobrať nároky daného nákupu podľa refund workflow; zachovať nesúvisiace balíky a dáta |
| Dispute | Pozastaviť použitia viazané na spornú platbu, manuálne vyhodnotiť; nevymazať účet |

Tolerancia pri neúspešnej obnove nie je Cashier cancel grace period; implementovať samostatne. Pre zjednodušenie launch bez bezplatného Stripe trial a bez automatického prorátovania. Zmena mesačný/ročný plán nastane na hranici existujúceho obdobia cez podporované plánovanie; portal nesmie dovoliť nepodporované okamžité swapy.

## 6. Mesačné granty pri ročnej platbe

Ročná úhrada dá Plus na rok, AI použitia po mesiacoch. Nevytvoriť 360 textov a 60 obrázkov okamžite.

Uložiť pôvodný anchor timestamp a vypočítať 12 intervalov priamym pripočítaním n kalendárnych mesiacov s orezaním na posledný deň mesiaca. Po 31. januári nasleduje koniec februára, potom 31. marec, nie trvalý posun na 28. deň. Počítať v explicitnej billing zóne, uložiť UTC hranice a intervaly [start, end). Nikdy nepoužívať pevné 30-dňové intervaly.

Mesačnému predplatnému zodpovedá zaplatené fakturačné obdobie zo Stripe. Ročnému zodpovedá vlastný mesačný rozpis ohraničený ročným paid_through.

Scheduler alebo prvé použitie idempotentne otvorí aktuálny grant. Výpadok scheduleru nevytvorí hromadu minulých použiteľných grantov. Aktuálny interval má unikátny kľúč household + entitlement source + quota kind + start. Zrušenie obnovovania ročného plánu nezastaví granty vo zvyšku zaplateného roka. Refundácia ročného plánu zastaví aj budúce granty z tejto platby.

Zakúpené použitia sú samostatné granty a mesačné obnovenie ich nikdy nevynuluje.

## 7. Refundácie, zrušenie a doklady

Rozlišovať štyri veci: zrušenie budúceho obnovovania, odstúpenie od zmluvy, reklamáciu vadného plnenia a dobrovoľnú refundáciu.

Odporúčaná jednoduchá štartovacia politika: pri včasnom odstúpení od prvého nákupu v 14-dňovej lehote vrátiť celú cenu, aj keď už bola využitá časť AI. Je to produktový návrh nadväzujúci na práva spotrebiteľa, nie tvrdenie, že všetky typy plnenia majú vždy rovnaký právny režim. Opakované zneužívanie riešiť primerane bez odopretia zákonných práv. Finálne znenie a uplatnenie na jednorazové balíky skontrolovať právnikom.

Refundovať cez Stripe a výsledok zosúladiť webhookom. Každá refundácia má idempotency key, dôvod, sumu, autora a väzbu na order/invoice. Nevymazať pôvodný doklad ani spotrebu. Viesť kompenzačné ledger zápisy. Pri čiastočnej refundácii administrátor určí, koľko nevyužitých jednotiek sa odoberá; neodhadovať nejednoznačne pomer podľa celkovej sumy. Odoberanie z iného zakúpeného balíka je zakázané.

Odporúčanie: jeden autoritatívny proces vystavovania daňových dokladov. Ak budú faktúry zo Stripe, overiť ich náležitosti a číslovanie s účtovníkom. Ak sa použije externá fakturácia, zabrániť duplicitnému vystaveniu. Cashier PDF sám osebe nie je potvrdenie splnenia všetkých miestnych daňových povinností. Dobropisy a refundácie musia zostať dohľadateľné.

Stripe Tax nezapínať bez obchodníkových daňových registrácií a rozhodnutia o režime. Pre predaj do ďalších krajín preveriť miesto dodania elektronickej služby a OSS [S11]. Nezamieňať neplatiteľa domácej DPH s automatickou absenciou všetkých cezhraničných povinností.

## 8. Administrácia pre vlastníka aplikácie

Navrhnuté rozhranie: Filament na `/admin` [S6], vo verzii kompatibilnej s repozitárom. Existujúci Filament zachovať. Administrátor platformy je iná rola než vlastník domácnosti.

### Prvý admin účet

Pripraviť idempotentný artisan príkaz `app:grant-platform-admin` s explicitným emailom/ID existujúceho overeného používateľa. Ak neexistuje, vytvoriť pozvánku cez bezpečný reset/setup link. Email vlastníka nie je v zadaní, doplní sa pri nasadení. Žiadne hardcoded heslo, verejný seed admin/admin, prideľovanie podľa prvého registrovaného účtu ani verejný endpoint na povýšenie.

Vyžadovať MFA pre admin, potvrdenie citlivých akcií opätovným overením, rate limiting a odhlásenie relácií pri odobratí roly. Bežný používateľ nesmie rolu prideliť mass assignmentom. Zabrániť odobratiu posledného funkčného superadmina bez náhradného prístupu. Recovery kódy odovzdať vlastníkovi bezpečne; neukladať ich do repozitára.

### Moduly

| Modul | Obsah a akcie |
|---|---|
| Dashboard | Platiace domácnosti, MRR bez DPH podľa definície, inkaso, refundácie, AI náklady, queue a webhook chyby |
| Domácnosti a účty | Stav, plán, dátum obnovy, overenie, blokovanie zneužitia, anonymizované metriky |
| Predplatné | Stripe ID, zaplatené intervaly, synchronizácia, zrušenie obnovy, prístup do Stripe |
| Balíky a objednávky | Obsah nákupu, úhrada, zostatky, refundácia |
| AI úlohy | Model, profil, usage, odhad nákladu, stav, trvanie, chyba; bez obsahu receptu predvolene |
| Použitia | Granty, rezervácie, spotreba, kompenzácie a dôvody |
| Katalóg | Draft/active/retired ceny a balíky; zmeny vytvoria novú verziu |
| Právne dokumenty | Verzie, platnosť, kontrola povinných údajov, publikácia a história akceptácií |
| Služby a cookies | Účel, poskytovateľ, kategória, aktívnosť, cookie/storage inventár |
| Súkromie | Žiadosti o export, opravu, výmaz, stav a lehota |
| Audit | Kto, čo, kedy, cieľ, dôvod; finančné záznamy sa v UI nemažú |

MRR normalizovať mesačne; ročná platba nie je celá mesačný výnos. Oddeliť tržbu, cash inkaso, AI náklady a príspevok po variabilných nákladoch. Posledný ukazovateľ neoznačovať ako čistý zisk.

Správca môže pridať kompenzačné použitia alebo časovo obmedzený Plus grant s dôvodom. Nesfalšuje tým Stripe platbu a neprepíše stav `paid`. Žiadne všeobecné editovanie zostatku bez ledger zápisu. Refundácia a zmena právnych textov majú potvrdenie a audit.

Administrácia predvolene nezobrazuje súkromné recepty ani mená detí/hostí. Podporný prístup k obsahu len pri konkrétnej potrebe, oprávnení a audite. V prvej verzii bez prihlasovania sa „ako používateľ“. Globálny AI kill switch zastaví nové úlohy, zachová dáta a ukáže dočasnú nedostupnosť; dlhšie výpadky riešiť komunikáciou a kompenzáciou.

## 9. VOP, cookies a ochrana súkromia – rozsah

Pripraviť stránky `/vop`, `/ochrana-osobnych-udajov`, `/cookies`, `/odstupenie-od-zmluvy` a kontakt/reklamácie. Odkazy v pätičke, registrácii a platbe. Dokumenty majú verziu, dátum publikácie/účinnosti a dostupný archív. Pri uzavretí zmluvy odoslať obsah podmienok a objednávky v trvalom formáte emailom (text/PDF), nie iba odkaz na priebežne meniteľnú stránku.

Právny rámec: zákon 108/2024 Z. z., Občiansky zákonník, GDPR a pravidlá elektronických komunikácií. Pri online odstúpení zohľadniť aj pravidlá účinné v roku 2026 [S7–S10]. Úplné aktuálne slovenské paragrafové znenie sa pri príprave nepodarilo cez nástroj načítať; agent ani tento dokument preto nepredstierajú finálny právny audit. Pred publikovaním overiť aktuálne znenie a právnikom schváliť klasifikáciu priebežnej digitálnej služby a jednorazových balíkov. Staré obchodné podmienky iného webu nekopírovať.

### Povinné vstupy od prevádzkovateľa

Obchodné meno, právna forma, sídlo/miesto podnikania, IČO, registrácia v registri, DIČ/IČ DPH ak relevantné, podporný email, reklamačný a privacy kontakt, prípadný telefón podľa platných povinností; cieľové krajiny; daňový režim; poskytovatelia hostingu, emailov, analytiky a monitoringu; umiestnenie a režim záloh; finálne ceny a reklamačné postupy.

Nevymýšľať firmu podľa iného projektu používateľa. Nevyplnené údaje zobrazovať iba v pracovnom návrhu. Publikovanie plateného Checkout blokovať, pokiaľ chýba povinná identifikácia, aktívne podmienky, jasné ceny alebo právne schválenie procesov. To neblokuje vývoj ani existujúce bezplatné funkcie.

### Obsah VOP na dopracovanie

Identita a kontakty; opis Free/Plus a balíkov; vznik zmluvy; konečná cena a interval obnovy; zahrnuté limity a jednorazové balíky; platby/doklady; zrušenie/odstúpenie/reklamácie oddelene; dostupnosť a chyby; pravidlá AI; používateľský obsah; zmeny ceny do budúcna; ukončenie a export; príslušné riešenie sporov. Určiť aktuálne príslušný orgán ARS a kontakty. Nepublikovať neoverený historický odkaz na európsku ODR platformu.

Nepísať „žiadne refundácie“, „za nič nezodpovedáme“ či automatickú stratu všetkých práv spustením AI. Nepoužívať jeden plošný checkbox „vzdávam sa odstúpenia“ pre celé predplatné. Zákonné nároky pri vadnej službe ostávajú zachované. Jednoduchšie je odporúčaná ústretová refund politika než komplikované uplatňovanie výnimiek.

### Registrácia a Checkout

Registrácia: samostatné prijatie VOP; informácie o súkromí dostupné pri formulári. Prevádzkové spracúvanie nezakladať na povinnom „súhlase s GDPR“. Marketingový súhlas, ak sa vôbec pridá, je samostatný a nepovinný.

Pred platbou: identita predávajúceho, obsah balíka, konečná suma, periodicita, automatická obnova, spôsob zrušenia, odkaz na odstúpenie a platnú verziu VOP. Finálne objednávkové tlačidlo jasne vyjadruje povinnosť zaplatiť. Ak sa služba začína ihneď a právny režim vyžaduje žiadosť o skoré plnenie, evidovať ju oddelene od marketingu a cookies; finálne znenie právne overiť.

Pripraviť viditeľnú online funkciu odstúpenia: identifikácia objednávky → potvrdenie úmyslu → okamžité potvrdenie prijatia na trvalom médiu → refund workflow. Nevynucovať telefonát. Odstúpenie musí byť riešiteľné aj keď používateľ stratil prístup k účtu. Odlíšiť ho od tlačidla „Zrušiť obnovovanie“.

## 10. Pracovné texty stránok

Nasledujúce sú konkrétne moduly textu pre implementáciu návrhov, nie kompletné hotové VOP. Doplniť vyššie uvedené povinné časti a chýbajúce firemné údaje. V administrácii stav `draft`, kým neprejdú kontrolou.

### VOP – produkt a predplatné

„Službu Moje recepty na adrese moje-recepty.sk poskytuje [OBCHODNÉ MENO, SÍDLO, IČO, REGISTER], kontakt [EMAIL]. Služba umožňuje ukladať vlastné recepty, zaznamenávať preferencie domácnosti a plánovať varenie. Rozsah plateného programu a jednorazových balíkov je uvedený pri objednávke.

Program Plus je určený pre jednu domácnosť. Cena je 2,49 € za mesačné obdobie alebo 24 € za ročné obdobie [POTVRDIŤ KONEČNÉ CENY A DAŇOVÚ INFORMÁCIU]. Predplatné sa automaticky obnovuje v zvolenom intervale. Obnovovanie môžete vypnúť v nastaveniach fakturácie; program zostane dostupný do konca zaplateného obdobia.

Program obsahuje 30 textových AI použití a 5 Standard obrázkov za mesačné obdobie. Pri ročnej platbe sa použitia dopĺňajú mesačne. Nevyužité zahrnuté použitia sa neprenášajú. Jednorazovo dokúpené použitia sa evidujú oddelene, neobnovujú sa automaticky a zostávajú použiteľné aj po skončení Plus počas prevádzky služby.

Nový úspešne doručený návrh alebo obrázok spotrebuje použitie aj vtedy, ak si ho neuložíte ako aktívny. Pri technickom zlyhaní bez doručeného výsledku sa použitie neodpočíta. AI návrhy môžu obsahovať chyby; pred použitím skontrolujte suroviny a postup. Obrázky vytvorené AI sú ilustrácie. Tým nie sú obmedzené vaše zákonné práva.

Po skončení Plus vám zostane prístup k uloženým receptom a exportu. Podmienky odstúpenia, reklamácií a refundácií upravujú samostatné články [DOPRACOVAŤ PRED PUBLIKÁCIOU].“

### Informácie o súkromí – úvod

„Prevádzkovateľom vašich osobných údajov je [IDENTITA A KONTAKT]. Spracúvame údaje potrebné na vytvorenie účtu, prevádzku vašej domácnosti, vybavenie platieb a podpory. Podrobný prehľad účelov, právnych základov, príjemcov a lehôt je uvedený nižšie [DOPLNIŤ SCHVÁLENÝ REGISTER].

Ak použijete AI funkciu, potrebný obsah receptu odošleme poskytovateľovi OpenAI. Mená členov domácnosti a ich osobné profily do požiadavky zámerne nezahŕňame. Do textu receptu preto nevkladajte osobné údaje, ktoré na túto funkciu nie sú potrebné. Platby spracúva Stripe; v aplikácii neukladáme celé číslo vašej platobnej karty.

Voliteľná analytika sa spustí podľa vašej voľby v nastaveniach cookies. Túto voľbu môžete neskôr zmeniť. S otázkami a žiadosťami týkajúcimi sa údajov nás kontaktujte na [PRIVACY EMAIL].“

### Cookies – banner a krátka stránka

Banner: „Na fungovanie účtu a zabezpečenie používame nevyhnutné technológie. S vaším súhlasom použijeme aj analytiku na meranie používania aplikácie. Voľbu môžete kedykoľvek zmeniť v nastaveniach cookies.“

Rovnako dostupné tlačidlá: **Prijať voliteľné**, **Odmietnuť voliteľné**, **Nastavenia**. Nezobrazovať kategóriu marketing, kým nemá konkrétnu službu a účel. Ak neexistuje voliteľná analytika ani iné voliteľné technológie, nežiadať prázdny súhlas.

Stránka: „Používame nevyhnutné technológie pre prihlásenie, bezpečnosť a zapamätanie vašej voľby. Voliteľné služby a ich konkrétne cookies alebo úložiská uvádzame v tabuľke nižšie. Pred ich povolením ich nespúšťame. Súhlas môžete zmeniť cez Nastavenia cookies v pätičke.“

Tabuľku vygenerovať z overeného inventára; názvy, domény a expirácie nesmú byť vymyslené generickým textom.

## 11. GDPR – implementácia a register údajov

GDPR vyžaduje transparentnosť, primeraný právny základ, minimalizáciu, bezpečnosť a postupy uplatnenia práv; pri údajoch získaných o iných členoch rodiny treba riešiť aj informačné povinnosti voči nim. Zdravotné údaje majú osobitný režim [S8]. Konkrétne právne základy a lehoty schváliť podľa skutočného prevádzkovateľa; tabuľka je pracovný návrh.

| Dáta/účel | Navrhnutý základ na preverenie | Návrh retencie |
|---|---|---|
| Účet prihlasujúceho sa zákazníka a služba | Plnenie zmluvy | Počas účtu; výmaz aktívnych dát do 30 dní po vybavení zrušenia |
| Fakturačné a účtovné záznamy | Zákonná povinnosť | Podľa potvrdených zákonných lehôt, oddelene od receptov |
| Bezpečnostné logy | Oprávnený záujem, zdokumentované posúdenie | 90 dní ako návrh, incident osobitne |
| Podpora | Zmluva/oprávnený záujem podľa prípadu | Návrh 24 mesiacov po uzavretí |
| Nepovinná analytika | Súhlas podľa konfigurácie | Návrh najkratšej použiteľnej retencie, napr. 2 mesiace detailných udalostí |
| Mená/preferencie osôb bez účtu | Samostatne posúdiť oprávnený záujem/informovanie | Do odstránenia profilu; uprednostniť prezývku |
| AI vstupy a výstupy | Súčasť vyžiadanej služby, preveriť zmluvy | Trvalé len prijaté receptové dáta; debug payloady predvolene neukladať |
| Doklad o akceptácii/súhlase | Evidencia právneho úkonu/obhajoba nárokov podľa účelu | Schválená retenčná politika, nie nekonečne |

Tabuľka neurčuje zákonné lehoty; čísla označené ako návrh sú interné produktové ciele. Prevádzka ich musí vedieť technicky splniť. Výnimka zo zmazania účtovných dokladov neznamená právo držať navždy celý receptový profil.

Doplniť príjemcov a ich skutočné roly: hosting, databáza/úložisko, email, Stripe, OpenAI, analytika, monitoring. Overiť zmluvy o spracúvaní tam, kde sú potrebné, lokality a mechanizmus prenosu mimo EHP. Neoznačiť automaticky všetkých dodávateľov za sprostredkovateľov. OpenAI dokumentácia opisuje rozdielne retenčné režimy; netvrdiť nulovú retenciu alebo výhradné uloženie v EÚ bez príslušnej konfigurácie a oprávnenia [S12].

### Rodina, deti a hostia

Účet a nákup navrhnúť pre dospelého správcu. Deti majú len profily bez vlastných účtov; nezbierať dátum narodenia ani plné meno, ak netreba. Hostí možno viesť pod prezývkou. Prístup k rodinnému priestoru odvolateľný.

Nevyžadovať ani systematicky neevidovať diagnózy/alergie v tejto monetizačnej etape. „Nemám rád“ nie je automaticky zdravotný údaj. Ak existujúce pole výluk zbiera zdravotné dôvody, treba osobitne vyriešiť čl. 9, oprávnenie pre údaje detí a iných dospelých a informovanie; samotné zaškrtnutie VOP vlastníkom domácnosti to nerieši. Takéto údaje neposielať analytike ani do AI promptov. Existujúce citlivé dáta nemažte bez migračného plánu.

### Používateľské funkcie

Export strojovo čitateľných dát a médií, oprava profilu, vymazanie hosťa, žiadosť o výmaz účtu. Pri zrušení vlastníka domácnosti ponúknuť prevod alebo zrušenie domácnosti; nevystaviť recepty zvyšným používateľom nesprávnym spôsobom. Pred potvrdením ukázať dopad na predplatné a nevyužité balíky. Žiadosť o výmaz nezamietnuť len kvôli aktívnemu predplatnému; vyriešiť jeho ukončenie a účtovné výnimky oddelene.

Zmazanie propagovať do úložiska, indexov, cache a dodávateľov podľa zmluvných možností. Pre zálohy stanoviť rotáciu a postup, aby obnova neobnovila už vybavené výmazy. Návrh záloh max. 35 dní, ale publikovať až po overení hostingu. Viesť evidenciu žiadostí a lehoty; pripraviť postup incidentu a posúdenia oznamovacích povinností.

## 12. Cookies a analytika – technická špecifikácia

Odporúčam začať jedným analytickým nástrojom; výber GA4 alebo iného poskytovateľa ostáva otvorený. Vytvoriť provider adapter a register služieb. Všetky nevybrané integrácie sú vypnuté. Cookies stránka uvádza iba skutočne nasadené služby.

Consent manager musí ovládať reálne načítanie scriptov, pixelov, iframe, SDK a relevantných sieťových požiadaviek, nie len hodnotu v localStorage. Prvá návšteva a odmietnutie = žiadna voliteľná analytická požiadavka. Na launch použiť prísne blokovanie do opt-in, bez predbežných „cookieless pings“. Server-side analytics nesmie slúžiť na obchádzanie odmietnutia.

Implementovať:
- Necessary stále aktívne a vysvetlené; analytics false do voľby. Marketing false a bez integrácie.
- Prijatie, odmietnutie a detailné nastavenie rovnako dostupné; žiadne vopred zapnuté voliteľné prepínače. Súlad smerovania s usmernením EDPB [S9].
- Zatvorenie lišty/scroll nie sú súhlas. Aplikácia ostáva použiteľná po odmietnutí.
- Trvalý odkaz na zmenu voľby. Odvolanie zastaví budúce eventy a odstráni spravované identifikátory, kde je to technicky možné; nedokáže spätne „odoslať späť“ už poslané dáta.
- Server aj klient pracujú s tou istou verziou účelov. Nový poskytovateľ/účel vyžaduje novú relevantnú voľbu pred spustením.
- Návrh životnosti uloženia voľby 6 mesiacov; ide o produktové nastavenie, nie univerzálnu zákonnú lehotu.
- Consent receipt s verziou textu, kategóriami, časom a pseudonymným ID. Neukladať celé IP len pre pohodlie. Anonymné rozhodnutie funguje aj bez loginu.
- Pri SPA navigácii neinicializovať analytiku duplicitne a neposielať staré eventy nazbierané pred súhlasom.
- Pri novej službe inventarizovať cookies, localStorage, domény, účel, trvanie a prenosy.

Neposielať názvy receptov, ingrediencie, alergie, mená rodiny, emaily, prompty či fakturačné údaje do analytiky. Použiť allowlist všeobecných udalostí: recipe_created, selection_started, meal_planned, checkout_started, subscription_started, addon_purchased; bez citlivých payloadov. Platobnú prevádzkovú evidenciu viesť samostatne od marketingovej analytiky. Neposielať automaticky celé URL s ID a query parametrami. V admin a na právnych/platobných citlivých formulároch voliteľnú analytiku predvolene vypnúť.

Na začiatok bez session replay, reklamných pixelov a profilovania detí. Nepridávať nové kategórie len preto, že ich má knižnica v šablóne.

## 13. Navrhnuté PHP/JS balíky

| Potreba | Návrh | Podmienka |
|---|---|---|
| Stripe | laravel/cashier | Požiadavka používateľa; kompatibilná verzia, nepísať druhý subscription engine |
| Admin | filament/filament | Zachovať existujúci, ak už je |
| Roly | Laravel Policies; spatie/laravel-permission až pri viacerých rolách | Pre jedného admina netreba zložitý RBAC balík |
| Audit | Vlastná malá audit tabuľka alebo spatie/laravel-activitylog | Redakcia citlivých polí; nesmie logovať heslá/tokeny |
| Queue monitoring | Laravel Horizon pri Redis queue | Ak Redis nie je, funguje aj existujúca queue; nezavádzať infra len pre dashboard |
| MFA | Existujúca autentifikácia/MFA kompatibilná s admin panelom | Žiadny druhý konfliktný login systém |
| Feature flags | Jednoduchá serverová konfigurácia alebo existujúce riešenie | Katalóg nárokov je v DB; flag nie je fakturačný dôkaz |
| Consent UI | Malý Vue komponent alebo overená CMP | Podstatné je blokovanie a evidencia; žiadny balík nezaručí právny súlad |
| Monitoring | Existujúci error tracker s redakciou dát | Ďalší dodávateľ až po zaradení do registra súkromia |

Voliteľné balíky sú kandidáti, nie presné overené kompatibilné verzie. Agent najprv skontroluje composer.lock/package lock a oficiálnu dokumentáciu zvoleného balíka. Nepridávať analytics backend package v domnienke, že vyrieši súhlas návštevníka.

## 14. Dátový model rozšírenia

Rešpektovať existujúce názvy a migrácie. Peniaze EUR v centoch, náklady USD s dostatočnou presnosťou (mikrojednotky/decimal), nikdy floating point pre účtovnú knihu.

| Entita | Podstatné polia |
|---|---|
| BillingAccount | household_id unique, payer_user_id, Stripe customer ID, billing details |
| Cashier subscriptions/items | Štandard z kompatibilnej verzie, custom billable FK |
| PlanVersion | code, limits, features, interval, final_price, currency, stripe_price_id, effective state |
| AddonVersion | code, unit_kind, unit_count, final_price, currency, stripe_price_id |
| Order | household_id, immutable product snapshot, amount, tax, currency, status, checkout/payment/invoice IDs |
| PaidEntitlement | household_id, source order/invoice, plan_version, start/end, revoked_at |
| UsageGrant | household_id, kind text/image_standard, source trial/subscription/addon/compensation, quantity, valid_from, expires_at nullable, source key unique |
| UsageReservation | grant_id, ai_job_id, quantity, state reserved/consumed/released |
| UsageLedger | grant/reservation, signed movement, reason, source key unique, actor |
| AiJob | existing entity rozšíriť o profile, usage, estimated/settled cost, rate_version, result state |
| AiCostRate | model, modality, tier, currency, effective_from, ceny a zdroj |
| StripeEventInbox | event_id unique, type, object_id, state, attempts, last_error; minimal payload retention |
| RefundCase | source payment, amount, reason, units revoked, Stripe refund ID, status |
| LegalDocumentVersion | type, version, content, checksum, published/effective_at, approval metadata |
| LegalAcceptance | user, household/order, document version, timestamp, action, required acknowledgements |
| ServiceRegistry | provider, purpose, category, domains/storage inventory, retention, enabled |
| ConsentReceipt | pseudonymous visitor ID, categories, policy version, action, time |
| PrivacyRequest | subject, kind, status, deadline, completion evidence |
| AdminAudit | actor, action, target, reason, redacted before/after, time |

Ledger je append-only; opravy sú kompenzačné pohyby. Zostatok sa dá rekonštruovať a cache zosúladiť. Rezervácia znižuje dostupné použitia, úspech nemení dostupný zostatok druhý raz. Zabezpečiť matematickú integritu spotrebované + rezervované <= platný grant po zohľadnení revokácií.

Používateľský obsah ukladať oddelene od dlhšie uchovávaných finančných údajov. Audit retenciu a osobné identifikátory riešiť retenčnou politikou, nie tvrdením, že append-only znamená navždy uchované osobné dáta.

## 15. Obrazovky pre zákazníka

- Cenník s mesačným/ročným prepínačom a konečnou sumou.
- Nastavenia → Predplatné: aktuálny plán, zaplatené do, ďalšia suma/dátum, zrušiť obnovovanie, správa platby, doklady, odstúpenie.
- Nastavenia → AI použitia: „Mesačné: 18/30 textov, 3/5 obrázkov; obnoví sa …“ a samostatne „Dokúpené: 12 obrázkov“.
- Pri AI tlačidle informácia o cene v použitiach pred spustením. Po vyčerpaní jasná voľba počkať alebo kúpiť balík; bez automatickej platby.
- Checkout success so stavom overovania, spracovanie zrušeného/nedokončeného checkoutu bez straty dát.
- Súkromie: export, vymazanie, cookies nastavenia.
- Pri výpadku AI zachovať ručné úpravy receptov.

Ročné predplatné neoznačiť len veľkým „2 €/mesiac“ bez ročnej inkasovanej sumy. Zrušenie nevyžaduje rozhovor s podporou. Pripomenutie blížiacej sa ročnej obnovy odporúčané, napr. 7 dní vopred.

## 16. Etapy implementácie

1. **Audit a príprava:** verzie, schéma, existujúce platby/AI/admin, feature matrix, migračný plán, záloha. Zistiť veľkosť/kvalitu aktuálnych obrázkov a reasoning textu. Navrhnúť kompatibilné balíky.
2. **Ledger a meranie:** AI job lifecycle, rezervácie, náklady a granty; testy súbehu ešte bez live platieb. Zachovať existujúcu funkčnosť za feature flagom.
3. **Cashier:** BillingAccount, test katalóg, Checkout, webhook inbox, nároky a ročné mesačné intervaly, portal a refund workflow.
4. **Admin:** bezpečný bootstrap, MFA, roly/policies, finančné a AI dashboardy, audit, kompenzácie a synchronizácia.
5. **Právne a súkromie:** draft stránky, verzie/akceptácie, registrácia služieb, consent gating, export/výmaz, odstúpenie a emailové potvrdenia.
6. **Plus funkcie:** dokončiť týždenný plán a nákupný zoznam, ak chýbajú. Nevyužívať platenú AI, ak stačí existujúci algoritmus. Nepredávať nehotové funkcie.
7. **Staging a launch:** test clock/simulácie dostupné v Stripe, overenie dokladov, doplnenie identity a právna/daňová kontrola, meranie AI, potvrdenie cien, produkčné secrets a webhook. Zapnutie platieb samostatným kontrolovaným nasadením.

Po dokončení odovzdať migrácie, runbook, konfiguráciu bez secrets, zoznam eventov, test výsledky, admin setup postup, právne drafty/publikované verzie a presné otvorené rozhodnutia. Chýbajúce údaje neblokujú etapy 1–6; blokujú len nepravdivé publikovanie a aktiváciu komerčnej ponuky.

## 17. Akceptačné testy

1. Člen domácnosti nemôže kúpiť predplatné alebo otvoriť billing portal za cudziu domácnosť; oprávnený vlastník áno.
2. Zmena klientského price ID/ceny/počtu jednotiek sa odmietne. Cena pochádza zo serverového katalógu.
3. Neplatný webhook podpis sa odmietne; duplicate event aj rôzne eventy tej istej úhrady pridajú grant raz.
4. Zaplatené Checkout a neskorší webhook skončia rovnako; success URL sama neaktivuje Plus.
5. Neúplná a async neúspešná platba nepridá použitia. Po úhrade sa prístup aktivuje bez ďalšieho nákupu.
6. Dve súbežné AI úlohy s jedným použitím: iba jedna rezervácia uspeje. Retry worker nespotrebuje druhú jednotku.
7. Technické zlyhanie uvoľní rezerváciu raz. Úspech uloží výsledok a spotrebu atómovo na aplikačnej úrovni.
8. Zrušený ročný plán pokračuje v mesačných grantoch do zaplateného konca. Ročná refundácia zastaví budúce granty.
9. Anchor 31. január, február priestupného roka, DST, presná hranica intervalu a scheduler výpadok nevytvoria duplicity ani posun na 30-dňový mesiac.
10. Obnova nevynuluje kúpený balík. Po skončení Plus sú dokúpené jednotky použiteľné a recepty dostupné.
11. Zmena plánu na hranici obdobia nepridá druhý mesačný grant. Nezaplatená obnova neudelí bezplatné nové použitia.
12. Plná aj čiastočná refundácia odoberú len príslušné nároky a majú dohľadateľný ledger a doklad. Starý webhook ich neobnoví.
13. Admin bez MFA nemá prístup; bežný vlastník domácnosti nie je admin. Finančné a kompenzačné akcie majú audit.
14. Nová cena nemení zakúpený snapshot, starý doklad ani zaplatené obdobie. Staré Plus funkcie neodobrať spätne.
15. Pred cookies voľbou, po odmietnutí a po odvolaní žiadne voliteľné sieťové požiadavky. Overiť aj SPA navigáciu, embed a server-side odosielanie.
16. Pri súhlase len analytics zostáva marketing vypnutý. Nový účel nededí starý súhlas. Zatvorenie lišty nie je opt-in.
17. Analytics payload neobsahuje receptový obsah, email ani rodinné údaje. Admin a citlivé formuláre sa nenahrávajú session replay.
18. Registrovanie nevyžaduje marketing/GDPR súhlas pre zmluvné spracúvanie. Objednávka zachytí presnú verziu podmienok a potvrdí ju emailom.
19. Odstúpenie je samostatné od zrušenia obnovovania a vytvorí potvrdenie prijatia aj refund case.
20. Nevyplnená identita prevádzkovateľa alebo draft právne dokumenty zablokujú live checkout, nie zobrazenie receptov.
21. Export/výmaz sa týka správneho subjektu/domácnosti; účtovné výnimky nezachovajú nepotrebné recepty. Obnova záloh rešpektuje evidované výmazy.
22. Globálny AI výpadok nezmaže granty ani neznemožní manuálnu editáciu. Budget alarm je viditeľný adminovi a neodpočítava nedoručené výsledky.
23. Migračný rollout nevytvorí skúšobné použitia opakovane existujúcim účtom a nevymyslí spätne spotrebu, ktorú systém nemeral.

## 18. Rozhodnutia na doplnenie pred live platbami

- Prevádzkovateľ a jeho fakturačné/právne údaje; nepotvrdené, nejde automaticky o BikeUP.
- Admin email alebo ID existujúceho účtu; rolu udeľuje bezpečný deploy príkaz.
- Potvrdenie 2,49 €/mes., 24 €/rok, 30 textov, 5 Standard obrázkov; 20 obrázkov za 3,99 € a 100 textov za 1,99 €.
- Skutočné nastavenie obrázkov a reasoning, výsledky nákladového merania.
- Analytický poskytovateľ; kým nie je vybraný, integrácia vypnutá.
- Hosting, email, zálohy, monitoring a zoznam príjemcov dát.
- Cieľový trh a DPH/OSS/fakturačný režim.
- Finálne právne posúdenie a schválenie VOP, odstúpenia, reklamácií a privacy registra.

## 19. Zdroje a hranice overenia

Zdroje overované 26. 9. 2026. Ceny a dokumentácia sa môžu zmeniť; pred implementáciou overiť kompatibilitu repozitára a pred launchom aktuálny cenník. Technický plán a konkrétne interné pravidlá sú vlastný návrh, nie doslovné požiadavky uvedených zdrojov.

- [S1 – GPT-6 Luna](https://developers.openai.com/api/docs/models/gpt-6-luna): cenník a možnosti modelu.
- [S2 – Image generation](https://developers.openai.com/api/docs/guides/image-generation): náklady gpt-image-2; [model](https://developers.openai.com/api/docs/models/gpt-image-2).
- [S3 – Stripe Payments SK](https://stripe.com/en-sk/pricing).
- [S4 – Stripe Billing SK](https://stripe.com/en-sk/billing/pricing).
- [S5 – Laravel Cashier](https://laravel.com/framework/docs/13.x/billing): referenčná dokumentácia, nie pokyn upgradovať aplikáciu na 13.x.
- [S6 – Filament](https://filamentphp.com/docs).
- [S7 – Zákon 108/2024 Z. z.](https://www.slov-lex.sk/ezbierky/pravne-predpisy/SK/ZZ/2024/108/): úplné aktuálne znenie sa nepodarilo načítať; vyžaduje overenie pred právnou finalizáciou.
- [S8 – GDPR](https://eur-lex.europa.eu/eli/reg/2016/679/oj/eng).
- [S9 – EDPB Cookie Banner Taskforce](https://www.edpb.europa.eu/documents/task-force-report/report-of-the-work-undertaken-by-the-cookie-banner-taskforce_en): vyhľadaný oficiálny záznam; úplný dokument sa nepodarilo načítať.
- [S10 – Európska komisia: Consumer Rights Directive](https://commission.europa.eu/law/law-topic/consumer-protection-law/consumer-contract-law/consumer-rights-directive_en): rámec práv spotrebiteľa a zmeny 2026; konkrétnu slovenskú transpozíciu overiť.
- [S11 – Finančná správa: OSS](https://www.financnasprava.sk/sk/podnikatelia/dane/dan-z-pridanej-hodnoty/one-stop-shop): rozhodnutie podľa skutočného podnikania, nie automatické nastavenie podľa domény.
- [S12 – OpenAI Data controls](https://developers.openai.com/api/docs/guides/your-data).

## 20. Priama inštrukcia pre coding agenta

Rozšír existujúcu aplikáciu podľa tohto dokumentu. Začni auditom repozitára, neprepíš receptový systém. Použi Cashier a zachovaj modely gpt-6-luna/gpt-image-2. Oddel Stripe billing od aplikačného ledgeru použití. Najprv dokonči testovateľnú vertikálnu cestu: zaplatené test predplatné → mesačný grant → rezervácia AI → úspech/chyba → dokúpenie → zrušenie/refundácia. Potom admin, právne stránky, consent a export/výmaz.

Implementuj aj konkrétne návrhy právnych stránok s jasnými chýbajúcimi údajmi v draft režime. Nevyhlasuj ich za právne schválené a nepublikuj placeholdery ako skutočné údaje. Neaktivuj živé predplatné s nehotovými sľúbenými funkciami. Chýbajúci admin email alebo dodávateľ analytiky rieš konfiguračným vstupom, nie odhadom. Po dokončení uveď vykonané testy, cenu meraných AI operácií alebo dôvod, prečo nebola meraná, a presné zostávajúce launch vstupy.
