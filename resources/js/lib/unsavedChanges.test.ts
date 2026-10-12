import { describe, expect, it } from 'vitest';
import { leavesPage, type VisitShape } from './unsavedChanges';

const visit = (changes: Partial<VisitShape> = {}): VisitShape => ({
    method: 'get',
    url: new URL('https://id.test/admin/settings'),
    only: [],
    except: [],
    prefetch: false,
    ...changes,
});

describe('leavesPage', () => {
    it('counts a GET for another page as leaving', () => {
        expect(leavesPage(visit())).toBe(true);
    });

    it('does not count the page saving itself', () => {
        expect(leavesPage(visit({ method: 'post' }))).toBe(false);
        expect(leavesPage(visit({ method: 'put' }))).toBe(false);
    });

    it('does not count a partial reload or a prefetch, which replace nothing on screen', () => {
        expect(leavesPage(visit({ only: ['checklist'] }))).toBe(false);
        expect(leavesPage(visit({ except: ['help'] }))).toBe(false);
        expect(leavesPage(visit({ prefetch: true }))).toBe(false);
    });
});
