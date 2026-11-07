import { router, usePage } from '@inertiajs/react';
import { DropdownMenu as Primitive } from 'radix-ui';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import type { SharedProps } from '@/types';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger, Icon } from '@/ui';

/** Only an explicit selection writes the signed-in person's shared language preference. */
export function ConsoleLanguagePicker() {
    const { i18n } = usePage<SharedProps>().props;
    const { t } = useTranslator();
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const preference = i18n?.preference;

    if (!i18n || !preference || i18n.locales.length < 2) return null;

    function choose(language: string) {
        if (saving || language === i18n!.locale) return;
        setError(null);
        setSaving(true);
        router.post(
            preference!.updateUrl,
            { language, subject_id: preference!.subjectId, version: preference!.version },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => setError(Object.values(errors).join(' ')),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="relative shrink-0">
            <DropdownMenu>
                <DropdownMenuTrigger
                    disabled={saving}
                    aria-label={t('hosted.language.label')}
                    aria-busy={saving}
                    className="inline-flex min-h-9 items-center gap-1.5 rounded-md px-2 text-sm hover:bg-[var(--surface-hover)] disabled:opacity-50"
                >
                    <span lang={i18n.locale}>
                        {i18n.locales.find((option) => option.code === i18n.locale)?.name ??
                            i18n.locale}
                    </span>
                    <Icon name="chevron" className="h-3.5 w-3.5" />
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
                                        <Icon name="check" className="h-4 w-4" />
                                    </Primitive.ItemIndicator>
                                </span>
                                <span lang={option.code}>{option.name}</span>
                            </Primitive.RadioItem>
                        ))}
                    </Primitive.RadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>
            {error && (
                <p
                    role="alert"
                    className="absolute right-0 top-full z-50 mt-1 w-64 rounded-md border p-3 text-sm"
                    style={{ background: 'var(--surface)', color: 'var(--destructive)' }}
                >
                    {error}
                </p>
            )}
        </div>
    );
}
