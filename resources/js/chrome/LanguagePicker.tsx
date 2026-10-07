import { router, usePage } from '@inertiajs/react';
import { useId } from 'react';
import { useTranslator } from '@/i18n';
import type { SharedProps } from '@/types';
import { update as updateLocale } from '@routes/locale';

/**
 * THE HOSTED PAGES' LANGUAGE PICKER — a native `<select>`, on purpose.
 *
 * It sits on pages people reach before they have an account, often on a phone, sometimes
 * in a language they cannot read. A native control is the one every platform already
 * knows how to operate with a screen reader, a keyboard and a thumb, and each option is
 * written in its own language ("Dansk", "Deutsch") so it can be found without reading
 * the page around it.
 *
 * Choosing posts to the server, which remembers the choice in a cookie and sends the
 * person back to the page they were on — re-rendered in the new language by the same
 * request that re-reads the catalogue. Absent when only one language is switched on: a
 * picker with one option is a control that does nothing.
 */
export function LanguagePicker() {
    const { i18n } = usePage<SharedProps>().props;
    const { t } = useTranslator();
    const id = useId();

    if (i18n === null || i18n.locales.length < 2) {
        return null;
    }

    return (
        <span className="inline-flex items-center">
            <label htmlFor={id} className="sr-only">
                {t('hosted.language.label')}
            </label>
            <select
                id={id}
                value={i18n.locale}
                onChange={(event) =>
                    router.post(
                        updateLocale.url(),
                        { locale: event.target.value },
                        { preserveScroll: true, preserveState: false },
                    )
                }
                className="rounded-md px-2 py-1 text-xs transition hover:opacity-80"
                style={{
                    border: '1px solid var(--border)',
                    background: 'transparent',
                    color: 'inherit',
                }}
            >
                {i18n.locales.map((option) => (
                    <option key={option.code} value={option.code} lang={option.code}>
                        {option.name}
                    </option>
                ))}
            </select>
        </span>
    );
}
