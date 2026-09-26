<x-mail::message>
# Potvrdenie prijatia odstúpenia od zmluvy

Prijali sme vaše odstúpenie od zmluvy pod číslom **{{ $request->reference }}** dňa {{ $request->received_at->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i') }}.

@if ($request->order_reference)
Uvedená objednávka: {{ $request->order_reference }}
@endif

Odstúpenie posúdime a o výsledku vás budeme informovať na tento e-mail. Vrátenie platby prebieha rovnakým spôsobom, akým ste platili, spravidla do 14 dní. Tento e-mail si uchovajte ako potvrdenie prijatia.

@if ($operator->get('complaints_email') !== '')
Otázky: {{ $operator->get('complaints_email') }}
@endif

{{ config('app.name') }}
</x-mail::message>
