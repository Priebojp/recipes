<x-mail::message>
# Potvrdenie objednávky #{{ $order->id }}

Ďakujeme za objednávku v službe {{ config('app.name') }}.

**Produkt:** {{ $order->productName() }}<br>
**Konečná cena:** {{ $amount }}{{ $isSubscription && $interval ? ' za '.($interval === 'year' ? 'ročné' : 'mesačné').' obdobie' : '' }}<br>
**Zaplatené:** {{ ($order->paid_at ?? now())->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i') }}<br>
@if ($isSubscription)
**Obnovovanie:** predplatné sa automaticky obnovuje v zvolenom intervale, kým ho nevypnete v Nastavenia → Predplatné. Program zostane dostupný do konca zaplateného obdobia.
@else
**Obnovovanie:** žiadne – jednorazový balík, nič sa neúčtuje automaticky.
@endif

@if ($isSubscription)
Obsah balíka: {{ $order->product_snapshot['text_uses_per_period'] ?? '–' }} textových AI použití a {{ $order->product_snapshot['image_uses_per_period'] ?? '–' }} obrázkov Standard za mesačné obdobie.
@else
Obsah balíka: {{ $order->product_snapshot['unit_count'] ?? '–' }} × {{ $order->product_snapshot['unit_kind'] ?? '' }}.
@endif

## Odstúpenie od zmluvy

Od zmluvy môžete odstúpiť do {{ $withdrawalDays }} dní od uzavretia online formulárom: {{ route('legal.withdrawal.form') }} (uveďte číslo objednávky #{{ $order->id }}). Formulár funguje aj bez prihlásenia. Odstúpenie je iná vec než vypnutie obnovovania predplatného.

@if ($terms)
## Obchodné podmienky

Objednávka sa riadi dokumentom **{{ $terms->title }}, verzia {{ $terms->version }}** (účinná od {{ $terms->effective_at?->format('j. n. Y') }}), ktorý je priložený k tomuto e-mailu a dostupný na {{ route('legal.show', ['slug' => $terms->type->slug(), 'version' => $terms->version]) }}.
@endif

@if ($operator->get('business_name') !== '')
Predávajúci: {{ $operator->identityLine() }}. Kontakt: {{ $operator->get('support_email') }}.
@endif

Doklad o platbe nájdete v Nastavenia → Predplatné → Správa platby a doklady.

{{ config('app.name') }}
</x-mail::message>
