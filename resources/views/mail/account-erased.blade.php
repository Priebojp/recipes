<x-mail::message>
# {{ __('Váš účet bol vymazaný') }}

{{ __('Žiadosť o výmaz č. :id sme vybavili :date. Recepty, fotografie, profily stravníkov, plány a história boli odstránené a prihlásenie už nie je možné.', ['id' => $requestId, 'date' => $erasedAt->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i')]) }}

{{ __('Ak ste mali platené objednávky, ich účtovné doklady uchovávame v rozsahu a po dobu, ktorú vyžaduje zákon – bez receptov a bez profilov domácnosti. Zálohy sa obmieňajú; vymazané údaje sa z nich neobnovujú.') }}

@if ($operator->get('privacy_email') !== '')
{{ __('Otázky k údajom: :email', ['email' => $operator->get('privacy_email')]) }}
@endif

{{ config('app.name') }}
</x-mail::message>
