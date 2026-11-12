import { router, usePage } from '@inertiajs/react';
import { DropdownMenu as Primitive } from 'radix-ui';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import type { SharedProps } from '@/types';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger, Icon } from '@/ui';
import { update as updateLocale } from '@routes/locale';

/**
 * THE HOSTED PAGES' LANGUAGE PICKER — the Cbox UI menu the console's picker uses, not a
 * native `<select>`.
 *
 * The design system forbids the native control, and on this page the reason showed: it was
 * the one element the theme could not reach, an OS-grey box in the footer of an otherwise
 * Cbox page. Radix keeps what the native control was right about — keyboard operation
 * (arrows, Home/End, type-ahead), the choice announced as a checked radio item — and each
 * option is still written in its own language ("Dansk", "Deutsch") with its `lang`, so it
 * can be found by somebody who cannot read the page around it.
 *
 * Choosing posts to the server, which remembers the choice in a cookie and sends the person
 * back to the page they were on, re-rendered in the new language. Absent when only one
 * language is switched on: a picker with one option is a control that does nothing.
 */
export function LanguagePicker() {
    const { i18n } = usePage<SharedProps>().props;
    const { t } = useTranslator();
    const [saving, setSaving] = useState(false);

    if (i18n === null || i18n.locales.length < 2) {
        return null;
    }

    const current = i18n.locales.find((option) => option.code === i18n.locale);

    function choose(locale: string) {
        if (saving || i18n === null || locale === i18n.locale) {
            return;
        }

        setSaving(true);
        router.post(
            updateLocale.url(),
            { locale },
            {
                preserveScroll: true,
                preserveState: false,
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                disabled={saving}
                aria-label={t('hosted.language.label')}
                aria-busy={saving}
                className="auth-footer-control"
            >
                <span lang={i18n.locale}>{current?.name ?? i18n.locale}</span>
                <Icon name="chevron" className="w-3.5 h-3.5" />
            </DropdownMenuTrigger>
            <DropdownMenuContent className="max-h-[min(26rem,70dvh)] overflow-y-auto">
                <Primitive.RadioGroup value={i18n.locale} onValueChange={choose}>
                    {i18n.locales.map((option) => (
                        <Primitive.RadioItem
                            key={option.code}
                            value={option.code}
                            disabled={saving}
                            className="cbx-menuitem"
                        >
                            <span className="w-4 shrink-0">
                                <Primitive.ItemIndicator>
                                    <Icon name="check" className="w-4 h-4" />
                                </Primitive.ItemIndicator>
                            </span>
                            <span lang={option.code}>{option.name}</span>
                        </Primitive.RadioItem>
                    ))}
                </Primitive.RadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
