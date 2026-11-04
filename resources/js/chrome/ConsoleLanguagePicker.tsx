import { update } from '@routes/console/locale';
import { router } from '@inertiajs/react';
import { DropdownMenu } from 'radix-ui';
import { useTranslator } from '@/i18n';
import { Icon } from '@/ui';

/** A browser preference, independent of the customer's hosted-language policy. */
export function ConsoleLanguagePicker() {
    const { locale, t } = useTranslator();

    return (
        <>
            <DropdownMenu.Label className="cbx-menulabel">
                {t('console.language')}
            </DropdownMenu.Label>
            <DropdownMenu.RadioGroup
                value={locale}
                aria-label={t('console.language')}
                onValueChange={(value) =>
                    router.post(
                        update.url(),
                        { locale: value },
                        { preserveScroll: true, preserveState: false },
                    )
                }
            >
                {[
                    { code: 'da', name: 'Dansk' },
                    { code: 'en', name: 'English' },
                ].map((language) => (
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
        </>
    );
}
