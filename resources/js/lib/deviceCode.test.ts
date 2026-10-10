import { describe, expect, it } from 'vitest';
import { formatDeviceCode } from './deviceCode';

describe('formatDeviceCode', () => {
    it.each([
        ['bcdfghjk', 'BCDF-GHJK'],
        ['bcdf-ghjk', 'BCDF-GHJK'],
        ['BCDF GHJK', 'BCDF-GHJK'],
        ['bcd', 'BCD'],
        ['bcdf', 'BCDF'],
        ['bcdfg', 'BCDF-G'],
        ['bcdfghjkxyz', 'BCDF-GHJK'],
        ['', ''],
    ])('formats %j as %j', (typed, shown) => {
        expect(formatDeviceCode(typed)).toBe(shown);
    });
});
