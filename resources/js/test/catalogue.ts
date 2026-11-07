import { execFileSync } from 'node:child_process';
import type { SharedProps } from '@/types';

/** Tests consume the real PHP catalogue, not a second copy of English labels. */
export function consoleI18n(
    locale:
        | 'en'
        | 'da'
        | 'de'
        | 'sv'
        | 'nb'
        | 'fr'
        | 'fi'
        | 'es'
        | 'it'
        | 'nl'
        | 'pt'
        | 'pl'
        | 'ro'
        | 'cs'
        | 'hu'
        | 'el'
        | 'bg'
        | 'hr'
        | 'sk'
        | 'tr'
        | 'uk' = 'en',
): NonNullable<SharedProps['i18n']> {
    const messages = JSON.parse(
        execFileSync(
            'php',
            [
                '-r',
                `$messages = []; $flatten = function ($values, $prefix) use (&$flatten, &$messages) { foreach ($values as $key => $text) { is_array($text) ? $flatten($text, $prefix.$key.'.') : $messages[$prefix.$key] = $text; } }; foreach (['console', 'hosted'] as $group) { $flatten(require 'lang/${locale}/'.$group.'.php', $group.'.'); } echo json_encode($messages, JSON_THROW_ON_ERROR);`,
            ],
            { encoding: 'utf8' },
        ),
    ) as Record<string, string>;

    return {
        locale,
        preference: null,
        locales: [
            { code: 'en', name: 'English' },
            { code: 'da', name: 'Dansk' },
            { code: 'de', name: 'Deutsch' },
            { code: 'sv', name: 'Svenska' },
            { code: 'nb', name: 'Norsk bokmål' },
            { code: 'fr', name: 'Français' },
            { code: 'fi', name: 'Suomi' },
            { code: 'es', name: 'Español' },
            { code: 'it', name: 'Italiano' },
            { code: 'nl', name: 'Nederlands' },
        ],
        messages,
    };
}
