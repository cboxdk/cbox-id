import { usePage } from '@inertiajs/react';
import { Fragment, createElement, useEffect, useMemo, type ReactNode } from 'react';
import type { SharedProps } from '@/types';
import type { MessageKey } from './keys';

export type { MessageKey } from './keys';

/**
 * THE SHARED PAGE TRANSLATOR — deliberately small, and deliberately Laravel-shaped.
 *
 * The catalogue is the server's: `lang/{locale}/*.php`, the same files PHP renders the
 * page title, the validation errors and the mail from. A page receives its own group's
 * strings as the `i18n` shared prop ({@see `App\Platform\Locale\HostedTranslations`}),
 * flattened to dot keys, so there is one source of truth and one placeholder syntax —
 * Laravel's `:name` — on both sides of the wire.
 *
 * WHY NOT A LIBRARY. What the pages need is lookup, `:placeholder` interpolation,
 * a React element in a placeholder ("sent to <b>{email}</b>"), and the plural forms
 * Laravel's `trans_choice` already understands. A library would
 * bring its own message format, which the PHP side cannot read, and a second catalogue
 * shape to keep in step with the first.
 *
 * WHY KEYS ARE TYPED. `MessageKey` is generated from the English catalogue by
 * `php artisan i18n:types`, so a page that asks for a key English does not have fails
 * `tsc`, not a person reading the page in Swedish. LangParityTest holds the other supported
 * languages to the same key set.
 */

export type Params = Record<string, string | number>;

export interface Translator {
    /** The language the page is drawn in — `en`, `da`, … */
    locale: string;
    /** A line, with `:placeholders` filled. */
    t: (key: MessageKey, params?: Params) => string;
    /**
     * A line whose placeholders are React nodes — a bolded address, a link. The text
     * around them stays translatable as one sentence, which is the point: a sentence split
     * into three keys around a `<b>` cannot be reordered by a language that puts the
     * object first.
     */
    rich: (key: MessageKey, params: Record<string, ReactNode>) => ReactNode;
    /**
     * A counted line in Laravel's locale-specific plural order — `:count` is filled in.
     * Chosen with `Intl.PluralRules`, so French's "0 is singular" is French's call.
     */
    choice: (key: MessageKey, count: number, params?: Params) => string;
}

type Messages = Readonly<Record<string, string>>;

/**
 * A line, or its key when the catalogue has none — visible in review, rather than an
 * empty string that renders as a button with nothing on it.
 */
export function lookup(messages: Messages, key: string): string {
    return Object.prototype.hasOwnProperty.call(messages, key) ? (messages[key] ?? key) : key;
}

/**
 * Fill `:name` placeholders. Longest name first, so `:organization` is never mistaken for
 * `:org` followed by "anization" — the same order Laravel's own replacer uses.
 */
export function interpolate(line: string, params: Params = {}): string {
    const names = Object.keys(params).sort((a, b) => b.length - a.length);
    if (names.length === 0) return line;
    const pattern = new RegExp(
        `:(${names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'g',
    );
    // Replace only placeholders in the source; a user value may itself contain ":name".
    return line.replace(pattern, (_match, name: string) => String(params[name]));
}

/** Pick Laravel's plural index for the supported locales and integer UI counts. */
export function pluralise(line: string, count: number, locale: string): string {
    const forms = line.split('|');

    if (forms.length < 2) {
        return line;
    }

    const language = locale.toLowerCase().split(/[-_]/)[0];
    // Laravel treats Turkish as invariant. European Portuguese uses singular only for 1.
    const category = new Intl.PluralRules(language === 'pt' ? 'pt-PT' : locale).select(count);
    const index =
        language === 'tr'
            ? 0
            : forms.length >= 3
              ? category === 'one'
                  ? 0
                  : category === 'few'
                    ? 1
                    : 2
              : category === 'one'
                ? 0
                : 1;

    return forms[index] ?? forms[0] ?? line;
}

/** Split a line on `:placeholders` and put a React node in each. */
export function interpolateRich(line: string, params: Record<string, ReactNode>): ReactNode {
    const names = Object.keys(params).sort((a, b) => b.length - a.length);

    if (names.length === 0) {
        return line;
    }

    const pattern = new RegExp(
        `:(${names.map((name) => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'g',
    );
    const parts: ReactNode[] = [];
    let last = 0;

    for (const match of line.matchAll(pattern)) {
        const index = match.index ?? 0;

        if (index > last) {
            parts.push(line.slice(last, index));
        }

        parts.push(
            createElement(Fragment, { key: `${match[1]}-${index}` }, params[match[1] ?? '']),
        );
        last = index + match[0].length;
    }

    if (last < line.length) {
        parts.push(line.slice(last));
    }

    return createElement(Fragment, null, ...parts);
}

export function createTranslator(locale: string, messages: Messages): Translator {
    return {
        locale,
        t: (key, params) => interpolate(lookup(messages, key), params),
        rich: (key, params) => interpolateRich(lookup(messages, key), params),
        choice: (key, count, params) =>
            interpolate(pluralise(lookup(messages, key), count, locale), { count, ...params }),
    };
}

const EMPTY: Messages = Object.freeze({});

/**
 * The translator for hosted and console pages. Missing keys remain visible so an
 * incomplete catalog is detectable rather than silently changing user-visible values.
 */
export function useTranslator(): Translator {
    const { i18n } = usePage<SharedProps>().props;
    const locale = i18n?.locale ?? 'en';
    const messages = i18n?.messages ?? EMPTY;

    return useMemo(() => createTranslator(locale, messages), [locale, messages]);
}

/**
 * Keep `<html lang>` true across client-side visits.
 *
 * The root view writes it on the first byte from the server's locale, but Inertia never
 * re-renders `<html>`. Each layout states the resolved page language so assistive
 * technology follows language changes during client-side navigation.
 */
export function useDocumentLanguage(locale: string): void {
    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);
}
