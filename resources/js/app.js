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
