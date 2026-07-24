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
        if (!res.ok) {
            // Sudo required: send the user to re-authenticate, then return here to
            // continue — never a dead end. Halt this flow (we're navigating away).
            if (res.status === 403 && data && typeof data.sudo === 'string') {
                window.location.assign(data.sudo);
                return new Promise(() => {});
            }
            throw new Error(data.error || 'Request failed');
        }
        return data;
    };

    const supported = () => typeof window.PublicKeyCredential !== 'undefined';

    async function register(name, base = '/passkeys') {
        const opts = await post(base + '/register/options');
        const publicKey = {
            ...opts,
            challenge: dec(opts.challenge),
            user: { ...opts.user, id: dec(opts.user.id) },
            excludeCredentials: (opts.excludeCredentials || []).map((c) => ({ ...c, id: dec(c.id) })),
        };
        const cred = await navigator.credentials.create({ publicKey });
        await post(base + '/register', {
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

    async function login(base = '/passkeys') {
        const opts = await post(base + '/login/options');
        const publicKey = {
            ...opts,
            challenge: dec(opts.challenge),
            allowCredentials: (opts.allowCredentials || []).map((c) => ({ ...c, id: dec(c.id) })),
        };
        const cred = await navigator.credentials.get({ publicKey });
        const data = await post(base + '/login', {
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
            const base = loginEl.getAttribute('data-passkey-base') || '/passkeys';
            run(loginEl, () => login(base), 'Waiting for passkey…');
            return;
        }
        const regEl = e.target.closest('[data-passkey-register]');
        if (regEl) {
            e.preventDefault();
            const base = regEl.getAttribute('data-passkey-base') || '/passkeys';
            run(regEl, () => register(regEl.getAttribute('data-passkey-name') || 'Passkey', base), 'Follow the prompt…').then(
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

// Generic copy-to-clipboard buttons. Any `<button data-copy="…">` copies its value;
// a nested `[data-copy-label]` flips to "Copied" briefly. Used for the 2FA setup key,
// recovery codes, and anywhere a value should be one tap to grab.
(() => {
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-copy]');
        if (!btn) return;
        e.preventDefault();
        const text = btn.getAttribute('data-copy') || '';
        if (!text || !(navigator.clipboard && navigator.clipboard.writeText)) return;

        navigator.clipboard.writeText(text).then(() => {
            const label = btn.querySelector('[data-copy-label]');
            if (!label || btn.dataset.copyBusy) return;
            btn.dataset.copyBusy = '1';
            const original = label.textContent;
            label.textContent = 'Copied';
            setTimeout(() => { label.textContent = original; delete btn.dataset.copyBusy; }, 1600);
        }).catch(() => {});
    });
})();

// ── Theme Editor — the live, client-side sign-in appearance editor.
// Registered as an Alpine component (bundled, so it runs under the strict CSP that
// forbids inline scripts). Editing and preview are entirely client-side for zero
// latency — Livewire is touched only on Save. The derivation here MIRRORS the PHP
// App\Platform\Appearance\AppearanceCss resolver so the live preview matches the
// rendered hosted page exactly.
document.addEventListener('alpine:init', () => {
    if (!window.Alpine) return;

    window.Alpine.data('themeEditor', (initial, presets, fonts, radii) => ({
        draft: JSON.parse(JSON.stringify(initial)),
        presets, fonts, radii,
        mode: 'light',       // which mode is being edited + previewed
        saved: false,
        copied: '',
        hexRe: /^#[0-9a-fA-F]{6}$/,

        get m() { return this.draft[this.mode]; },

        applyPreset(id) {
            const p = this.presets[id];
            if (!p) return;
            this.draft.preset = id;
            this.draft.radius = p.radius;
            this.draft.font = p.font;
            this.draft.light = Object.assign({}, p.light);
            this.draft.dark = Object.assign({}, p.dark);
        },

        setColor(token, value) {
            if (this.hexRe.test(value)) this.m[token] = value.toLowerCase();
        },

        fontStack(f) { return this.fonts[f] || this.fonts.system; },
        fontLabel(f) { return { system: 'System', geometric: 'Geometric', serif: 'Serif' }[f] || f; },
        radiusLabel(r) { return { '0rem': 'Square', '0.25rem': 'XS', '0.375rem': 'S', '0.5rem': 'M', '0.75rem': 'L', '1rem': 'XL' }[r] || r; },

        // WCAG maths — mirrors App\Platform\Appearance\Color.
        _chan(hex) { hex = hex.replace('#', ''); return [0, 2, 4].map((i) => parseInt(hex.substr(i, 2), 16) / 255); },
        _lum(hex) { const [r, g, b] = this._chan(hex).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)); return 0.2126 * r + 0.7152 * g + 0.0722 * b; },
        contrast(a, b) { const la = this._lum(a), lb = this._lum(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); },
        readable(bg) { return this.contrast(bg, '#ffffff') >= this.contrast(bg, '#151515') ? '#ffffff' : '#151515'; },

        // The AA readout for the currently-edited mode (primary on background).
        get aa() {
            const r = this.contrast(this.m.primary, this.m.background);
            return { ratio: r.toFixed(2), level: r >= 7 ? 'AAA' : r >= 4.5 ? 'AA' : r >= 3 ? 'AA Large' : 'Fail', pass: r >= 4.5 };
        },

        // CSS-variable map for the preview surface — identical derivation to the resolver.
        vars(mode) {
            const m = this.draft[mode];
            const on = this.readable(m.primary);
            return {
                // Mirror AppearanceCss::modeVars — --primary drives .btn-primary so the
                // preview's CTA shows the chosen colour at rest, matching what the server injects.
                '--primary': m.primary, '--primary-foreground': on,
                '--accent': m.primary, '--ring': m.primary, '--accent-foreground': on,
                '--accent-soft': `color-mix(in srgb, ${m.primary} 12%, transparent)`,
                '--accent-edge': `color-mix(in srgb, ${m.primary} 32%, transparent)`,
                '--background': m.background, '--foreground': m.foreground,
                '--card': m.background, '--card-foreground': m.foreground,
                '--secondary': `color-mix(in srgb, ${m.foreground} 6%, ${m.background})`, '--secondary-foreground': m.foreground,
                '--muted-foreground': m.muted, '--faint': `color-mix(in srgb, ${m.muted} 65%, ${m.background})`,
                '--border': `color-mix(in srgb, ${m.foreground} 14%, ${m.background})`,
                '--input': `color-mix(in srgb, ${m.foreground} 22%, ${m.background})`,
                '--radius': this.draft.radius, '--font-sans': this.fontStack(this.draft.font),
                background: m.background, color: m.foreground,
            };
        },

        // Exported CSS — same shape the server injects.
        get exportedCss() {
            const block = (mode) => {
                const v = this.vars(mode);
                delete v.background; delete v.color;
                if (mode === 'dark') { delete v['--radius']; delete v['--font-sans']; }
                return Object.entries(v).map(([k, val]) => `${k}:${val}`).join(';');
            };
            return `:root{${block('light')}}\n@media(prefers-color-scheme:dark){:root:not([data-theme='light']){${block('dark')}}}\n:root[data-theme='dark']{${block('dark')}}`;
        },

        copy(kind) {
            const text = kind === 'json' ? JSON.stringify(this.draft, null, 2) : this.exportedCss;
            if (!(navigator.clipboard && navigator.clipboard.writeText)) return;
            navigator.clipboard.writeText(text).then(() => { this.copied = kind; setTimeout(() => { this.copied = ''; }, 1500); }).catch(() => {});
        },

        save() {
            this.$wire.save(this.draft).then(() => { this.saved = true; setTimeout(() => { this.saved = false; }, 2200); });
        },
    }));
});
