import { execFileSync } from 'node:child_process';
import type { SharedProps } from '@/types';

/** Tests consume the real PHP catalogue, not a second copy of English labels. */
export function consoleI18n(
    locale: 'en' | 'da' | 'de' | 'sv' | 'nb' | 'fr' | 'fi' | 'es' | 'it' | 'nl' = 'en',
): NonNullable<SharedProps['i18n']> {
    const messages = JSON.parse(
        execFileSync(
            'php',
            [
                '-r',
                `$messages = []; foreach (require 'lang/${locale}/console.php' as $key => $text) { $messages['console.'.$key] = $text; } echo json_encode($messages, JSON_THROW_ON_ERROR);`,
            ],
            { encoding: 'utf8' },
        ),
    ) as Record<string, string>;

    return {
        locale,
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
