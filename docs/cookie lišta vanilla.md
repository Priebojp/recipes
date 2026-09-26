# BikeUP — cookie lišta (vanilla-cookieconsent namiesto Cookiebotu)

Cookiebot účtuje každú ďalšiu doménu. Od Sep 2026 má web vlastnú cookie lištu:
[vanilla-cookieconsent](https://cookieconsent.orestbida.com/) (MIT, bez
licencie, súhlas sa ukladá do first-party cookie na každej doméne zvlášť).
Tento dokument popisuje, ako je lišta zapojená na tomto webe, čo treba
prepnúť v GTM kontajneri, a ako tú istú lištu nasadiť na ďalšie weby — s
prístupom ku kódu aj len cez GTM.

---

## 1. Ako to funguje na tomto webe

**Zapína sa jednou env premennou:** `COOKIE_CONSENT_ENABLED=true`. Bez nej
sa nerenderuje nič (lokál, CI, testy). `COOKIE_CONSENT_REVISION` (číslo,
default 0) sa zvýši, keď sa zmení, čo kategórie pokrývajú — každý návštevník
dostane lištu znova.

**Štyri kategórie** (rovnaké, ako mal Cookiebot):

| Kategória | Google consent typy | Čo pokrýva |
| --- | --- | --- |
| `necessary` | `security_storage` (vždy granted) | session, košík, jazyk, CSRF — nedá sa vypnúť |
| `analytics` | `analytics_storage` | GA4, Hotjar |
| `marketing` | `ad_storage`, `ad_user_data`, `ad_personalization` | Google Ads, Meta pixel, Sklik, Seznam |
| `functionality` | `functionality_storage`, `personalization_storage` | Daktela live chat |

**Čo lišta posiela do GTM:**

1. Ešte pred GTM snippetom (v `<head>`) web pushne
   `gtag('consent', 'default', { … všetko denied, security_storage granted,
   wait_for_update: 500 })`. Google tagy (GA4, Ads) tým pádom bežia v
   Consent Mode a bez súhlasu posielajú len cookieless pingy.
2. Po odpovedi návštevníka — a pri každom ďalšom načítaní stránky, keď je
   odpoveď uložená — lišta pushne `gtag('consent', 'update', {…})` podľa
   prijatých kategórií **a** event:

   ```js
   {
       event: 'cookie_consent_update',
       cookie_consent: { necessary: true, analytics: true, marketing: false, functionality: true }
   }
   ```

   Ten event je pre custom HTML tagy (Meta pixel, Sklik, Seznam, Hotjar),
   ktoré o Consent Mode nevedia.

**Kde čo je v kóde:**

- `app/Service/FrontEnd/CookieConsentService.php` — texty (DB preklady,
  skupina `frontend`, kľúče `cookieConsent.*`), linky na dokumenty
  Cookies a GDPR danej krajiny, consent defaults.
- `resources/views/frontend/components/consent/cookie-consent.blade.php` —
  vyrenderuje `window.cookieConsentConfig`.
- `resources/js/frontend/cookie-consent.js` — spustí lištu, mapuje
  kategórie na consent typy, maže cookies odmietnutých kategórií.
- `resources/css/frontend/app.css` — farby/písmo cez CSS premenné lišty
  (`#cc-main { --cc-… }`).
- Pätička má odkaz **Nastavenia cookies** (`data-cc="show-preferencesModal"`).
- Daktela chat sa so zapnutou lištou renderuje ako
  `<script type="text/plain" data-category="functionality">` — lišta ho
  spustí až po súhlase s funkčnými cookies.

---

## 2. Čo prepnúť v GTM kontajneri (BikeUP)

Poradie je dôležité: kód môže ísť do produkcie skôr, lišta sa zapne až
spolu s úpravou kontajnera. Kým je `COOKIE_CONSENT_ENABLED` vypnuté, web
nepushne žiadne consent defaults a Cookiebot v kontajneri funguje ako doteraz.

1. **Cookiebot CMP tag** — spauzovať (po overení zmazať aj s premennými,
   ktoré používal len on).
2. **Google tagy (GA4 config, GA4 eventy, Google Ads konverzie/remarketing,
   Conversion linker)** — nič. Majú vstavané consent checks a reagujú na
   `consent default/update` z webu. *Admin → Container settings → Enable
   consent overview* nechať zapnuté, aby bolo vidieť, ktorý tag čo vyžaduje.
3. **Custom HTML tagy** (FB pixel base + eventy, Sklik, Seznam, Hotjar,
   FPC/Enhanced Conversions):
   - Vytvoriť premennú *Data Layer Variable* `DL - consent marketing` s
     názvom `cookie_consent.marketing` a `DL - consent analytics` s
     `cookie_consent.analytics`.
   - Vytvoriť spúšťač *Custom Event* `Consent - marketing granted`:
     event name `cookie_consent_update`, podmienka
     `DL - consent marketing equals true`. Rovnako `Consent - analytics
     granted` pre Hotjar.
   - Base/pageview tagom (FB Base Pixel, Sklik retargeting, Seznam, Hotjar)
     **vymeniť** spúšťač *All Pages* za tento consent spúšťač — event chodí
     pri každom načítaní stránky so súhlasom, takže pageview nechýba, a
     zároveň okamžite po kliknutí na „Prijať".
   - Event tagom (FB AddToCart, FB Purchase, Sklik purchase…) nechať ich
     ecommerce spúšťač a v *Advanced settings → Consent settings* pridať
     *Require additional consent*: `ad_storage` (FB, Sklik) alebo
     `analytics_storage` (Hotjar). GTM ich potom bez súhlasu nespustí.
4. **Overiť v GTM Preview:** v záložke *Consent* pri `Consent
   Initialization` musí byť všetko *Denied* (okrem security), po kliknutí
   na „Prijať všetky" *Granted*; event `cookie_consent_update` sa objaví v
   časovej osi. Bez súhlasu nesmie odísť žiadny request na
   `facebook.com/tr`, `sklik`, `hotjar`.
5. **GA4 → Admin → Data streams → Configure tag settings → Consent
   settings** by mal po nábehu hlásiť, že consent signály chodia.

Poznámka: kategórie lišty sú zámerne rovnaké ako v Cookiebote
(necessary/statistics=analytics/marketing/preferences=functionality), takže
existujúce *Additional consent checks* (ak nejaké sú) ostávajú platné.

---

## 3. Nasadenie na ďalší web — s prístupom ku kódu (odporúčané)

Toto je copy-paste verzia toho, čo robí tento web, bez Laravelu. Tri kroky:
consent default pred GTM, lišta, prepojenie.

### 3.1 `<head>` — consent default a GTM (v tomto poradí)

```html
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        functionality_storage: 'denied',
        personalization_storage: 'denied',
        security_storage: 'granted',
        wait_for_update: 500
    });
</script>

<!-- štandardný GTM snippet až za tým -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-XXXXXXX');</script>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orestbida/cookieconsent@3.1.0/dist/cookieconsent.css">
```

(Ak web má bundler, radšej `npm i vanilla-cookieconsent` a importovať JS aj
CSS — bez závislosti na CDN. Tento web to robí tak.)

### 3.2 Pred `</body>` — lišta

```html
<script src="https://cdn.jsdelivr.net/gh/orestbida/cookieconsent@3.1.0/dist/cookieconsent.umd.js"></script>
<script>
    var CONSENT_TYPES = {
        analytics: ['analytics_storage'],
        marketing: ['ad_storage', 'ad_user_data', 'ad_personalization'],
        functionality: ['functionality_storage', 'personalization_storage']
    };

    function syncConsent() {
        var update = { security_storage: 'granted' };
        var accepted = { necessary: true };
        Object.keys(CONSENT_TYPES).forEach(function (category) {
            accepted[category] = CookieConsent.acceptedCategory(category);
            CONSENT_TYPES[category].forEach(function (type) {
                update[type] = accepted[category] ? 'granted' : 'denied';
            });
        });
        gtag('consent', 'update', update);
        dataLayer.push({ event: 'cookie_consent_update', cookie_consent: accepted });
    }

    CookieConsent.run({
        revision: 0,
        cookie: { expiresAfterDays: 365 },
        guiOptions: {
            consentModal: { layout: 'box wide', position: 'bottom center', equalWeightButtons: true },
            preferencesModal: { layout: 'box', equalWeightButtons: true }
        },
        categories: {
            necessary: { enabled: true, readOnly: true },
            analytics: { autoClear: { cookies: [{ name: /^_ga/ }, { name: '_gid' }, { name: /^_gat/ }, { name: /^_hj/ }] } },
            marketing: { autoClear: { cookies: [{ name: /^_fb/ }, { name: /^_gcl/ }, { name: 'sid' }] } },
            functionality: {}
        },
        language: {
            default: document.documentElement.lang || 'sk',
            autoDetect: 'document',
            translations: {
                sk: {
                    consentModal: {
                        title: 'Používame cookies',
                        description: 'Cookies nám pomáhajú, aby web fungoval správne, aby sme vedeli, ako ho používate, a aby sme vám ukazovali ponuky, ktoré vás zaujímajú. Nevyhnutné cookies bežia vždy, o ostatných rozhodnete vy.',
                        acceptAllBtn: 'Prijať všetky',
                        acceptNecessaryBtn: 'Iba nevyhnutné',
                        showPreferencesBtn: 'Nastaviť',
                        footer: '<a href="/sk/documents/sprava-cookies">Cookies</a> <a href="/sk/documents/gdpr">GDPR</a>'
                    },
                    preferencesModal: {
                        title: 'Nastavenia cookies',
                        acceptAllBtn: 'Prijať všetky',
                        acceptNecessaryBtn: 'Iba nevyhnutné',
                        savePreferencesBtn: 'Uložiť výber',
                        closeIconLabel: 'Zavrieť',
                        sections: [
                            { title: 'Ako používame cookies', description: 'Cookies sú malé súbory, ktoré si prehliadač ukladá pri návšteve webu. Nižšie si vyberiete, ktoré kategórie povolíte. Svoju voľbu môžete kedykoľvek zmeniť cez odkaz Nastavenia cookies v pätičke stránky.' },
                            { title: 'Nevyhnutné', description: 'Bez nich web nefunguje: prihlásenie, košík, výber jazyka a krajiny, zabezpečenie formulárov. Nedajú sa vypnúť.', linkedCategory: 'necessary' },
                            { title: 'Analytické', description: 'Anonymné štatistiky návštevnosti (Google Analytics), z ktorých sa učíme, čo na webe funguje a čo zlepšiť.', linkedCategory: 'analytics' },
                            { title: 'Marketingové', description: 'Cookies reklamných sietí (Google Ads, Meta), vďaka ktorým vidíte naše ponuky aj inde na internete a my vieme, ktorá reklama vás k nám priviedla.', linkedCategory: 'marketing' },
                            { title: 'Funkčné', description: 'Doplnkové služby, ktoré si pamätajú, čo ste s nimi robili — napríklad online chat s našou podporou.', linkedCategory: 'functionality' }
                        ]
                    }
                }
                // cs, de, da, en, pl: rovnaká štruktúra — texty všetkých piatich
                // jazykov sú v database/seeders/LanguageSeeder.php (createCookieConsent).
            }
        },
        onConsent: syncConsent,
        onChange: syncConsent
    });
</script>
```

### 3.3 Odkaz v pätičke a skripty mimo GTM

```html
<button type="button" data-cc="show-preferencesModal">Nastavenia cookies</button>

<!-- skript, ktorý nejde cez GTM (chat, mapa…): spustí sa až po súhlase -->
<script type="text/plain" data-category="functionality" src="https://…/widget.js"></script>
```

### 3.4 Vzhľad

Lišta sa štýluje cez CSS premenné — stačí prepísať na `#cc-main`:

```css
#cc-main {
    --cc-font-family: 'Poppins', system-ui, sans-serif;
    --cc-btn-border-radius: 9999px;
    --cc-btn-primary-bg: #fd9c01;
    --cc-btn-primary-color: #000;
    --cc-btn-primary-border-color: #fd9c01;
    --cc-btn-primary-hover-bg: #e17500;
    --cc-btn-primary-hover-border-color: #e17500;
    --cc-toggle-on-bg: #fd9c01;
    --cc-link-color: #1179a9;
}
```

Celý zoznam premenných je v `dist/cookieconsent.css` knižnice. Kontajner
potom nastaviť podľa časti 2.

---

## 4. Nasadenie na ďalší web — len cez GTM (bez zásahu do kódu)

Funguje, ale má háčiky, preto je to voľba B:

- Lišta sa načíta až po GTM, takže consent default musí byť **v tom istom
  tagu**, na spúšťači *Consent Initialization – All Pages* (ten beží pred
  všetkým ostatným). Nič iné nesmie na tento spúšťač ísť skôr.
- Preklady a linky na dokumenty sú zadrôtované v tagu, nie na webe.
- CSS/JS z jsDelivr CDN; ak by CDN padlo, lišta sa nezobrazí a Google tagy
  ostanú v denied (bezpečné, ale bez dát).
- `data-category` skripty webu (časť 3.3) sa nedajú použiť — všetko, čo má
  čakať na súhlas, musí byť tag v kontajneri.

Postup:

1. **Tag „CMP – CookieConsent"** typu *Custom HTML*, spúšťač *Consent
   Initialization – All Pages*, obsah:

   ```html
   <script>
       window.dataLayer = window.dataLayer || [];
       function gtag() { dataLayer.push(arguments); }
       gtag('consent', 'default', {
           ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied',
           analytics_storage: 'denied', functionality_storage: 'denied',
           personalization_storage: 'denied', security_storage: 'granted',
           wait_for_update: 500
       });

       var css = document.createElement('link');
       css.rel = 'stylesheet';
       css.href = 'https://cdn.jsdelivr.net/gh/orestbida/cookieconsent@3.1.0/dist/cookieconsent.css';
       document.head.appendChild(css);

       var js = document.createElement('script');
       js.src = 'https://cdn.jsdelivr.net/gh/orestbida/cookieconsent@3.1.0/dist/cookieconsent.umd.js';
       js.onload = function () {
           /* sem vložiť CONSENT_TYPES, syncConsent a CookieConsent.run({...}) z časti 3.2 */
       };
       document.head.appendChild(js);
   </script>
   ```

   V *Advanced settings → Tag firing options* nastaviť *Once per page*.
   Consent settings tagu: *No additional consent required*.
2. Tag typu *Custom HTML* s CSS z časti 3.4 (alebo vložiť `<style>` do toho
   istého tagu).
3. Odkaz „Nastavenia cookies" v pätičke: ak sa dá do webu vložiť aspoň
   `<button data-cc="show-preferencesModal">`, lišta si ho nájde sama
   (musí existovať v momente `run()`; inak tag typu Custom HTML, ktorý
   tlačidlo vytvorí a pridá `onclick="CookieConsent.showPreferences()"`).
4. Kontajner nastaviť podľa časti 2 (Cookiebot von, consent spúšťače).

---

## 5. Overenie po nasadení (každý web)

- Nová anonymná relácia: lišta sa zobrazí, pred kliknutím **žiadne**
  `_ga`, `_fbp`, `_gcl_au` cookies, v GTM Preview → Consent všetko denied.
- „Iba nevyhnutné": lišta zmizne, ostáva len `cc_cookie` + session cookies,
  žiadne requesty na google-analytics/facebook/sklik.
- „Prijať všetky": consent granted, GA4 DebugView ukáže `page_view`, FB
  pixel pošle PageView, `cc_cookie` má `categories` so všetkými štyrmi.
- Pätička → Nastavenia cookies → odškrtnúť marketing → uložiť: `_fbp`,
  `_gcl_*` zmiznú (autoClear), ďalší page load pošle `ad_storage: denied`.
- Lighthouse/boti: `hideFromBots` je zapnuté, lišta sa im nezobrazí.

