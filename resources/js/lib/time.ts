import { formatLocale, formatTimeZone } from './formats';

/**
 * "4 minutes ago", computed in the browser.
 *
 * Deliberately not formatted on the server. A relative time rendered once is wrong from
 * the second frame on, and the console is full of pages people leave open — waiting for a
 * retry, watching a deploy, holding an activity log.
 *
 * `Intl.RelativeTimeFormat` rather than a formatting library: it is in every browser this
 * app supports, it speaks the reader's own locale, and it is already loaded.
 */
export function relativeTime(iso: string, locale: string = 'en'): string {
    const seconds = Math.round((Date.parse(iso) - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 7],
    ];

    let value = seconds;

    for (const [unit, step] of units) {
        if (Math.abs(value) < step) {
            return format.format(Math.round(value), unit);
        }

        value /= step;
    }

    return format.format(Math.round(value), 'week');
}

/**
 * An absolute timestamp, in the reader's regional format (else the interface language) and
 * their chosen time zone (else the device's), as the account menu set them ({@see ./formats}).
 */
export function absoluteTime(iso: string, locale: string = 'en'): string {
    return new Intl.DateTimeFormat(formatLocale(locale), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: formatTimeZone(),
    }).format(new Date(iso));
}
