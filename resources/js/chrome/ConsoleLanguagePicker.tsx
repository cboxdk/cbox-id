import { router, usePage } from '@inertiajs/react';
import { DropdownMenu as Primitive } from 'radix-ui';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import type { I18n, SharedProps } from '@/types';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger, Icon } from '@/ui';

/**
 * The signed-in person's saved interface language, and the one way to change it. Null when
 * there is nothing to choose: no saved preference, or one language.
 */
export function useLanguageChoice(): {
    i18n: I18n;
    current: string;
    saving: boolean;
    error: string | null;
    choose: (language: string) => void;
} | null {
    const { i18n } = usePage<SharedProps>().props;
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const preference = i18n?.preference;

    if (!i18n || !preference || i18n.locales.length < 2) return null;

    return {
        i18n,
        current: i18n.locales.find((option) => option.code === i18n.locale)?.name ?? i18n.locale,
        saving,
        error,
        choose(language: string) {
            if (saving || language === i18n.locale) return;
            setError(null);
            setSaving(true);
            router.post(
                preference.updateUrl,
                { language, subject_id: preference.subjectId, version: preference.version },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onError: (errors) => setError(Object.values(errors).join(' ')),
                    onFinish: () => setSaving(false),
                },
            );
        },
    };
}

/** The languages as radio items, for a menu: the picker below and the account menu's submenu. */
export function LanguageRadioItems({
    choice,
}: {
    choice: NonNullable<ReturnType<typeof useLanguageChoice>>;
}) {
    return (
        <Primitive.RadioGroup value={choice.i18n.locale} onValueChange={choice.choose}>
            {choice.i18n.locales.map((option) => (
                <Primitive.RadioItem
                    key={option.code}
                    value={option.code}
                    disabled={choice.saving}
                    className="cbx-menuitem"
                >
                    <span className="w-4 shrink-0">
                        <Primitive.ItemIndicator>
                            <Icon name="check" className="h-4 w-4" />
                        </Primitive.ItemIndicator>
                    </span>
                    <span lang={option.code}>{option.name}</span>
                </Primitive.RadioItem>
            ))}
        </Primitive.RadioGroup>
    );
}

/** Only an explicit selection writes the signed-in person's shared language preference. */
export function ConsoleLanguagePicker() {
    const { t } = useTranslator();
    const choice = useLanguageChoice();

    if (choice === null) return null;

    return (
        <div className="relative shrink-0">
            <DropdownMenu>
                <DropdownMenuTrigger
                    disabled={choice.saving}
                    aria-label={t('hosted.language.label')}
                    aria-busy={choice.saving}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-md px-2 text-sm hover:bg-[var(--surface-hover)] disabled:opacity-50"
                >
                    <span lang={choice.i18n.locale}>{choice.current}</span>
                    <Icon name="chevron" className="h-3.5 w-3.5" />
                </DropdownMenuTrigger>
                <DropdownMenuContent className="max-h-[min(26rem,70dvh)] overflow-y-auto">
                    <LanguageRadioItems choice={choice} />
                </DropdownMenuContent>
            </DropdownMenu>
            {choice.error && <LanguageError message={choice.error} />}
        </div>
    );
}

/** A refused save, said where the choice was made. */
export function LanguageError({ message }: { message: string }) {
    return (
        <p
            role="alert"
            className="absolute right-0 top-full z-50 mt-1 w-64 rounded-md border p-3 text-sm"
            style={{ background: 'var(--surface)', color: 'var(--destructive)' }}
        >
            {message}
        </p>
    );
}
