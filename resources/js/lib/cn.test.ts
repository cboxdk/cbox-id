import { describe, expect, it } from 'vitest';
import { cn } from './cn';

describe('cn', () => {
    it('lets a caller override a component default rather than emitting both', () => {
        // The whole reason this helper exists: without the merge, both classes survive
        // and which one wins is decided by stylesheet order, which the caller cannot see.
        expect(cn('px-4 py-2', 'px-6')).toBe('py-2 px-6');
    });

    it('drops falsy values so a conditional class needs no ternary at the call site', () => {
        const isPrimary = false;

        expect(cn('btn', isPrimary && 'btn-primary', undefined, 'btn-sm')).toBe('btn btn-sm');
    });

    it('leaves non-Tailwind class names alone, which is most of this design system', () => {
        expect(cn('cbx-panel', 'cbx-panel-body')).toBe('cbx-panel cbx-panel-body');
    });
});
