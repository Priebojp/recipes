<x-mail::message>
# {{ __('Potvrdenie objednávky #:order', ['order' => $order->id]) }}

{{ __('Ďakujeme za objednávku v službe :app.', ['app' => config('app.name')]) }}

**{{ __('Produkt:') }}** {{ $order->productName() }}<br>
**{{ __('Konečná cena:') }}** {{ $isSubscription && $interval ? ($interval === 'year' ? __(':amount za ročné obdobie', ['amount' => $amount]) : __(':amount za mesačné obdobie', ['amount' => $amount])) : $amount }}<br>
**{{ __('Zaplatené:') }}** {{ ($order->paid_at ?? now())->timezone(config('recipes.billing.timezone'))->format('j. n. Y H:i') }}<br>
@if ($isSubscription)
**{{ __('Obnovovanie:') }}** {{ __('predplatné sa automaticky obnovuje v zvolenom intervale, kým ho nevypnete v Nastavenia → Predplatné. Program zostane dostupný do konca zaplateného obdobia.') }}
@else
**{{ __('Obnovovanie:') }}** {{ __('žiadne – jednorazový balík, nič sa neúčtuje automaticky.') }}
@endif

@if ($isSubscription)
{{ __('Obsah programu: :uses.', ['uses' => implode(', ', \App\Models\PlanVersion::describeUses((array) ($order->product_snapshot['uses_per_period'] ?? []))) ?: '–']) }}
@else
{{ __('Obsah balíka: :count × :kind.', ['count' => $order->product_snapshot['unit_count'] ?? '–', 'kind' => \App\Enums\UsageKind::tryFrom((string) ($order->product_snapshot['unit_kind'] ?? ''))?->label() ?? ($order->product_snapshot['unit_kind'] ?? '')]) }}
@endif

## {{ __('Odstúpenie od zmluvy') }}

{{ __('Od zmluvy môžete odstúpiť do :days dní od uzavretia online formulárom: :url (uveďte číslo objednávky #:order). Formulár funguje aj bez prihlásenia. Odstúpenie je iná vec než vypnutie obnovovania predplatného.', ['days' => $withdrawalDays, 'url' => route('legal.withdrawal.form'), 'order' => $order->id]) }}

@if ($terms)
## {{ __('Obchodné podmienky') }}

{{ __('Objednávka sa riadi dokumentom **:title, verzia :version** (účinná od :date), ktorý je priložený k tomuto e-mailu a dostupný na :url.', ['title' => $terms->title, 'version' => $terms->version, 'date' => $terms->effective_at?->format('j. n. Y'), 'url' => route('legal.show', ['slug' => $terms->type->slug(), 'version' => $terms->version])]) }}
@endif

@if ($operator->get('business_name') !== '')
{{ __('Predávajúci: :seller. Kontakt: :email.', ['seller' => $operator->identityLine(), 'email' => $operator->get('support_email')]) }}
@endif

{{ __('Doklad o platbe nájdete v Nastavenia → Predplatné → Správa platby a doklady.') }}

{{ config('app.name') }}
</x-mail::message>
