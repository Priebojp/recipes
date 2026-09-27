<x-mail::message>
# {{ __('Potvrdenie prijatia odstúpenia od zmluvy') }}

{{ __('Prijali sme vaše odstúpenie od zmluvy pod číslom **:reference** dňa :date.', ['reference' => $request->reference, 'date' => $request->received_at->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i')]) }}

@if ($request->order_reference)
{{ __('Uvedená objednávka: :order', ['order' => $request->order_reference]) }}
@endif

{{ __('Odstúpenie posúdime a o výsledku vás budeme informovať na tento e-mail. Vrátenie platby prebieha rovnakým spôsobom, akým ste platili, spravidla do 14 dní. Tento e-mail si uchovajte ako potvrdenie prijatia.') }}

@if ($operator->get('complaints_email') !== '')
{{ __('Otázky: :email', ['email' => $operator->get('complaints_email')]) }}
@endif

{{ config('app.name') }}
</x-mail::message>
