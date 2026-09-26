<x-mail::message>
# Odstúpenie od zmluvy {{ $request->reference }}

@if ($refunded)
Vaše odstúpenie sme prijali a **vraciame {{ $amount }}** rovnakým spôsobom, akým ste platili. Pripísanie na účet zvyčajne trvá niekoľko pracovných dní podľa vašej banky. Zakúpené použitia a prípadné zaplatené obdobie boli ukončené; recepty a ostatné údaje zostávajú v účte.
@else
Vaše odstúpenie sme posúdili s týmto výsledkom:

{{ $request->decision_note }}

Ak s rozhodnutím nesúhlasíte, môžete podať reklamáciu alebo sa obrátiť na subjekt alternatívneho riešenia sporov uvedený v obchodných podmienkach.
@endif

@if ($operator->get('complaints_email') !== '')
Kontakt: {{ $operator->get('complaints_email') }}
@endif

{{ config('app.name') }}
</x-mail::message>
