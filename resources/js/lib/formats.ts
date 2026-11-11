/**
 * THE PERSON'S REGIONAL FORMAT AND TIME ZONE, chosen in the account menu (the rail's foot)
 * or the ⌘K palette.
 *
 * Kept in two cookies, like the theme ({@see ./theme}), so the server puts them in
 * `shell.preferences` before the first paint ({@see \App\Platform\Shell\IdShell}) and every
 * page formats a date the same way from its first frame. Unset means "Automatic": the
 * interface language's own format and the device's time zone.
 */
export const FORMAT_COOKIE = 'cbox-format';
export const TIME_ZONE_COOKIE = 'cbox-time-zone';

const YEAR = 60 * 60 * 24 * 365;

/** The regional formats offered: one per interface language, plus US English. */
export const FORMAT_CODES = [
    'en-GB',
    'en-US',
    'da-DK',
    'de-DE',
    'sv-SE',
    'nb-NO',
    'fi-FI',
    'fr-FR',
    'es-ES',
    'it-IT',
    'nl-NL',
    'pl-PL',
    'pt-PT',
    'cs-CZ',
    'sk-SK',
    'hu-HU',
    'ro-RO',
    'bg-BG',
    'hr-HR',
    'el-GR',
    'tr-TR',
    'uk-UA',
] as const;

let current: { regionalFormat?: string; timeZone?: string } = {};

/** What the shell says this person chose; read by {@see formatLocale()} and {@see formatTimeZone()}. */
export function setFormatPreferences(preferences: {
    regionalFormat?: string;
    timeZone?: string;
}): void {
    current = { regionalFormat: preferences.regionalFormat, timeZone: preferences.timeZone };
}

/** The locale a date or number is formatted in: the chosen regional format, else the interface language. */
export function formatLocale(language: string): string {
    return current.regionalFormat ?? language;
}

/** The chosen time zone, or undefined for the device's own. */
export function formatTimeZone(): string | undefined {
    return current.timeZone;
}

/** A format's name in its own language: "dansk (Danmark)", "English (United States)". */
export function formatLabel(code: string): string {
    try {
        const name = new Intl.DisplayNames([code], {
            type: 'language',
            languageDisplay: 'standard',
        }).of(code);

        return name === undefined || name === ''
            ? code
            : name.charAt(0).toLocaleUpperCase(code) + name.slice(1);
    } catch {
        return code;
    }
}

/** Remember a choice for the next first paint; an empty value forgets it ("Automatic"). */
export function writeFormatCookie(name: string, value: string | undefined): void {
    const secure = location.protocol === 'https:' ? '; secure' : '';

    document.cookie =
        value === undefined || value === ''
            ? `${name}=; path=/; max-age=0; samesite=lax${secure}`
            : `${name}=${encodeURIComponent(value)}; path=/; max-age=${YEAR}; samesite=lax${secure}`;
}
