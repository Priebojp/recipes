<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Slovak messages for the validator. The application speaks informally
    | ("ty"), so the messages do too.
    |
    */

    'accepted' => 'Pole :attribute musí byť potvrdené.',
    'accepted_if' => 'Pole :attribute musí byť potvrdené, keď :other je :value.',
    'active_url' => 'Pole :attribute nie je platná URL adresa.',
    'after' => 'Pole :attribute musí byť dátum po :date.',
    'after_or_equal' => 'Pole :attribute musí byť dátum :date alebo neskorší.',
    'alpha' => 'Pole :attribute môže obsahovať len písmená.',
    'alpha_dash' => 'Pole :attribute môže obsahovať len písmená, číslice, pomlčky a podčiarkovníky.',
    'alpha_num' => 'Pole :attribute môže obsahovať len písmená a číslice.',
    'any_of' => 'Pole :attribute nie je platné.',
    'array' => 'Pole :attribute musí byť zoznam.',
    'ascii' => 'Pole :attribute môže obsahovať len jednobajtové alfanumerické znaky a symboly.',
    'before' => 'Pole :attribute musí byť dátum pred :date.',
    'before_or_equal' => 'Pole :attribute musí byť dátum :date alebo skorší.',
    'between' => [
        'array' => 'Pole :attribute musí mať od :min do :max položiek.',
        'file' => 'Súbor :attribute musí mať od :min do :max kB.',
        'numeric' => 'Pole :attribute musí byť od :min do :max.',
        'string' => 'Pole :attribute musí mať od :min do :max znakov.',
    ],
    'boolean' => 'Pole :attribute musí byť áno alebo nie.',
    'can' => 'Pole :attribute obsahuje nepovolenú hodnotu.',
    'confirmed' => 'Potvrdenie poľa :attribute sa nezhoduje.',
    'contains' => 'Pole :attribute neobsahuje požadovanú hodnotu.',
    'current_password' => 'Heslo nie je správne.',
    'date' => 'Pole :attribute nie je platný dátum.',
    'date_equals' => 'Pole :attribute musí byť dátum :date.',
    'date_format' => 'Pole :attribute nezodpovedá formátu :format.',
    'decimal' => 'Pole :attribute musí mať :decimal desatinných miest.',
    'declined' => 'Pole :attribute musí byť odmietnuté.',
    'declined_if' => 'Pole :attribute musí byť odmietnuté, keď :other je :value.',
    'different' => 'Polia :attribute a :other musia byť rozdielne.',
    'digits' => 'Pole :attribute musí mať :digits číslic.',
    'digits_between' => 'Pole :attribute musí mať od :min do :max číslic.',
    'dimensions' => 'Obrázok :attribute má neplatné rozmery.',
    'distinct' => 'Pole :attribute obsahuje duplicitnú hodnotu.',
    'doesnt_contain' => 'Pole :attribute nesmie obsahovať: :values.',
    'doesnt_end_with' => 'Pole :attribute nesmie končiť na: :values.',
    'doesnt_start_with' => 'Pole :attribute nesmie začínať na: :values.',
    'email' => 'Pole :attribute musí byť platná e-mailová adresa.',
    'ends_with' => 'Pole :attribute musí končiť na: :values.',
    'enum' => 'Vybraná hodnota poľa :attribute nie je platná.',
    'exists' => 'Vybraná hodnota poľa :attribute nie je platná.',
    'extensions' => 'Súbor :attribute musí mať príponu: :values.',
    'file' => 'Pole :attribute musí byť súbor.',
    'filled' => 'Pole :attribute musí mať hodnotu.',
    'gt' => [
        'array' => 'Pole :attribute musí mať viac ako :value položiek.',
        'file' => 'Súbor :attribute musí byť väčší ako :value kB.',
        'numeric' => 'Pole :attribute musí byť väčšie ako :value.',
        'string' => 'Pole :attribute musí mať viac ako :value znakov.',
    ],
    'gte' => [
        'array' => 'Pole :attribute musí mať aspoň :value položiek.',
        'file' => 'Súbor :attribute musí mať aspoň :value kB.',
        'numeric' => 'Pole :attribute musí byť aspoň :value.',
        'string' => 'Pole :attribute musí mať aspoň :value znakov.',
    ],
    'hex_color' => 'Pole :attribute musí byť platná farba v hexadecimálnom tvare.',
    'image' => 'Pole :attribute musí byť obrázok.',
    'in' => 'Vybraná hodnota poľa :attribute nie je platná.',
    'in_array' => 'Pole :attribute sa musí nachádzať v :other.',
    'in_array_keys' => 'Pole :attribute musí obsahovať aspoň jeden z kľúčov: :values.',
    'integer' => 'Pole :attribute musí byť celé číslo.',
    'ip' => 'Pole :attribute musí byť platná IP adresa.',
    'ipv4' => 'Pole :attribute musí byť platná IPv4 adresa.',
    'ipv6' => 'Pole :attribute musí byť platná IPv6 adresa.',
    'json' => 'Pole :attribute musí byť platný JSON.',
    'list' => 'Pole :attribute musí byť zoznam.',
    'lowercase' => 'Pole :attribute musí byť malými písmenami.',
    'lt' => [
        'array' => 'Pole :attribute musí mať menej ako :value položiek.',
        'file' => 'Súbor :attribute musí byť menší ako :value kB.',
        'numeric' => 'Pole :attribute musí byť menšie ako :value.',
        'string' => 'Pole :attribute musí mať menej ako :value znakov.',
    ],
    'lte' => [
        'array' => 'Pole :attribute nesmie mať viac ako :value položiek.',
        'file' => 'Súbor :attribute nesmie mať viac ako :value kB.',
        'numeric' => 'Pole :attribute nesmie byť väčšie ako :value.',
        'string' => 'Pole :attribute nesmie mať viac ako :value znakov.',
    ],
    'mac_address' => 'Pole :attribute musí byť platná MAC adresa.',
    'max' => [
        'array' => 'Pole :attribute nesmie mať viac ako :max položiek.',
        'file' => 'Súbor :attribute nesmie mať viac ako :max kB.',
        'numeric' => 'Pole :attribute nesmie byť väčšie ako :max.',
        'string' => 'Pole :attribute nesmie mať viac ako :max znakov.',
    ],
    'max_digits' => 'Pole :attribute nesmie mať viac ako :max číslic.',
    'mimes' => 'Súbor :attribute musí byť typu: :values.',
    'mimetypes' => 'Súbor :attribute musí byť typu: :values.',
    'min' => [
        'array' => 'Pole :attribute musí mať aspoň :min položiek.',
        'file' => 'Súbor :attribute musí mať aspoň :min kB.',
        'numeric' => 'Pole :attribute musí byť aspoň :min.',
        'string' => 'Pole :attribute musí mať aspoň :min znakov.',
    ],
    'min_digits' => 'Pole :attribute musí mať aspoň :min číslic.',
    'missing' => 'Pole :attribute nesmie byť prítomné.',
    'missing_if' => 'Pole :attribute nesmie byť prítomné, keď :other je :value.',
    'missing_unless' => 'Pole :attribute nesmie byť prítomné, pokiaľ :other nie je :value.',
    'missing_with' => 'Pole :attribute nesmie byť prítomné, keď je vyplnené :values.',
    'missing_with_all' => 'Pole :attribute nesmie byť prítomné, keď sú vyplnené :values.',
    'multiple_of' => 'Pole :attribute musí byť násobkom :value.',
    'not_in' => 'Vybraná hodnota poľa :attribute nie je platná.',
    'not_regex' => 'Formát poľa :attribute nie je platný.',
    'numeric' => 'Pole :attribute musí byť číslo.',
    'password' => [
        'letters' => 'Pole :attribute musí obsahovať aspoň jedno písmeno.',
        'mixed' => 'Pole :attribute musí obsahovať aspoň jedno veľké a jedno malé písmeno.',
        'numbers' => 'Pole :attribute musí obsahovať aspoň jednu číslicu.',
        'symbols' => 'Pole :attribute musí obsahovať aspoň jeden špeciálny znak.',
        'uncompromised' => 'Zadané :attribute sa objavilo v úniku dát. Zvoľ si, prosím, iné.',
    ],
    'present' => 'Pole :attribute musí byť prítomné.',
    'present_if' => 'Pole :attribute musí byť prítomné, keď :other je :value.',
    'present_unless' => 'Pole :attribute musí byť prítomné, pokiaľ :other nie je :value.',
    'present_with' => 'Pole :attribute musí byť prítomné, keď je vyplnené :values.',
    'present_with_all' => 'Pole :attribute musí byť prítomné, keď sú vyplnené :values.',
    'prohibited' => 'Pole :attribute nie je povolené.',
    'prohibited_if' => 'Pole :attribute nie je povolené, keď :other je :value.',
    'prohibited_if_accepted' => 'Pole :attribute nie je povolené, keď je :other potvrdené.',
    'prohibited_if_declined' => 'Pole :attribute nie je povolené, keď je :other odmietnuté.',
    'prohibited_unless' => 'Pole :attribute nie je povolené, pokiaľ :other nie je v :values.',
    'prohibits' => 'Pole :attribute nedovoľuje vyplniť :other.',
    'regex' => 'Formát poľa :attribute nie je platný.',
    'required' => 'Pole :attribute je povinné.',
    'required_array_keys' => 'Pole :attribute musí obsahovať položky: :values.',
    'required_if' => 'Pole :attribute je povinné, keď :other je :value.',
    'required_if_accepted' => 'Pole :attribute je povinné, keď je :other potvrdené.',
    'required_if_declined' => 'Pole :attribute je povinné, keď je :other odmietnuté.',
    'required_unless' => 'Pole :attribute je povinné, pokiaľ :other nie je v :values.',
    'required_with' => 'Pole :attribute je povinné, keď je vyplnené :values.',
    'required_with_all' => 'Pole :attribute je povinné, keď sú vyplnené :values.',
    'required_without' => 'Pole :attribute je povinné, keď nie je vyplnené :values.',
    'required_without_all' => 'Pole :attribute je povinné, keď nie je vyplnené žiadne z :values.',
    'same' => 'Polia :attribute a :other sa musia zhodovať.',
    'size' => [
        'array' => 'Pole :attribute musí mať :size položiek.',
        'file' => 'Súbor :attribute musí mať :size kB.',
        'numeric' => 'Pole :attribute musí byť :size.',
        'string' => 'Pole :attribute musí mať :size znakov.',
    ],
    'starts_with' => 'Pole :attribute musí začínať na: :values.',
    'string' => 'Pole :attribute musí byť text.',
    'timezone' => 'Pole :attribute musí byť platné časové pásmo.',
    'unique' => 'Táto hodnota poľa :attribute sa už používa.',
    'uploaded' => 'Súbor :attribute sa nepodarilo nahrať.',
    'uppercase' => 'Pole :attribute musí byť veľkými písmenami.',
    'url' => 'Pole :attribute musí byť platná URL adresa.',
    'ulid' => 'Pole :attribute musí byť platný ULID.',
    'uuid' => 'Pole :attribute musí byť platný UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'email' => [
            'unique' => 'Účet s touto e-mailovou adresou už existuje.',
        ],
        'terms' => [
            'accepted' => 'Na založenie účtu je potrebné prijať obchodné podmienky.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Human names of the fields so messages read "Pole meno je povinné."
    |
    */

    'attributes' => [
        'name' => 'meno',
        'email' => 'e-mail',
        'password' => 'heslo',
        'password_confirmation' => 'potvrdenie hesla',
        'current_password' => 'aktuálne heslo',
        'code' => 'kód',
        'recovery_code' => 'záložný kód',
        'terms' => 'obchodné podmienky',
        'title' => 'názov',
        'description' => 'popis',
        'note' => 'poznámka',
        'notes' => 'poznámka',
        'color' => 'farba',
        'kind' => 'typ',
        'reason' => 'dôvod',
        'message' => 'správa',
        'quantity' => 'množstvo',
        'servings' => 'porcie',
        'base_servings' => 'porcie',
        'prep_minutes' => 'čas prípravy',
        'cook_minutes' => 'čas varenia',
        'ingredients' => 'ingrediencie',
        'steps' => 'postup',
        'date' => 'dátum',
        'timezone' => 'časové pásmo',
    ],

];
