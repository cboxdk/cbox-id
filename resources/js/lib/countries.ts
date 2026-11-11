/**
 * COUNTRIES, AS PEOPLE PICK THEM: a flag and the country's name in the reader's language,
 * found by typing. Never an ISO code to type (policy, Sylvester 2026-11-11: "Country iso er
 * totalt uacceptabelt"). The code is what is stored and sent; it is never what is asked for.
 */

/** Codes the platform's name table knows that are not countries one picks. */
const NOT_COUNTRIES = new Set(['EU', 'EZ', 'UN', 'ZZ', 'QO', 'XA', 'XB']);

/** The flag emoji for an ISO 3166-1 alpha-2 code ("DK" → 🇩🇰). */
export function flag(code: string): string {
    const upper = code.toUpperCase();

    if (!/^[A-Z]{2}$/.test(upper)) {
        return '';
    }

    return String.fromCodePoint(...[...upper].map((letter) => 0x1f1e6 + letter.charCodeAt(0) - 65));
}

function names(locale: string): Intl.DisplayNames | null {
    try {
        return new Intl.DisplayNames([locale, 'en'], { type: 'region', fallback: 'none' });
    } catch {
        return null;
    }
}

/** The country's name in `locale`, else its code. */
export function countryName(code: string, locale = 'en'): string {
    return names(locale)?.of(code.toUpperCase()) ?? code.toUpperCase();
}

let every: string[] | null = null;

/** Every country this browser can name, as codes. */
export function allCountryCodes(): string[] {
    if (every !== null) {
        return every;
    }

    const table = names('en');
    const codes: string[] = [];

    for (let a = 65; a <= 90; a++) {
        for (let b = 65; b <= 90; b++) {
            const code = String.fromCharCode(a, b);

            if (NOT_COUNTRIES.has(code)) {
                continue;
            }

            const name = table?.of(code);

            if (name !== undefined && name !== code) {
                codes.push(code);
            }
        }
    }

    every = codes;

    return codes;
}

/** Type-ahead options: "🇩🇰 Denmark", sorted by name, for the given codes or every country. */
export function countryOptions(
    codes: readonly string[] = allCountryCodes(),
    locale = 'en',
): { value: string; label: string }[] {
    const collator = new Intl.Collator(locale);

    return codes
        .map((code) => ({ value: code.toUpperCase(), name: countryName(code, locale) }))
        .sort((left, right) => collator.compare(left.name, right.name))
        .map(({ value, name }) => ({ value, label: `${flag(value)} ${name}` }));
}

/** "DK, SE" ⇄ ["DK", "SE"]: how a rule stores a list of countries. */
export function parseCountryList(value: string): string[] {
    return value
        .split(/[\s,]+/)
        .map((code) => code.trim().toUpperCase())
        .filter((code) => /^[A-Z]{2}$/.test(code));
}

export function formatCountryList(codes: readonly string[]): string {
    return codes.join(', ');
}
