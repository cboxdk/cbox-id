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
