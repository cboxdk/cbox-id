/**
 * ⌘K's conversation with the server: what it asks (`GET /admin/search?q=`), what comes back
 * (`App\Platform\Console\ConsoleSearch` + `PaletteActions`), and the few recent records this
 * browser remembers.
 *
 * Kept out of the component so the rules a person would notice getting wrong — a key pasted
 * whole is never sent, a one-letter query asks nothing, the recent list does not grow
 * forever — are tested as rules rather than through a rendered dialog.
 */

export interface SearchItem {
    kind: 'user' | 'organization' | 'app' | 'key' | 'audit';
    id: string;
    title: string;
    subtitle: string | null;
    href: string;
}

export interface SearchGroup {
    key: string;
    label: string;
    items: SearchItem[];
}

export interface PaletteAction {
    name: string;
    label: string;
    href: string;
}

export interface SearchResponse {
    query: string;
    /** The one record a pasted id named — Enter opens it. */
    jump: SearchItem | null;
    groups: SearchGroup[];
    actions: PaletteAction[];
}

/** Below this, the server answers nothing, so nothing is asked. */
export const MIN_QUERY = 2;

/** How much of a management key the console ever shows — and so all of one ever sent. */
export const KEY_PREFIX = 13;

/**
 * The query as it is sent: trimmed, and a pasted key cut to its prefix. A whole key in a
 * URL lands in access logs and browser history; its prefix is what the console shows anyway.
 */
export function searchQuery(raw: string): string {
    const term = raw.trim();

    return term.startsWith('cbid_') ? term.slice(0, KEY_PREFIX) : term;
}

/**
 * Where this console's search lives, or null where there is none — the operator's platform
 * console searches every environment on a page of its own.
 */
export function searchHref(altitude: string | undefined, platformMode: boolean): string | null {
    if (platformMode || altitude === undefined) {
        return null;
    }

    return altitude === 'environment' ? '/admin/search' : '/search';
}

export function searchUrl(base: string, query: string): string {
    return `${base}?q=${encodeURIComponent(query)}`;
}

export const RECENT_KEY = 'cbx.palette.recent';

const RECENT_LIMIT = 5;

/**
 * What was opened last, newest first, without duplicates. Per-browser convenience only — a
 * private window, a blocked store or a parse error is just an empty list.
 */
export function readRecent(
    storage: Pick<Storage, 'getItem'> | undefined,
    scope: string,
): SearchItem[] {
    try {
        const raw = storage?.getItem(`${RECENT_KEY}:${scope}`);
        const parsed: unknown = raw == null ? [] : JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed
                  .filter(
                      (item): item is SearchItem =>
                          typeof item === 'object' &&
                          item !== null &&
                          typeof (item as SearchItem).href === 'string' &&
                          typeof (item as SearchItem).title === 'string',
                  )
                  .slice(0, RECENT_LIMIT)
            : [];
    } catch {
        return [];
    }
}

export function rememberRecent(
    storage: Pick<Storage, 'getItem' | 'setItem'> | undefined,
    scope: string,
    item: SearchItem,
): SearchItem[] {
    const next = [
        item,
        ...readRecent(storage, scope).filter((other) => other.href !== item.href),
    ].slice(0, RECENT_LIMIT);

    try {
        storage?.setItem(`${RECENT_KEY}:${scope}`, JSON.stringify(next));
    } catch {
        // A full or blocked store: the palette still works, it just forgets.
    }

    return next;
}

/**
 * How ⌘K ranks what it offers: cmdk's own fuzzy score, except that a page one of whose
 * KEYWORDS is exactly what was typed comes first.
 *
 * Without it "SAML" put "SAML apps" — the outbound direction, which merely has the word in
 * its label — above Enterprise SSO, whose keywords say SAML is what it is. A keyword is a
 * statement that this page IS the thing typed; a letter-by-letter match in a label is only
 * a guess, so the guess is held just below a statement.
 */
export function paletteScore(
    fuzzy: (value: string, search: string, keywords?: string[]) => number,
    value: string,
    search: string,
    keywords?: string[],
): number {
    const typed = search.trim().toLowerCase();

    if (typed !== '' && keywords?.some((keyword) => keyword.toLowerCase() === typed)) {
        return 1;
    }

    return fuzzy(value, search, keywords) * 0.95;
}
