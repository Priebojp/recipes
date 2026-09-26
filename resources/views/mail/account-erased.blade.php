<x-mail::message>
# Váš účet bol vymazaný

Žiadosť o výmaz č. {{ $requestId }} sme vybavili {{ $erasedAt->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i') }}. Recepty, fotografie, profily stravníkov, plány a história boli odstránené a prihlásenie už nie je možné.

Ak ste mali platené objednávky, ich účtovné doklady uchovávame v rozsahu a po dobu, ktorú vyžaduje zákon – bez receptov a bez profilov domácnosti. Zálohy sa obmieňajú; vymazané údaje sa z nich neobnovujú.

@if ($operator->get('privacy_email') !== '')
Otázky k údajom: {{ $operator->get('privacy_email') }}
@endif

{{ config('app.name') }}
</x-mail::message>
