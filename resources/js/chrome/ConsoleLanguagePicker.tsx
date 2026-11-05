import { update } from '@routes/console/locale';
import { update as updateEnvironment } from '@routes/environment/locale';
import type { SharedProps } from '@/types';
import { usePage, router } from '@inertiajs/react';
import { DropdownMenu } from 'radix-ui';
import { useTranslator } from '@/i18n';
import { Button, DropdownMenuContent, Icon } from '@/ui';

/** Shared account preference, independent of the customer's hosted-language policy. */
export function ConsoleLanguagePicker() {
    const { locale, t } = useTranslator();
    const { i18n, auth, shell } = usePage<SharedProps>().props;
    const languages = i18n?.locales ?? [];

    if (languages.length < 2) return null;
    const current = languages.find((language) => language.code === locale);

    return (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className="border-transparent bg-transparent shadow-none"
                    data-testid="console-language-picker"
                    aria-label={`${t('console.language')}: ${current?.name ?? locale}`}
                >
                    <span lang={locale}>{current?.name ?? locale}</span>
                    <Icon name="chevron" className="h-3.5 w-3.5" />
                </Button>
            </DropdownMenu.Trigger>
            <DropdownMenuContent align="end">
                <DropdownMenu.Label className="cbx-menulabel">
                    {t('console.language')}
                </DropdownMenu.Label>
                <DropdownMenu.RadioGroup
                    value={locale}
                    aria-label={t('console.language')}
                    onValueChange={(value) =>
                        router.post(
                            shell?.altitude === 'environment' ? updateEnvironment.url() : update.url(),
                            { locale: value, user_id: auth.user?.id },
                            { preserveScroll: true, preserveState: false },
                        )
                    }
                >
                    {languages.map((language) => (
                        <DropdownMenu.RadioItem
                            key={language.code}
                            value={language.code}
                            className="cbx-menuitem"
                        >
                            <span lang={language.code} className="flex-1">
                                {language.name}
                            </span>
                            <DropdownMenu.ItemIndicator>
                                <Icon name="check" className="h-4 w-4" />
                            </DropdownMenu.ItemIndicator>
                        </DropdownMenu.RadioItem>
                    ))}
                </DropdownMenu.RadioGroup>
            </DropdownMenuContent>
        </DropdownMenu.Root>
    );
}
