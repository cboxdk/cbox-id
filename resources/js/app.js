import './bootstrap';

// Theme: honour the stored preference, else the OS. Applied to <html data-theme>.
// Kept in bundled (same-origin) JS so a strict CSP needs no inline script.
(() => {
    const root = document.documentElement;
    const stored = localStorage.getItem('cbox-theme');

    const apply = (theme) => {
        if (theme === 'light' || theme === 'dark') {
            root.setAttribute('data-theme', theme);
        } else {
            root.removeAttribute('data-theme');
        }
    };

    apply(stored);

    window.cboxToggleTheme = () => {
        const current =
            root.getAttribute('data-theme') ||
            (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        const next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem('cbox-theme', next);
        apply(next);
    };

    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-theme-toggle]');
        if (el) {
            e.preventDefault();
            window.cboxToggleTheme();
        }
    });
})();

// WebAuthn / passkeys. Same-origin fetches only (CSP connect-src 'self').
(() => {
    const enc = (buf) =>
        btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const dec = (str) => {
        const b64 = str.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(str.length / 4) * 4, '=');
        return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const post = async (url, body) => {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body ?? {}),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Request failed');
        return data;
    };

    const supported = () => typeof window.PublicKeyCredential !== 'undefined';

    async function register(name) {
        const opts = await post('/passkeys/register/options');
        const publicKey = {
            ...opts,
            challenge: dec(opts.challenge),
            user: { ...opts.user, id: dec(opts.user.id) },
            excludeCredentials: (opts.excludeCredentials || []).map((c) => ({ ...c, id: dec(c.id) })),
        };
        const cred = await navigator.credentials.create({ publicKey });
        await post('/passkeys/register', {
            name,
            id: cred.id,
            type: cred.type,
            response: {
                clientDataJSON: enc(cred.response.clientDataJSON),
                attestationObject: enc(cred.response.attestationObject),
                transports: cred.response.getTransports ? cred.response.getTransports() : [],
            },
        });
    }

    async function login() {
        const opts = await post('/passkeys/login/options');
        const publicKey = {
            ...opts,
            challenge: dec(opts.challenge),
            allowCredentials: (opts.allowCredentials || []).map((c) => ({ ...c, id: dec(c.id) })),
        };
        const cred = await navigator.credentials.get({ publicKey });
        const data = await post('/passkeys/login', {
            id: cred.id,
            type: cred.type,
            response: {
                clientDataJSON: enc(cred.response.clientDataJSON),
                authenticatorData: enc(cred.response.authenticatorData),
                signature: enc(cred.response.signature),
                userHandle: cred.response.userHandle ? enc(cred.response.userHandle) : null,
            },
        });
        if (data.redirect) window.location.assign(data.redirect);
    }

    const feedback = (el, message, ok = false) => {
        const target = el.getAttribute('data-passkey-feedback');
        const node = target ? document.getElementById(target) : null;
        if (node) {
            node.textContent = message;
            node.style.color = ok ? 'var(--success)' : 'var(--danger)';
        }
    };

    const run = async (el, fn, busyText) => {
        if (!supported()) {
            feedback(el, 'This browser does not support passkeys.');
            return false;
        }
        const original = el.textContent;
        el.setAttribute('disabled', 'true');
        el.textContent = busyText;
        try {
            await fn();
            feedback(el, 'Success.', true);
            return true;
        } catch (e) {
            if (e?.name !== 'NotAllowedError') feedback(el, e.message || 'Passkey failed.');
            return false;
        } finally {
            el.removeAttribute('disabled');
            el.textContent = original;
        }
    };

    document.addEventListener('click', (e) => {
        const loginEl = e.target.closest('[data-passkey-login]');
        if (loginEl) {
            e.preventDefault();
            run(loginEl, login, 'Waiting for passkey…');
            return;
        }
        const regEl = e.target.closest('[data-passkey-register]');
        if (regEl) {
            e.preventDefault();
            run(regEl, () => register(regEl.getAttribute('data-passkey-name') || 'Passkey'), 'Follow the prompt…').then(
                (ok) => ok && window.location.reload()
            );
        }
    });

    // Hide passkey affordances entirely where unsupported.
    document.addEventListener('DOMContentLoaded', () => {
        if (!supported()) {
            document.querySelectorAll('[data-passkey-only]').forEach((el) => (el.style.display = 'none'));
        }
    });
})();

// Livewire error UX. By default Livewire drops the raw server error page into a
// full-screen modal on any non-2xx component request — jarring in production.
// Intercept failures and show a branded, calm error surface instead (the
// Livewire equivalent of an Inertia error boundary). Same-origin, no inline JS.
(() => {
    const MESSAGES = {
        419: {
            title: 'Your session expired',
            body: 'For your security you were signed out after a period of inactivity. Reload to continue.',
            cta: 'Reload',
        },
        429: {
            title: 'Too many requests',
            body: 'You are going a little fast. Wait a moment, then try again.',
            cta: 'Try again',
        },
        403: {
            title: 'Not allowed',
            body: 'You do not have permission to do that.',
            cta: 'Reload',
        },
        500: {
            title: 'Something went wrong',
            body: 'An unexpected error occurred on our side. The team has been notified. Reloading usually helps.',
            cta: 'Reload page',
        },
        503: {
            title: 'Down for maintenance',
            body: 'The service is briefly unavailable. Please try again in a moment.',
            cta: 'Retry',
        },
    };

    // Minimal escaping — trace ids are hex, but never inject untrusted text raw.
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    // A trace id turns "something broke" into "here's the exact request to open
    // in telemetry". Rendered as a copyable pill, mirroring the full-page views.
    const traceRow = (traceId) =>
        !traceId ? '' :
        '<div style="margin-top:1.4rem;padding-top:1.1rem;border-top:1px solid var(--border)">' +
        '<button data-copy-trace="' + esc(traceId) + '" title="Copy trace ID" ' +
        'style="display:inline-flex;align-items:center;gap:.45rem;background:var(--bg);border:1px solid var(--border);border-radius:.5rem;padding:.35rem .6rem;cursor:pointer;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.72rem;color:var(--text)">' +
        '<span style="color:var(--muted)">Trace ID</span><span>' + esc(traceId) + '</span></button>' +
        '<span data-copy-feedback aria-live="polite" style="display:block;font-size:.68rem;color:var(--accent);margin-top:.35rem;min-height:1em;visibility:hidden">Copied</span></div>';

    const show = (status, traceId) => {
        if (document.getElementById('cbox-error-overlay')) return;
        const m = MESSAGES[status] || MESSAGES[500];

        const overlay = document.createElement('div');
        overlay.id = 'cbox-error-overlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'cbox-error-title');
        overlay.style.cssText =
            'position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:1.5rem;' +
            'background:color-mix(in srgb, var(--bg) 78%, transparent);backdrop-filter:blur(4px)';

        overlay.innerHTML =
            '<div style="max-width:26rem;width:100%;background:var(--surface);border:1px solid var(--border);' +
            'border-radius:1rem;padding:1.75rem;box-shadow:0 20px 50px -12px rgba(0,0,0,.35);text-align:center">' +
            '<div style="width:2.75rem;height:2.75rem;margin:0 auto .9rem;border-radius:.75rem;display:grid;place-items:center;' +
            'background:color-mix(in srgb, var(--danger) 14%, transparent);color:var(--danger)">' +
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>' +
            '<h2 id="cbox-error-title" style="font-size:1.05rem;font-weight:600;color:var(--text);margin:0 0 .4rem">' + m.title + '</h2>' +
            '<p style="font-size:.875rem;color:var(--muted);margin:0 0 1.25rem;line-height:1.5">' + m.body + '</p>' +
            '<div style="display:flex;gap:.6rem;justify-content:center">' +
            '<button data-cbox-reload style="background:var(--accent);color:#fff;border:0;border-radius:.6rem;padding:.55rem 1.1rem;font-size:.875rem;font-weight:500;cursor:pointer">' + m.cta + '</button>' +
            '<button data-cbox-dismiss style="background:transparent;color:var(--muted);border:1px solid var(--border);border-radius:.6rem;padding:.55rem 1.1rem;font-size:.875rem;cursor:pointer">Dismiss</button>' +
            '</div>' + traceRow(traceId) + '</div>';

        overlay.querySelector('[data-cbox-reload]').addEventListener('click', () => window.location.reload());
        overlay.querySelector('[data-cbox-dismiss]').addEventListener('click', () => overlay.remove());
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') overlay.remove(); });
        document.body.appendChild(overlay);
        overlay.querySelector('[data-cbox-reload]').focus();
    };

    const HEADER = 'X-Trace-Id'; // matches telemetry.traces.response_header

    document.addEventListener('livewire:init', () => {
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') return;
        window.Livewire.hook('request', ({ respond, fail }) => {
            // The failing AJAX response carries the trace id in its header; capture
            // it here so the branded surface can point straight at the trace.
            let traceId = null;
            if (typeof respond === 'function') {
                respond(({ response }) => {
                    try { traceId = response?.headers?.get(HEADER) || traceId; } catch (e) { /* opaque response */ }
                });
            }
            if (typeof fail !== 'function') return;
            fail(({ status, preventDefault }) => {
                if (status >= 400 && status !== 422) {   // 422 = validation, handled inline
                    if (typeof preventDefault === 'function') preventDefault();
                    show(status, traceId);
                }
            });
        });
    });

    // Copy-trace + reload controls used by both the overlay and the full-page
    // error views. Delegated + bundled so the strict CSP needs no inline script.
    document.addEventListener('click', (e) => {
        const reload = e.target.closest('[data-error-reload]');
        if (reload) { e.preventDefault(); window.location.reload(); return; }

        const copy = e.target.closest('[data-copy-trace]');
        if (copy) {
            e.preventDefault();
            const id = copy.getAttribute('data-copy-trace');
            const done = () => {
                const fb = copy.parentElement && copy.parentElement.querySelector('[data-copy-feedback]');
                if (fb) { fb.style.visibility = 'visible'; setTimeout(() => (fb.style.visibility = 'hidden'), 1600); }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(id).then(done).catch(() => {});
            }
        }
    });
})();
