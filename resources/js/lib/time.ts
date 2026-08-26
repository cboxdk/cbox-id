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
export function relativeTime(iso: string): string {
    const seconds = Math.round((Date.parse(iso) - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

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

/** An absolute timestamp, in the reader's own locale and time zone. */
export function absoluteTime(iso: string): string {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}
