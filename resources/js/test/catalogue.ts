import { execFileSync } from 'node:child_process';
import type { SharedProps } from '@/types';

/** Tests consume the real PHP catalogue, not a second copy of English labels. */
export function consoleI18n(locale: 'en' | 'da' = 'en'): NonNullable<SharedProps['i18n']> {
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
        ],
        messages,
    };
}
