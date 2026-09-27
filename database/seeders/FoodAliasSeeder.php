<?php

namespace Database\Seeders;

use App\Enums\FoodPreparationState;
use App\Models\FoodAlias;
use App\Models\FoodSourceRecord;
use Illuminate\Database\Seeder;

/**
 * Hand-checked dictionary of common Slovak / Czech ingredients → USDA FoodData Central foods (SR Legacy,
 * IDs verified against the API on 26. 9. 2026). The seed stores only IDs, names and aliases; nutrient values
 * arrive with `php artisan app:food-sync`, which also warns when a description at USDA no longer matches
 * the expectation recorded here. Idempotent: existing records and aliases are left as they are.
 */
class FoodAliasSeeder extends Seeder
{
    /**
     * [fdcId, USDA description, Slovak name, preparation state, ['sk' => aliases, 'cs' => aliases]]
     *
     * @return list<array{0: int, 1: string, 2: string, 3: FoodPreparationState, 4: array<string, list<string>>}>
     */
    public static function entries(): array
    {
        $raw = FoodPreparationState::Raw;
        $cooked = FoodPreparationState::Cooked;
        $dry = FoodPreparationState::Dry;
        $canned = FoodPreparationState::Canned;

        return [
            // Múka, obilniny, pečivo
            [169761, 'Wheat flour, white, all-purpose, unenriched', 'Hladká pšeničná múka', $dry, ['sk' => ['múka', 'hladká múka', 'polohrubá múka', 'hrubá múka', 'pšeničná múka'], 'cs' => ['mouka', 'hladká mouka', 'polohrubá mouka', 'hrubá mouka']]],
            [168944, 'Wheat flour, whole-grain, soft wheat', 'Celozrnná pšeničná múka', $dry, ['sk' => ['celozrnná múka'], 'cs' => ['celozrnná mouka']]],
            [168933, 'Semolina, unenriched', 'Krupica', $dry, ['sk' => ['krupica', 'detská krupica'], 'cs' => ['krupice']]],
            [169756, 'Rice, white, long-grain, regular, raw, unenriched', 'Ryža biela dlhozrnná, surová', $raw, ['sk' => ['ryža', 'surová ryža', 'biela ryža', 'dlhozrnná ryža'], 'cs' => ['rýže']]],
            [169757, 'Rice, white, long-grain, regular, unenriched, cooked without salt', 'Ryža biela dlhozrnná, uvarená', $cooked, ['sk' => ['ryža', 'varená ryža', 'uvarená ryža'], 'cs' => ['rýže', 'vařená rýže']]],
            [169703, 'Rice, brown, long-grain, raw (Includes foods for USDA\'s Food Distribution Program)', 'Hnedá ryža (natural), surová', $raw, ['sk' => ['hnedá ryža', 'natural ryža'], 'cs' => ['hnědá rýže']]],
            [168927, 'Pasta, dry, unenriched', 'Cestoviny suché', $dry, ['sk' => ['cestoviny', 'špagety', 'penne', 'fusilli', 'tarhoňa', 'kolienka'], 'cs' => ['těstoviny']]],
            [168928, 'Pasta, cooked, unenriched, without added salt', 'Cestoviny uvarené', $cooked, ['sk' => ['varené cestoviny', 'uvarené cestoviny'], 'cs' => ['vařené těstoviny']]],
            [174924, 'Bread, white, commercially prepared (includes soft bread crumbs)', 'Biely chlieb / pečivo', $cooked, ['sk' => ['chlieb', 'biely chlieb', 'rožok', 'rožky', 'žemľa', 'žemle', 'pečivo'], 'cs' => ['chléb', 'rohlík', 'rohlíky', 'houska']]],
            [172684, 'Bread, rye', 'Ražný chlieb', $cooked, ['sk' => ['ražný chlieb', 'tmavý chlieb'], 'cs' => ['žitný chléb']]],
            [174928, 'Bread, crumbs, dry, grated, plain', 'Strúhanka', $dry, ['sk' => ['strúhanka'], 'cs' => ['strouhanka']]],
            [173904, 'Cereals, oats, regular and quick, not fortified, dry', 'Ovsené vločky', $dry, ['sk' => ['ovsené vločky', 'vločky'], 'cs' => ['ovesné vločky']]],
            [170685, 'Buckwheat groats, roasted, dry', 'Pohánka', $dry, ['sk' => ['pohánka'], 'cs' => ['pohanka']]],
            [168874, 'Quinoa, uncooked', 'Quinoa surová', $dry, ['sk' => ['quinoa'], 'cs' => []]],
            [170284, 'Barley, pearled, raw', 'Jačmenné krúpy', $dry, ['sk' => ['krúpy', 'jačmenné krúpy'], 'cs' => ['kroupy']]],
            [169702, 'Millet, raw', 'Pšeno', $dry, ['sk' => ['pšeno'], 'cs' => ['jáhly']]],
            [169698, 'Cornstarch', 'Kukuričný škrob', $dry, ['sk' => ['kukuričný škrob', 'škrob', 'solamyl', 'maizena'], 'cs' => ['škrob']]],
            [172803, 'Leavening agents, baking powder, double-acting, sodium aluminum sulfate', 'Prášok do pečiva', $dry, ['sk' => ['prášok do pečiva', 'kypriaci prášok'], 'cs' => ['prášek do pečiva']]],
            [175040, 'Leavening agents, baking soda', 'Sóda bikarbóna', $dry, ['sk' => ['sóda bikarbóna', 'jedlá sóda'], 'cs' => ['jedlá soda']]],
            [175043, 'Leavening agents, yeast, baker\'s, active dry', 'Sušené droždie', $dry, ['sk' => ['sušené droždie', 'droždie', 'kvasnice'], 'cs' => ['droždí', 'sušené droždí']]],

            // Zelenina a bylinky
            [170000, 'Onions, raw', 'Cibuľa', $raw, ['sk' => ['cibuľa', 'cibuľka', 'červená cibuľa'], 'cs' => ['cibule']]],
            [169230, 'Garlic, raw', 'Cesnak', $raw, ['sk' => ['cesnak'], 'cs' => ['česnek']]],
            [170393, 'Carrots, raw', 'Mrkva', $raw, ['sk' => ['mrkva'], 'cs' => ['mrkev']]],
            [170026, 'Potatoes, flesh and skin, raw', 'Zemiaky surové', $raw, ['sk' => ['zemiaky', 'zemiak', 'surové zemiaky'], 'cs' => ['brambory', 'brambora']]],
            [170438, 'Potatoes, boiled, cooked in skin, flesh, without salt', 'Zemiaky uvarené v šupke', $cooked, ['sk' => ['varené zemiaky', 'uvarené zemiaky'], 'cs' => ['vařené brambory']]],
            [170457, 'Tomatoes, red, ripe, raw, year round average', 'Paradajky', $raw, ['sk' => ['paradajky', 'paradajka', 'rajčiny'], 'cs' => ['rajčata', 'rajče']]],
            [170108, 'Peppers, sweet, red, raw', 'Červená paprika', $raw, ['sk' => ['paprika', 'červená paprika', 'kápia'], 'cs' => ['červená paprika']]],
            [170427, 'Peppers, sweet, green, raw', 'Zelená paprika', $raw, ['sk' => ['zelená paprika'], 'cs' => []]],
            [169975, 'Cabbage, raw', 'Kapusta biela', $raw, ['sk' => ['kapusta', 'biela kapusta'], 'cs' => ['zelí', 'bílé zelí']]],
            [169279, 'Sauerkraut, canned, solids and liquids', 'Kyslá kapusta', $canned, ['sk' => ['kyslá kapusta'], 'cs' => ['kysané zelí']]],
            [168409, 'Cucumber, with peel, raw', 'Uhorka šalátová', $raw, ['sk' => ['uhorka', 'šalátová uhorka'], 'cs' => ['okurka']]],
            [169251, 'Mushrooms, white, raw', 'Šampiňóny', $raw, ['sk' => ['šampiňóny', 'huby'], 'cs' => ['žampiony', 'houby']]],
            [168462, 'Spinach, raw', 'Špenát', $raw, ['sk' => ['špenát'], 'cs' => []]],
            [169248, 'Lettuce, iceberg (includes crisphead types), raw', 'Ľadový šalát', $raw, ['sk' => ['ľadový šalát', 'šalát'], 'cs' => ['ledový salát']]],
            [170419, 'Peas, green, raw', 'Hrášok', $raw, ['sk' => ['hrášok', 'zelený hrášok'], 'cs' => ['hrášek']]],
            [169961, 'Beans, snap, green, raw', 'Zelená fazuľka', $raw, ['sk' => ['zelená fazuľka', 'fazuľové struky'], 'cs' => ['zelené fazolky']]],
            [170379, 'Broccoli, raw', 'Brokolica', $raw, ['sk' => ['brokolica'], 'cs' => ['brokolice']]],
            [169986, 'Cauliflower, raw', 'Karfiol', $raw, ['sk' => ['karfiol'], 'cs' => ['květák']]],
            [169988, 'Celery, raw', 'Zeler stonkový', $raw, ['sk' => ['stonkový zeler', 'zeler'], 'cs' => ['řapíkatý celer']]],
            [170416, 'Parsley, fresh', 'Petržlenová vňať', $raw, ['sk' => ['petržlenová vňať', 'petržlen', 'vňať'], 'cs' => ['petrželka', 'petržel']]],
            [172233, 'Dill weed, fresh', 'Kôpor', $raw, ['sk' => ['kôpor'], 'cs' => ['kopr']]],
            [169246, 'Leeks, (bulb and lower leaf-portion), raw', 'Pór', $raw, ['sk' => ['pór'], 'cs' => ['pórek']]],
            [169291, 'Squash, summer, zucchini, includes skin, raw', 'Cuketa', $raw, ['sk' => ['cuketa'], 'cs' => []]],
            [169228, 'Eggplant, raw', 'Baklažán', $raw, ['sk' => ['baklažán'], 'cs' => ['lilek']]],
            [169998, 'Corn, sweet, yellow, raw', 'Kukurica', $raw, ['sk' => ['kukurica'], 'cs' => ['kukuřice']]],
            [169295, 'Squash, winter, butternut, raw', 'Tekvica maslová', $raw, ['sk' => ['tekvica', 'maslová tekvica', 'hokkaido'], 'cs' => ['dýně']]],
            [169145, 'Beets, raw', 'Cvikla', $raw, ['sk' => ['cvikla', 'červená repa'], 'cs' => ['červená řepa']]],
            [168424, 'Kohlrabi, raw', 'Kaleráb', $raw, ['sk' => ['kaleráb'], 'cs' => ['kedlubna']]],
            [169276, 'Radishes, raw', 'Reďkovka', $raw, ['sk' => ['reďkovka'], 'cs' => ['ředkvička']]],
            [172232, 'Basil, fresh', 'Bazalka čerstvá', $raw, ['sk' => ['bazalka', 'čerstvá bazalka'], 'cs' => []]],

            // Ovocie
            [167746, 'Lemons, raw, without peel', 'Citrón', $raw, ['sk' => ['citrón', 'citrónová šťava'], 'cs' => ['citron']]],
            [171688, 'Apples, raw, with skin (Includes foods for USDA\'s Food Distribution Program)', 'Jablko', $raw, ['sk' => ['jablko', 'jablká'], 'cs' => ['jablka']]],
            [173944, 'Bananas, raw', 'Banán', $raw, ['sk' => ['banán', 'banány'], 'cs' => []]],
            [169097, 'Oranges, raw, all commercial varieties', 'Pomaranč', $raw, ['sk' => ['pomaranč', 'pomaranče'], 'cs' => ['pomeranč']]],
            [167762, 'Strawberries, raw', 'Jahody', $raw, ['sk' => ['jahody'], 'cs' => []]],
            [169949, 'Plums, raw', 'Slivky', $raw, ['sk' => ['slivky'], 'cs' => ['švestky']]],
            [173954, 'Cherries, sour, red, raw', 'Višne', $raw, ['sk' => ['višne'], 'cs' => ['višně']]],
            [171697, 'Apricots, raw', 'Marhule', $raw, ['sk' => ['marhule'], 'cs' => ['meruňky']]],
            [169928, 'Peaches, yellow, raw', 'Broskyne', $raw, ['sk' => ['broskyne'], 'cs' => ['broskve']]],
            [169118, 'Pears, raw', 'Hrušky', $raw, ['sk' => ['hrušky'], 'cs' => []]],
            [168165, 'Raisins, dark, seedless (Includes foods for USDA\'s Food Distribution Program)', 'Hrozienka', $dry, ['sk' => ['hrozienka'], 'cs' => ['rozinky']]],

            // Strukoviny, orechy, semená
            [172420, 'Lentils, raw', 'Šošovica suchá', $dry, ['sk' => ['šošovica'], 'cs' => ['čočka']]],
            [172421, 'Lentils, mature seeds, cooked, boiled, without salt', 'Šošovica uvarená', $cooked, ['sk' => ['varená šošovica'], 'cs' => ['vařená čočka']]],
            [175202, 'Beans, white, mature seeds, raw', 'Fazuľa biela suchá', $dry, ['sk' => ['fazuľa', 'biela fazuľa'], 'cs' => ['fazole']]],
            [175194, 'Beans, kidney, red, mature seeds, cooked, boiled, without salt', 'Fazuľa červená uvarená', $cooked, ['sk' => ['varená fazuľa', 'červená fazuľa'], 'cs' => ['vařené fazole']]],
            [173756, 'Chickpeas (garbanzo beans, bengal gram), mature seeds, raw', 'Cícer suchý', $dry, ['sk' => ['cícer'], 'cs' => ['cizrna']]],
            [173757, 'Chickpeas (garbanzo beans, bengal gram), mature seeds, cooked, boiled, without salt', 'Cícer uvarený', $cooked, ['sk' => ['varený cícer'], 'cs' => ['vařená cizrna']]],
            [170187, 'Nuts, walnuts, english', 'Vlašské orechy', $dry, ['sk' => ['vlašské orechy', 'orechy'], 'cs' => ['vlašské ořechy', 'ořechy']]],
            [170567, 'Nuts, almonds', 'Mandle', $dry, ['sk' => ['mandle'], 'cs' => []]],
            [170581, 'Nuts, hazelnuts or filberts', 'Lieskové orechy', $dry, ['sk' => ['lieskové orechy', 'lieskovce'], 'cs' => ['lískové ořechy']]],
            [172430, 'Peanuts, all types, raw', 'Arašidy', $dry, ['sk' => ['arašidy'], 'cs' => ['arašídy']]],
            [170562, 'Seeds, sunflower seed kernels, dried', 'Slnečnicové semienka', $dry, ['sk' => ['slnečnicové semienka', 'slnečnica'], 'cs' => ['slunečnicová semínka']]],
            [170556, 'Seeds, pumpkin and squash seed kernels, dried', 'Tekvicové semienka', $dry, ['sk' => ['tekvicové semienka'], 'cs' => ['dýňová semínka']]],
            [171330, 'Spices, poppy seed', 'Mak', $dry, ['sk' => ['mak', 'mletý mak'], 'cs' => ['mák']]],
            [170150, 'Seeds, sesame seeds, whole, dried', 'Sezam', $dry, ['sk' => ['sezam', 'sezamové semienka'], 'cs' => []]],
            [169414, 'Seeds, flaxseed', 'Ľanové semienka', $dry, ['sk' => ['ľanové semienka'], 'cs' => ['lněná semínka']]],

            // Mliečne výrobky, vajcia, tuky
            [171287, 'Egg, whole, raw, fresh', 'Vajce', $raw, ['sk' => ['vajce', 'vajcia', 'vajíčko', 'vajíčka'], 'cs' => ['vejce']]],
            [173424, 'Egg, whole, cooked, hard-boiled', 'Vajce uvarené natvrdo', $cooked, ['sk' => ['vajce natvrdo', 'varené vajce', 'varené vajcia'], 'cs' => ['vejce natvrdo']]],
            [171265, 'Milk, whole, 3.25% milkfat, with added vitamin D', 'Mlieko plnotučné', $raw, ['sk' => ['plnotučné mlieko', 'mlieko'], 'cs' => ['plnotučné mléko', 'mléko']]],
            [171267, 'Milk, reduced fat, fluid, 2% milkfat, with added vitamin A and vitamin D', 'Mlieko polotučné (2 %)', $raw, ['sk' => ['polotučné mlieko'], 'cs' => ['polotučné mléko']]],
            [170859, 'Cream, fluid, heavy whipping', 'Smotana na šľahanie (36 %)', $raw, ['sk' => ['smotana na šľahanie', 'šľahačková smotana', 'smotana 33', 'šľahačka'], 'cs' => ['šlehačka', 'smetana ke šlehání']]],
            [170857, 'Cream, fluid, light (coffee cream or table cream)', 'Smotana na varenie (20 %)', $raw, ['sk' => ['smotana na varenie', 'smotana'], 'cs' => ['smetana na vaření', 'smetana']]],
            [171257, 'Cream, sour, cultured', 'Kyslá smotana', $raw, ['sk' => ['kyslá smotana'], 'cs' => ['zakysaná smetana']]],
            [171284, 'Yogurt, plain, whole milk', 'Biely jogurt plnotučný', $raw, ['sk' => ['biely jogurt', 'jogurt'], 'cs' => ['bílý jogurt']]],
            [172179, 'Cheese, cottage, creamed, large or small curd', 'Tvaroh hrudkový (cottage)', $raw, ['sk' => ['tvaroh', 'hrudkový tvaroh'], 'cs' => []]],
            [173410, 'Butter, salted', 'Maslo', $raw, ['sk' => ['maslo'], 'cs' => ['máslo']]],
            [171401, 'Lard', 'Bravčová masť', $raw, ['sk' => ['masť', 'bravčová masť'], 'cs' => ['sádlo']]],
            [173419, 'Cheese, edam', 'Eidam', $raw, ['sk' => ['eidam', 'syr', 'tvrdý syr'], 'cs' => ['sýr']]],
            [171241, 'Cheese, gouda', 'Gouda', $raw, ['sk' => ['gouda'], 'cs' => []]],
            [170845, 'Cheese, mozzarella, whole milk', 'Mozzarella', $raw, ['sk' => ['mozzarella'], 'cs' => []]],
            [171247, 'Cheese, parmesan, grated', 'Parmezán strúhaný', $raw, ['sk' => ['parmezán'], 'cs' => ['parmazán']]],
            [173420, 'Cheese, feta', 'Feta (balkánsky syr)', $raw, ['sk' => ['feta', 'balkánsky syr'], 'cs' => ['balkánský sýr']]],
            [173418, 'Cheese, cream', 'Smotanový syr', $raw, ['sk' => ['smotanový syr', 'lučina'], 'cs' => []]],
            [170874, 'Milk, buttermilk, fluid, cultured, lowfat', 'Cmar', $raw, ['sk' => ['cmar'], 'cs' => ['podmáslí']]],
            [171413, 'Oil, olive, salad or cooking', 'Olivový olej', $raw, ['sk' => ['olivový olej'], 'cs' => []]],
            [171025, 'Oil, sunflower, linoleic, (approx. 65%)', 'Slnečnicový olej', $raw, ['sk' => ['olej', 'slnečnicový olej', 'rastlinný olej'], 'cs' => ['slunečnicový olej']]],
            [172336, 'Oil, canola', 'Repkový olej', $raw, ['sk' => ['repkový olej'], 'cs' => ['řepkový olej']]],
            [170173, 'Nuts, coconut milk, canned (liquid expressed from grated meat and water)', 'Kokosové mlieko', $canned, ['sk' => ['kokosové mlieko'], 'cs' => ['kokosové mléko']]],

            // Mäso a ryby
            [171077, 'Chicken, broiler or fryers, breast, skinless, boneless, meat only, raw', 'Kuracie prsia', $raw, ['sk' => ['kuracie prsia', 'kuracie mäso', 'kuracie'], 'cs' => ['kuřecí prsa', 'kuřecí maso']]],
            [173627, 'Chicken, broilers or fryers, dark meat, thigh, meat only, raw', 'Kuracie stehná bez kože', $raw, ['sk' => ['kuracie stehná', 'stehná'], 'cs' => ['kuřecí stehna']]],
            [171447, 'Chicken, broilers or fryers, meat and skin, raw', 'Kura celé (mäso s kožou)', $raw, ['sk' => ['kura', 'celé kura'], 'cs' => ['kuře']]],
            [171116, 'Chicken, ground, raw', 'Mleté kuracie mäso', $raw, ['sk' => ['mleté kuracie', 'mleté kuracie mäso'], 'cs' => ['mleté kuřecí']]],
            [171098, 'Turkey, whole, breast, meat only, raw', 'Morčacie prsia', $raw, ['sk' => ['morčacie prsia', 'morčacie mäso'], 'cs' => ['krůtí prsa']]],
            [168263, 'Pork, fresh, loin, center loin (chops), boneless, separable lean only, raw', 'Bravčové karé (chudé)', $raw, ['sk' => ['bravčové karé', 'karé', 'bravčové mäso', 'bravčové', 'bravčový rezeň'], 'cs' => ['vepřová kotleta', 'vepřové maso']]],
            [167843, 'Pork, fresh, shoulder, whole, separable lean and fat, raw', 'Bravčové pliecko', $raw, ['sk' => ['bravčové pliecko', 'pliecko'], 'cs' => ['vepřová plec']]],
            [167902, 'Pork, fresh, ground, raw', 'Mleté bravčové mäso', $raw, ['sk' => ['mleté bravčové', 'mleté mäso'], 'cs' => ['mleté vepřové', 'mleté maso']]],
            [169491, 'Beef, chuck, arm pot roast, separable lean and fat, trimmed to 1/8" fat, all grades, raw', 'Hovädzie pliecko (na guláš)', $raw, ['sk' => ['hovädzie na guláš', 'hovädzie mäso', 'hovädzie', 'hovädzie pliecko'], 'cs' => ['hovězí maso', 'hovězí']]],
            [173996, 'Beef, round, top round roast, boneless, separable lean only, trimmed to 0" fat, choice, raw', 'Hovädzie zadné (chudé)', $raw, ['sk' => ['hovädzie zadné', 'hovädzí roštenec'], 'cs' => ['hovězí zadní']]],
            [174036, 'Beef, ground, 80% lean meat / 20% fat, raw', 'Mleté hovädzie mäso', $raw, ['sk' => ['mleté hovädzie'], 'cs' => ['mleté hovězí']]],
            [173864, 'Ham, sliced, regular (approximately 11% fat)', 'Šunka', $cooked, ['sk' => ['šunka'], 'cs' => []]],
            [168277, 'Pork, cured, bacon, unprepared', 'Slanina', $raw, ['sk' => ['slanina', 'anglická slanina'], 'cs' => []]],
            [172964, 'Frankfurter, pork', 'Párky', $cooked, ['sk' => ['párky', 'párok'], 'cs' => []]],
            [172936, 'Salami, cooked, beef and pork', 'Saláma', $cooked, ['sk' => ['saláma'], 'cs' => ['salám']]],
            [174579, 'Sausage, pork and beef, fresh, cooked', 'Klobása', $cooked, ['sk' => ['klobása'], 'cs' => []]],
            [167862, 'Pork, fresh, variety meats and by-products, liver, raw', 'Bravčová pečeň', $raw, ['sk' => ['pečeň', 'bravčová pečeň'], 'cs' => ['játra']]],
            [175167, 'Fish, salmon, Atlantic, farmed, raw', 'Losos', $raw, ['sk' => ['losos'], 'cs' => []]],
            [171955, 'Fish, cod, Atlantic, raw', 'Treska', $raw, ['sk' => ['treska'], 'cs' => []]],
            [173717, 'Fish, trout, rainbow, farmed, raw', 'Pstruh', $raw, ['sk' => ['pstruh'], 'cs' => []]],
            [171952, 'Fish, carp, raw', 'Kapor', $raw, ['sk' => ['kapor'], 'cs' => ['kapr']]],
            [171986, 'Fish, tuna, light, canned in water, without salt, drained solids', 'Tuniak v konzerve (vo vlastnej šťave)', $canned, ['sk' => ['tuniak', 'tuniak v konzerve'], 'cs' => ['tuňák']]],

            // Sladidlá, korenie, dochucovadlá, nápoje
            [169655, 'Sugars, granulated', 'Cukor kryštálový', $dry, ['sk' => ['cukor', 'kryštálový cukor'], 'cs' => ['cukr', 'krystalový cukr']]],
            [169656, 'Sugars, powdered', 'Práškový cukor', $dry, ['sk' => ['práškový cukor'], 'cs' => ['moučkový cukr']]],
            [169640, 'Honey', 'Med', $raw, ['sk' => ['med'], 'cs' => []]],
            [169641, 'Jams and preserves', 'Džem', $raw, ['sk' => ['džem', 'lekvár', 'marmeláda'], 'cs' => []]],
            [173468, 'Salt, table', 'Soľ', $dry, ['sk' => ['soľ'], 'cs' => ['sůl']]],
            [170931, 'Spices, pepper, black', 'Čierne korenie mleté', $dry, ['sk' => ['čierne korenie', 'mleté čierne korenie', 'korenie'], 'cs' => ['pepř', 'černý pepř']]],
            [171329, 'Spices, paprika', 'Mletá paprika', $dry, ['sk' => ['mletá paprika', 'sladká paprika', 'mletá červená paprika'], 'cs' => ['sladká paprika']]],
            [170918, 'Spices, caraway seed', 'Rasca', $dry, ['sk' => ['rasca'], 'cs' => ['kmín']]],
            [170928, 'Spices, marjoram, dried', 'Majorán', $dry, ['sk' => ['majorán', 'majoránka'], 'cs' => []]],
            [170917, 'Spices, bay leaf', 'Bobkový list', $dry, ['sk' => ['bobkový list'], 'cs' => []]],
            [171328, 'Spices, oregano, dried', 'Oregano', $dry, ['sk' => ['oregano'], 'cs' => []]],
            [170938, 'Spices, thyme, dried', 'Tymian', $dry, ['sk' => ['tymian'], 'cs' => ['tymián']]],
            [171320, 'Spices, cinnamon, ground', 'Škorica', $dry, ['sk' => ['škorica'], 'cs' => ['skořice']]],
            [173471, 'Vanilla extract', 'Vanilkový extrakt', $raw, ['sk' => ['vanilkový extrakt'], 'cs' => []]],
            [169593, 'Cocoa, dry powder, unsweetened', 'Kakao', $dry, ['sk' => ['kakao'], 'cs' => []]],
            [170273, 'Chocolate, dark, 70-85% cacao solids', 'Horká čokoláda', $dry, ['sk' => ['horká čokoláda', 'čokoláda'], 'cs' => ['hořká čokoláda']]],
            [172237, 'Vinegar, distilled', 'Ocot', $raw, ['sk' => ['ocot'], 'cs' => ['ocet']]],
            [172234, 'Mustard, prepared, yellow', 'Horčica', $raw, ['sk' => ['horčica'], 'cs' => ['hořčice']]],
            [168556, 'Catsup', 'Kečup', $raw, ['sk' => ['kečup'], 'cs' => []]],
            [170459, 'Tomato products, canned, paste, without salt added (Includes foods for USDA\'s Food Distribution Program)', 'Paradajkový pretlak', $canned, ['sk' => ['paradajkový pretlak', 'pretlak'], 'cs' => ['rajčatový protlak']]],
            [170460, 'Tomato products, canned, puree, without salt added', 'Paradajkové pyré (passata)', $canned, ['sk' => ['passata', 'paradajkové pyré'], 'cs' => []]],
            [170051, 'Tomatoes, red, ripe, canned, packed in tomato juice', 'Paradajky konzervované', $canned, ['sk' => ['konzervované paradajky', 'krájané paradajky'], 'cs' => ['krájená rajčata']]],
            [174277, 'Soy sauce made from soy and wheat (shoyu)', 'Sójová omáčka', $raw, ['sk' => ['sójová omáčka'], 'cs' => []]],
            [171009, 'Salad dressing, mayonnaise, regular', 'Majonéza', $raw, ['sk' => ['majonéza'], 'cs' => []]],
            [171542, 'Soup, chicken broth, canned, condensed', 'Kurací vývar', $cooked, ['sk' => ['vývar', 'kurací vývar'], 'cs' => []]],
            [173647, 'Beverages, water, tap, drinking', 'Voda', $raw, ['sk' => ['voda'], 'cs' => []]],
            [173190, 'Alcoholic beverage, wine, table, red', 'Červené víno', $raw, ['sk' => ['červené víno', 'víno'], 'cs' => []]],
            [174837, 'Alcoholic beverage, wine, table, white', 'Biele víno', $raw, ['sk' => ['biele víno'], 'cs' => ['bílé víno']]],
            [168746, 'Alcoholic beverage, beer, regular, all', 'Pivo', $raw, ['sk' => ['pivo'], 'cs' => []]],
        ];
    }

    public function run(): void
    {
        foreach (self::entries() as [$fdcId, $expected, $nameSk, $state, $aliases]) {
            $record = FoodSourceRecord::query()->firstOrCreate(
                ['provider' => FoodSourceRecord::PROVIDER_USDA, 'external_id' => (string) $fdcId],
                [
                    'license' => 'CC0-1.0',
                    'name' => $expected,
                    'name_sk' => $nameSk,
                    'preparation_state' => $state,
                    'basis' => FoodSourceRecord::BASIS_100G,
                    'source_snapshot' => ['seed' => ['expected_name' => $expected]],
                    'is_curated' => true,
                ],
            );

            foreach ($aliases as $locale => $names) {
                foreach ($names as $alias) {
                    FoodAlias::query()->firstOrCreate(
                        ['normalized' => FoodAlias::normalize($alias), 'food_source_record_id' => $record->id],
                        ['alias' => $alias, 'locale' => $locale, 'preparation_state' => $state, 'curated_at' => now()],
                    );
                }
            }
        }
    }
}
