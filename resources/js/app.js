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
