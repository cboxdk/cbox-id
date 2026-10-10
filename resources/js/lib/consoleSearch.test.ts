import { defaultFilter } from 'cmdk';
import {
    paletteScore,
    readRecent,
    rememberRecent,
    type SearchItem,
    searchHref,
    searchQuery,
} from './consoleSearch';

function memoryStorage(): Pick<Storage, 'getItem' | 'setItem'> & { data: Map<string, string> } {
    const data = new Map<string, string>();

    return {
        data,
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => void data.set(key, value),
    };
}

function item(id: string): SearchItem {
    return { kind: 'user', id, title: `User ${id}`, subtitle: null, href: `/admin/users/${id}` };
}

describe('console search', () => {
    it('never sends a whole management key — only its prefix', () => {
        expect(searchQuery('  cbid_env_ab12CDEFGHIJKLMNOPQRSTUVWXYZ  ')).toBe('cbid_env_ab12');
        expect(searchQuery(' ada@example.com ')).toBe('ada@example.com');
    });

    it('searches the console it is on, and nowhere on the operator console', () => {
        expect(searchHref('environment', false)).toBe('/admin/search');
        expect(searchHref('organization', false)).toBe('/search');
        expect(searchHref('environment', true)).toBeNull();
        expect(searchHref(undefined, false)).toBeNull();
    });

    it('remembers the last five records opened, newest first, without repeats, per host', () => {
        const storage = memoryStorage();

        for (const id of ['1', '2', '3', '4', '5', '6']) {
            rememberRecent(storage, 'acme.cboxid.com', item(id));
        }

        rememberRecent(storage, 'acme.cboxid.com', item('4'));

        expect(readRecent(storage, 'acme.cboxid.com').map((recent) => recent.id)).toEqual([
            '4',
            '6',
            '5',
            '3',
            '2',
        ]);
        expect(readRecent(storage, 'other.cboxid.com')).toEqual([]);
    });

    it('treats a broken or blocked store as empty', () => {
        expect(readRecent({ getItem: () => '{nope' }, 'x')).toEqual([]);
        expect(
            readRecent(
                {
                    getItem: () => {
                        throw new Error('blocked');
                    },
                },
                'x',
            ),
        ).toEqual([]);
        expect(readRecent(undefined, 'x')).toEqual([]);
        expect(
            rememberRecent(
                {
                    getItem: () => null,
                    setItem: () => {
                        throw new Error('full');
                    },
                },
                'x',
                item('1'),
            ),
        ).toHaveLength(1);
    });
});

describe('paletteScore', () => {
    it('puts a page whose keyword IS the query above one that only spells it in its label', () => {
        const sso = paletteScore(defaultFilter, 'go Authentication Enterprise SSO', 'saml', [
            'SAML',
            'OIDC',
        ]);
        const samlApps = paletteScore(defaultFilter, 'go Advanced SAML apps', 'saml', ['SAML IdP']);

        expect(sso).toBe(1);
        expect(samlApps).toBeGreaterThan(0);
        expect(samlApps).toBeLessThan(sso);
    });

    it('still matches by label, and matches nothing that does not match', () => {
        expect(
            paletteScore(defaultFilter, 'go Developers Applications', 'appl', []),
        ).toBeGreaterThan(0);
        expect(paletteScore(defaultFilter, 'go Developers Applications', 'zzzz', ['SDK'])).toBe(0);
    });
});
