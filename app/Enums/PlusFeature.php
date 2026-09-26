<?php

namespace App\Enums;

/**
 * Features sold in the Plus plan (specification chapter 2). Free households keep every other function.
 */
enum PlusFeature: string
{
    case WeeklyMenu = 'weekly_menu';
    case ShoppingList = 'shopping_list';
    case SelectionPresets = 'selection_presets';

    public function label(): string
    {
        return match ($this) {
            self::WeeklyMenu => 'Návrh týždenného jedálnička',
            self::ShoppingList => 'Nákupný zoznam z plánu',
            self::SelectionPresets => 'Uložené skupiny a filtre výberu',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::WeeklyMenu => 'Rovnaký generátor ako pri jednom jedle naplánuje celý týždeň naraz: bez opakovania, s ohľadom na chute, históriu a už naplánované jedlá. Každý deň môžeš vymeniť a až potom uložiť.',
            self::ShoppingList => 'Suroviny všetkých naplánovaných jedál v týždni, prepočítané na porcie a zlúčené podľa názvu a jednotky. Odškrtávanie a vlastné položky.',
            self::SelectionPresets => 'Jedným klikom obnovíš skupinu stravníkov, typ jedla a filtre – napríklad „Rodina“ alebo „Návšteva“.',
        };
    }
}
