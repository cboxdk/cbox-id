import { afterEach, describe, expect, it, vi } from 'vitest';
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
