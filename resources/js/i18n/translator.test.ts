import { isValidElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createElement } from 'react';
import { describe, expect, it } from 'vitest';
import { createTranslator, interpolate, lookup, pluralise } from './index';

describe('the hosted translator', () => {
    const messages = {
        'auth.login.title': 'Log ind',
        'auth.login.sent': 'Vi har sendt et link til :email.',
        'auth.org': ':organization kræver :org',
        'auth.codes': ':count kode tilbage|:count koder tilbage',
    };
    const { t, rich, choice } = createTranslator('da', messages);

    it('looks a line up, and answers with the key when there is none', () => {
        expect(lookup(messages, 'auth.login.title')).toBe('Log ind');
        expect(lookup(messages, 'auth.nope')).toBe('auth.nope');
        expect(lookup(messages, 'toString')).toBe('toString');
    });

    it('fills Laravel-style placeholders, longest name first', () => {
        expect(t('auth.login.sent' as never, { email: 'ada@acme.dk' })).toBe(
            'Vi har sendt et link til ada@acme.dk.',
        );
        expect(interpolate(':organization kræver :org', { org: 'SSO', organization: 'Acme' })).toBe(
            'Acme kræver SSO',
        );
    });

    it('puts React nodes into a whole sentence', () => {
        const node = rich('auth.login.sent' as never, {
            email: createElement('b', null, 'ada@acme.dk'),
        });

        expect(isValidElement(node)).toBe(true);
        expect(renderToStaticMarkup(createElement('p', null, node))).toBe(
            '<p>Vi har sendt et link til <b>ada@acme.dk</b>.</p>',
        );
    });

    it('picks the plural form the language asks for', () => {
        expect(choice('auth.codes' as never, 1)).toBe('1 kode tilbage');
        expect(choice('auth.codes' as never, 3)).toBe('3 koder tilbage');
        // French counts zero as singular; that is Intl's call, not ours.
        expect(pluralise('one|other', 0, 'fr')).toBe('one');
        expect(pluralise('one|other', 0, 'en')).toBe('other');
    });
});

describe('European plural forms', () => {
    it.each([
        ['pl', 1, 'one'],
        ['pl', 2, 'few'],
        ['pl', 5, 'other'],
        ['pl', 12, 'other'],
        ['pl', 22, 'few'],
        ['pl', 21, 'other'],
        ['uk', 21, 'one'],
        ['uk', 22, 'few'],
        ['uk', 11, 'other'],
        ['hr', 21, 'one'],
        ['hr', 24, 'few'],
        ['hr', 14, 'other'],
        ['cs', 2, 'few'],
        ['cs', 5, 'other'],
        ['sk', 3, 'few'],
        ['ro', 0, 'few'],
        ['ro', 19, 'few'],
        ['ro', 20, 'other'],
    ])('selects %s at %i', (locale, count, expected) => {
        expect(pluralise('one|few|other', Number(count), String(locale))).toBe(expected);
    });

    it('uses European Portuguese and invariant Turkish like Laravel', () => {
        expect(pluralise('one|other', 0, 'pt')).toBe('other');
        expect(pluralise('one|other', 5, 'tr')).toBe('one');
    });
});

it('does not reinterpret placeholders inside user-supplied values', () => {
    expect(interpolate(':name — :role', { name: 'Acme :role', role: 'Admin' })).toBe(
        'Acme :role — Admin',
    );
    expect(
        interpolate(':organization / :org', { organization: ':org company', org: 'customer' }),
    ).toBe(':org company / customer');
});
