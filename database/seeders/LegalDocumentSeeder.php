<?php

namespace Database\Seeders;

use App\Enums\LegalDocumentState;
use App\Enums\LegalDocumentType;
use App\Models\LegalDocumentVersion;
use Illuminate\Database\Seeder;

/**
 * Working texts of the legal pages (specification chapter 10) as *drafts*. Placeholders in [BRACKETS] must be
 * replaced by the operator's real data and the texts legally reviewed before an administrator publishes them.
 * Nothing here is a finished legal document. Idempotent: existing types are left untouched.
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::texts() as $type => [$title, $content]) {
            $type = LegalDocumentType::from($type);
            if (LegalDocumentVersion::query()->where('type', $type)->exists()) {
                continue;
            }
            LegalDocumentVersion::create([
                'type' => $type,
                'version' => 1,
                'title' => $title,
                'content' => $content,
                'checksum' => LegalDocumentVersion::checksumOf($content),
                'change_summary' => 'Pracovný návrh zo zadania v2, kapitola 10.',
                'state' => LegalDocumentState::Draft,
            ]);
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function texts(): array
    {
        return [
            'terms' => ['Všeobecné obchodné podmienky', self::terms()],
            'privacy' => ['Informácie o spracúvaní osobných údajov', self::privacy()],
            'cookies' => ['Cookies a voliteľné služby', self::cookies()],
            'withdrawal' => ['Odstúpenie od zmluvy, reklamácie a refundácie', self::withdrawal()],
        ];
    }

    private static function terms(): string
    {
        return <<<'MD'
## 1. Poskytovateľ a služba

Službu Moje recepty na adrese moje-recepty.sk poskytuje [OBCHODNÉ MENO, SÍDLO, IČO, REGISTER], kontakt [EMAIL]. Služba umožňuje ukladať vlastné recepty, zaznamenávať preferencie domácnosti a plánovať varenie. Rozsah plateného programu a jednorazových balíkov je uvedený pri objednávke.

## 2. Program Free a Plus

Program Free je bezplatný: vlastné recepty a fotografie, rodinné profily, náhodný výber, ručný plán, história a export vlastných údajov, plus skúšobné AI použitia uvedené pri registrácii.

Program Plus je určený pre jednu domácnosť. Cena je 2,49 € za mesačné obdobie alebo 24 € za ročné obdobie [POTVRDIŤ KONEČNÉ CENY A DAŇOVÚ INFORMÁCIU]. Predplatné sa automaticky obnovuje v zvolenom intervale. Obnovovanie môžete vypnúť v nastaveniach fakturácie; program zostane dostupný do konca zaplateného obdobia.

Program obsahuje 30 textových AI použití a 5 Standard obrázkov za mesačné obdobie. Pri ročnej platbe sa použitia dopĺňajú mesačne. Nevyužité zahrnuté použitia sa neprenášajú. Jednorazovo dokúpené použitia sa evidujú oddelene, neobnovujú sa automaticky a zostávajú použiteľné aj po skončení Plus počas prevádzky služby.

## 3. Vznik zmluvy, ceny a platby

Zmluva o bezplatnom používaní vzniká registráciou. Zmluva o platenom programe alebo balíku vzniká odoslaním objednávky tlačidlom s povinnosťou platby a jej úhradou; pred odoslaním vidíte identitu predávajúceho, obsah, konečnú cenu, interval obnovy a spôsob zrušenia. Ceny sú konečné pre spotrebiteľa [DOPLNIŤ DAŇOVÝ REŽIM]. Platby spracúva Stripe; doklad o platbe je dostupný v nastaveniach predplatného.

## 4. AI funkcie

Nový úspešne doručený návrh alebo obrázok spotrebuje použitie aj vtedy, ak si ho neuložíte ako aktívny. Pri technickom zlyhaní bez doručeného výsledku sa použitie neodpočíta. AI návrhy môžu obsahovať chyby; pred použitím skontrolujte suroviny a postup. Obrázky vytvorené AI sú ilustrácie. Tým nie sú obmedzené vaše zákonné práva.

## 5. Používateľský obsah a dostupnosť

Recepty, fotografie a poznámky sú vaším obsahom; udeľujete nám iba oprávnenie ich uchovávať a zobrazovať v rozsahu potrebnom na prevádzku služby. Snažíme sa o nepretržitú dostupnosť, plánovanú údržbu oznamujeme vopred; pri dlhšom výpadku platenej funkcie poskytneme primeranú kompenzáciu.

## 6. Zrušenie, odstúpenie, reklamácie

Vypnutie obnovovania, odstúpenie od zmluvy, reklamáciu a dobrovoľnú refundáciu upravuje samostatný dokument Odstúpenie od zmluvy, reklamácie a refundácie. Zákonné nároky pri vadnej službe zostávajú zachované.

## 7. Ukončenie a export

Po skončení Plus vám zostane prístup k uloženým receptom a exportu. Účet môžete kedykoľvek vymazať v nastaveniach súkromia; pred potvrdením vidíte dopad na predplatné a nevyužité balíky.

## 8. Zmeny podmienok a riešenie sporov

Zmenu podmienok oznámime e-mailom najmenej 30 dní vopred; nová verzia platí pre obdobia, ktoré začnú po jej účinnosti, staršie verzie sú v archíve. Spory rieši príslušný súd Slovenskej republiky; spotrebiteľ sa môže obrátiť na subjekt alternatívneho riešenia sporov [ARS]. [DOPRACOVAŤ PRED PUBLIKÁCIOU: právna kontrola klasifikácie priebežnej digitálnej služby a jednorazových balíkov]
MD;
    }

    private static function privacy(): string
    {
        return <<<'MD'
## Prevádzkovateľ

Prevádzkovateľom vašich osobných údajov je [IDENTITA A KONTAKT]. Spracúvame údaje potrebné na vytvorenie účtu, prevádzku vašej domácnosti, vybavenie platieb a podpory. Podrobný prehľad účelov, právnych základov, príjemcov a lehôt je uvedený nižšie [DOPLNIŤ SCHVÁLENÝ REGISTER].

## AI a platby

Ak použijete AI funkciu, potrebný obsah receptu odošleme poskytovateľovi OpenAI. Mená členov domácnosti a ich osobné profily do požiadavky zámerne nezahŕňame. Do textu receptu preto nevkladajte osobné údaje, ktoré na túto funkciu nie sú potrebné. Platby spracúva Stripe; v aplikácii neukladáme celé číslo vašej platobnej karty.

## Analytika

Voliteľná analytika sa spustí podľa vašej voľby v nastaveniach cookies. Túto voľbu môžete neskôr zmeniť. S otázkami a žiadosťami týkajúcimi sa údajov nás kontaktujte na [PRIVACY EMAIL].

## Účely, právne základy a lehoty (pracovný návrh)

| Údaje / účel | Právny základ (na preverenie) | Lehota (návrh) |
|---|---|---|
| Účet a prevádzka služby | plnenie zmluvy | počas trvania účtu; výmaz do 30 dní po vybavení zrušenia |
| Fakturačné a účtovné záznamy | zákonná povinnosť | podľa zákonných lehôt, oddelene od receptov |
| Bezpečnostné logy | oprávnený záujem | 90 dní |
| Podpora | zmluva / oprávnený záujem | 24 mesiacov po uzavretí |
| Voliteľná analytika | súhlas | najkratšia použiteľná, návrh 2 mesiace detailných udalostí |
| Mená a chute osôb bez účtu | oprávnený záujem – informovať dotknuté osoby | do odstránenia profilu; odporúčame prezývku |
| AI vstupy a výstupy | súčasť vyžiadanej služby | trvalo iba prijaté receptové dáta |
| Doklad o akceptácii a súhlase | evidencia právneho úkonu | podľa schválenej retenčnej politiky |

## Príjemcovia

Hosting [DOPLNIŤ], e-mail [DOPLNIŤ], Stripe (platby), OpenAI (AI funkcie), analytika (iba po súhlase) [DOPLNIŤ]. Prenosy mimo EHP a ich záruky [DOPLNIŤ PO OVERENÍ ZMLÚV].

## Vaše práva

Prístup, oprava, výmaz, obmedzenie, prenosnosť a námietka. Export údajov a výmaz účtu sú dostupné priamo v Nastavenia → Súkromie; ostatné žiadosti na [PRIVACY EMAIL]. Sťažnosť môžete podať Úradu na ochranu osobných údajov SR.

## Rodina a deti

Účet a nákup sú určené pre dospelého správcu. Členovia domácnosti majú iba profily bez vlastných účtov; nezbierame dátum narodenia ani plné meno, ak nie je potrebné. Zdravotné údaje nevyžadujeme; do poznámok k chutiam nevkladajte diagnózy.
MD;
    }

    private static function cookies(): string
    {
        return <<<'MD'
Používame nevyhnutné technológie pre prihlásenie, bezpečnosť a zapamätanie vašej voľby. Voliteľné služby a ich konkrétne cookies alebo úložiská uvádzame v tabuľke nižšie. Pred ich povolením ich nespúšťame. Súhlas môžete zmeniť cez Nastavenia cookies v pätičke.

Zatvorenie lišty ani rolovanie nie je súhlas. Aplikácia je plne použiteľná aj po odmietnutí voliteľných služieb. Odvolanie súhlasu zastaví ďalšie odosielanie a odstráni identifikátory, ktoré spravujeme; už odoslané údaje nedokážeme vziať späť.
MD;
    }

    private static function withdrawal(): string
    {
        return <<<'MD'
## Štyri rôzne veci

1. **Vypnutie obnovovania** predplatného: v Nastavenia → Predplatné, bez kontaktu s podporou. Plus zostane do konca zaplateného obdobia, nič ďalšie sa neúčtuje.
2. **Odstúpenie od zmluvy**: do 14 dní od uzavretia zmluvy o platenom programe alebo balíku, online formulárom nižšie, aj bez prihlásenia. Prijatie potvrdíme ihneď e-mailom. Pri včasnom odstúpení od prvého nákupu vraciame celú cenu, aj keď ste časť AI použití už využili [PRÁVNE OVERIŤ UPLATNENIE NA JEDNORAZOVÉ BALÍKY].
3. **Reklamácia** vadného plnenia: napíšte na [E-MAIL PRE REKLAMÁCIE]; vybavíme ju do 30 dní. Zákonné nároky zostávajú zachované.
4. **Dobrovoľná refundácia** mimo zákonných prípadov je na našom uvážení.

## Ako odstúpiť

Odstúpenie odošlite formulárom na tejto stránke (číslo objednávky nájdete v Nastavenia → Predplatné alebo v potvrdzovacom e-maile). Peniaze vraciame rovnakým spôsobom, akým ste platili, spravidla do 14 dní od prijatia odstúpenia.

## Alternatívne riešenie sporov

Spotrebiteľ sa môže obrátiť na [ARS]. [DOPRACOVAŤ PRED PUBLIKÁCIOU: overiť aktuálne znenie zákona 108/2024 Z. z. a pravidlá účinné v roku 2026]
MD;
    }
}
