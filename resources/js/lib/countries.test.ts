import { describe, expect, it } from 'vitest';
import {
    allCountryCodes,
    countryOptions,
    flag,
    formatCountryList,
    parseCountryList,
} from './countries';

describe('countries', () => {
    it('draws a flag from the code', () => {
        expect(flag('DK')).toBe('🇩🇰');
        expect(flag('dk')).toBe('🇩🇰');
        expect(flag('DNK')).toBe('');
    });

    it('offers every country by flag and name, not by code', () => {
        const codes = allCountryCodes();

        expect(codes.length).toBeGreaterThan(200);
        expect(codes).toContain('DK');
        expect(codes).not.toContain('EU');

        const denmark = countryOptions(['DK', 'SE'], 'en').find((option) => option.value === 'DK');

        expect(denmark?.label).toBe('🇩🇰 Denmark');
        expect(countryOptions(['DK'], 'da')[0]?.label).toBe('🇩🇰 Danmark');
    });

    it('keeps the stored list a rule engine reads', () => {
        expect(parseCountryList('dk, SE  no')).toEqual(['DK', 'SE', 'NO']);
        expect(formatCountryList(['DK', 'SE'])).toBe('DK, SE');
    });
});
