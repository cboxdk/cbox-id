import type { ThemePreference } from '@/types';

const COOKIE = 'cbox-theme';
const YEAR = 60 * 60 * 24 * 365;

/**
 * WHICH THEME THIS DOCUMENT IS PAINTED IN, AND HOW IT CHANGES.
 *
 * The preference is a COOKIE, not localStorage, and that is the whole design. The server
 * cannot read localStorage, so a preference kept there could only be applied by a script
 * — and the bundle is an ES module, deferred by definition, so it runs after the first
 * paint. Every hard refresh painted the operating system's theme and then flipped.
 *
 * So the server decides ({@see \App\Platform\Theme}) and puts the attribute on `<html>`
 * before a byte of JavaScript exists. This only WRITES the choice and mirrors it onto the
 * document the person is already looking at.
 */
export function currentTheme(): 'light' | 'dark' {
    const explicit = document.documentElement.getAttribute('data-theme');

    if (explicit === 'light' || explicit === 'dark') {
        return explicit;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export function setTheme(theme: 'light' | 'dark'): void {
    // Lax rather than Strict: the console is reached from mailed links — invitations,
    // password resets — and a Strict cookie is withheld on that first cross-site
    // navigation, which would paint the wrong theme on precisely the page somebody
    // arrives at from their inbox.
    //
    // Not `secure` unconditionally, because local development is served over http on some
    // machines and a dropped cookie there looks exactly like this bug.
    const secure = location.protocol === 'https:' ? '; secure' : '';

    document.cookie = `${COOKIE}=${theme}; path=/; max-age=${YEAR}; samesite=lax${secure}`;
    document.documentElement.setAttribute('data-theme', theme);
}

export function toggleTheme(): 'light' | 'dark' {
    const next = currentTheme() === 'dark' ? 'light' : 'dark';

    setTheme(next);

    return next;
}

/**
 * A one-time move for anyone still carrying the old localStorage preference. They get the
 * server-rendered default for one paint and their own choice from then on; leaving it
 * would silently forget a preference somebody had already expressed.
 */
export function migrateLegacyThemePreference(serverPreference: ThemePreference): void {
    const legacy = localStorage.getItem(COOKIE);

    if (legacy !== 'light' && legacy !== 'dark') {
        return;
    }

    localStorage.removeItem(COOKIE);

    if (serverPreference === null) {
        setTheme(legacy);
    }
}

/** The sidebar pin state, read server-side to render the right rail width on first paint. */
export function setNavPinned(pinned: boolean): void {
    const secure = location.protocol === 'https:' ? '; secure' : '';

    document.cookie = `cbox-nav-pinned=${pinned ? '1' : '0'}; path=/; max-age=${YEAR}; samesite=lax${secure}`;
    document.documentElement.classList.toggle('cbx-nav-pinned', pinned);
}
