/*
 * Consent manager (specification chapter 12). The server tells the page which optional services the visitor already
 * granted (window config); nothing optional is fetched before that. A decision is posted to the server, which
 * answers with the new configuration. Withdrawal removes the storage the services manage and disables them.
 */
const loaded = new Set();
let config = readConfig();

function readConfig() {
    const el = document.getElementById('mr-consent-config');
    if (!el) {
        return null;
    }
    try {
        return JSON.parse(el.textContent || 'null');
    } catch {
        return null;
    }
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        return meta.getAttribute('content');
    }
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** A page path without ids or query strings: /recipes/123 → /recipes/:id */
function safePath() {
    return window.location.pathname.replace(/\/\d+(?=\/|$)/g, '/:id').replace(/\/[0-9a-f]{8}-[0-9a-f-]{27,}/gi, '/:uuid');
}

const adapters = {
    ga4(service) {
        const id = service.loader && service.loader.measurement_id;
        if (!id) {
            return;
        }
        window['ga-disable-' + id] = false;
        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
        if (!document.querySelector('script[data-mr-service="' + service.key + '"]')) {
            const script = document.createElement('script');
            script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
            script.dataset.mrService = service.key;
            document.head.appendChild(script);
            window.gtag('js', new Date());
            window.gtag('config', id, { send_page_view: false, anonymize_ip: true, allow_google_signals: false, allow_ad_personalization_signals: false });
        }
        return {
            pageView() {
                window.gtag('event', 'page_view', { page_location: window.location.origin + safePath(), page_path: safePath(), page_title: document.title.split(' - ')[0] });
            },
            track(name, properties) {
                window.gtag('event', name, properties);
            },
            disable() {
                window['ga-disable-' + id] = true;
            },
        };
    },
    script(service) {
        const src = service.loader && service.loader.src;
        if (!src || document.querySelector('script[data-mr-service="' + service.key + '"]')) {
            return;
        }
        const script = document.createElement('script');
        script.async = true;
        script.src = src;
        script.dataset.mrService = service.key;
        document.head.appendChild(script);
        return { pageView() {}, track() {}, disable() {} };
    },
};

const active = new Map();

function initServices() {
    if (!config || config.suppressed) {
        return;
    }
    for (const service of config.services || []) {
        if (loaded.has(service.key)) {
            continue; // SPA navigation must not initialise a service twice
        }
        const adapter = adapters[(service.loader && service.loader.type) || ''];
        if (!adapter) {
            continue;
        }
        const instance = adapter(service);
        if (instance) {
            active.set(service.key, instance);
            loaded.add(service.key);
        }
    }
}

function pageView() {
    if (!config || config.suppressed) {
        return;
    }
    for (const instance of active.values()) {
        instance.pageView();
    }
}

function clearManagedStorage(entries) {
    for (const entry of entries || []) {
        if (entry.kind === 'localStorage') {
            try { window.localStorage.removeItem(entry.name); } catch { /* storage unavailable */ }
            continue;
        }
        const names = entry.name.includes('*') ? cookieNamesMatching(entry.name) : [entry.name];
        for (const name of names) {
            for (const domain of ['', window.location.hostname, '.' + window.location.hostname]) {
                document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + (domain ? '; domain=' + domain : '');
            }
        }
    }
}

function cookieNamesMatching(pattern) {
    const regex = new RegExp('^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
    return document.cookie.split(';').map((c) => c.trim().split('=')[0]).filter((name) => regex.test(name));
}

function applyConfig(next) {
    const previous = config;
    // The page decides where analytics may run (admin, legal and payment pages never load it).
    next.suppressed = previous ? previous.suppressed : next.suppressed;
    config = next;
    const granted = new Set((config.services || []).map((s) => s.key));
    for (const [key, instance] of active) {
        if (!granted.has(key)) {
            instance.disable();
            active.delete(key);
        }
    }
    if (previous && (!config.decision || Object.values(config.decision.categories || {}).some((v) => !v))) {
        clearManagedStorage(config.managedStorage);
    }
    initServices();
    pageView();
}

async function submitDecision(action, categories) {
    if (!config) {
        return null;
    }
    const response = await fetch(config.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action, categories }),
    });
    if (!response.ok) {
        return null;
    }
    const data = await response.json();
    if (data && data.config) {
        applyConfig(data.config);
    }
    return data;
}

/** Public event API: allowlisted names only, no free text – see App\Services\Consent\AnalyticsEvents. */
window.mrAnalytics = {
    track(name, properties = {}) {
        if (!config || config.suppressed || !config.events || !config.events[name]) {
            return false;
        }
        const allowedKeys = config.events[name];
        const clean = {};
        for (const key of Object.keys(properties)) {
            const value = properties[key];
            if (allowedKeys.includes(key) && (typeof value === 'number' || typeof value === 'boolean' || (typeof value === 'string' && value.length <= 40))) {
                clean[key] = value;
            }
        }
        let sent = false;
        for (const instance of active.values()) {
            instance.track(name, clean);
            sent = true;
        }
        return sent;
    },
    open() {
        window.dispatchEvent(new CustomEvent('mr-consent-open'));
    },
};

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mrConsent', (bannerNeeded) => ({
        banner: bannerNeeded,
        settingsOpen: false,
        offered: (config && config.offered) || [],
        choices: Object.fromEntries(((config && config.offered) || []).map((c) => [c.key, !!(config && config.decision && config.decision.categories && config.decision.categories[c.key])])),
        openSettings() {
            this.settingsOpen = true;
        },
        async decide(action) {
            const data = await submitDecision(action, action === 'custom' ? this.choices : {});
            if (data && data.config) {
                this.banner = false;
                this.settingsOpen = false;
                this.offered = data.config.offered || [];
                this.choices = Object.fromEntries(this.offered.map((c) => [c.key, !!(data.config.decision && data.config.decision.categories[c.key])]));
            }
        },
    }));
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-consent-open]');
    if (trigger) {
        event.preventDefault();
        window.mrAnalytics.open();
    }
});

document.addEventListener('livewire:navigated', () => {
    const next = readConfig();
    if (next) {
        config = next;
    }
    initServices();
    pageView();
    const pending = document.getElementById('mr-analytics-event');
    if (pending) {
        try {
            const event = JSON.parse(pending.textContent || 'null');
            if (event && event.name) {
                window.mrAnalytics.track(event.name, event.properties || {});
            }
        } catch { /* ignore */ }
        pending.remove();
    }
});

initServices();
