import { afterEach, describe, expect, it, vi } from 'vitest';
import { formatLabel, setFormatPreferences } from './formats';
import { absoluteTime, relativeTime } from './time';
afterEach(() => vi.useRealTimers());
describe('selected language formatting', () => {
    it('uses the app language for relative time, independently of the browser language', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-04T12:00:00Z'));
        expect(relativeTime('2026-11-04T11:55:00Z', 'da')).toBe('for 5 minutter siden');
        expect(relativeTime('2026-11-04T11:55:00Z', 'en')).toBe('5 minutes ago');
    });
    it('formats absolute dates with the selected language and the local time zone', () => {
        const date = '2026-11-04T12:00:00Z';
        for (const locale of ['da', 'en']) {
            expect(absoluteTime(date, locale)).toBe(
                new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(
                    new Date(date),
                ),
            );
        }
    });
});

describe("the account menu's regional format and time zone", () => {
    afterEach(() => setFormatPreferences({}));

    it('formats absolute dates in the chosen format and zone, else the language and the device', () => {
        const date = '2026-11-04T12:00:00Z';

        setFormatPreferences({ regionalFormat: 'en-US', timeZone: 'Asia/Tokyo' });
        expect(absoluteTime(date, 'da')).toBe(
            new Intl.DateTimeFormat('en-US', {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: 'Asia/Tokyo',
            }).format(new Date(date)),
        );

        setFormatPreferences({});
        expect(absoluteTime(date, 'da')).toBe(
            new Intl.DateTimeFormat('da', { dateStyle: 'medium', timeStyle: 'short' }).format(
                new Date(date),
            ),
        );
    });

    it('names each format in its own language', () => {
        expect(formatLabel('da-DK')).toBe('Dansk (Danmark)');
        expect(formatLabel('en-US')).toBe('English (United States)');
    });
});
