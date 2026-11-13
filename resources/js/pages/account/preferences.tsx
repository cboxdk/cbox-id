import { applyTheme } from '@cboxdk/cbox-ui';
import { router, usePage } from '@inertiajs/react';
import { type ReactNode, useMemo, useState } from 'react';
import { toast } from '@/chrome/Toaster';
import { useTranslator } from '@/i18n';
import AccountLayout from '@/layouts/AccountLayout';
import { savePreference } from '@/layouts/ConsoleLayout';
import { FORMAT_CODES, formatLabel } from '@/lib/formats';
import type { HelpContent, PageProps, SharedProps } from '@/types';
import { Combobox, Icon, PageHeader, Panel } from '@/ui';
import type { IconName } from '@/ui/icons';

type Theme = 'system' | 'light' | 'dark';

type Props = PageProps<{
    help: HelpContent;
    current: {
        theme: Theme;
        regionalFormat: string | null;
        timeZone: string | null;
    };
}>;

/** "Automatic" in a Combobox, which takes strings only. */
const AUTOMATIC = 'automatic';

/**
 * PREFERENCES: the account menu's four choices on a page of their own
 * ({@see \App\Http\Controllers\AccountPreferencesController}). Each saves the moment it is
 * chosen, through the same saver the menu and ⌘K use, and says so.
 */
export default function Preferences({ current, help }: Props) {
    const { t, locale } = useTranslator();
    const { i18n } = usePage<SharedProps>().props;
    const [theme, setTheme] = useState<Theme>(current.theme);

    const saved = () => toast.success(t('console.account_preferences_saved'));
    const save = (key: string, value: string | undefined) => {
        savePreference(key, value, i18n);

        // The language reloads the page in it; the others re-read the props, so dates on
        // every page (this one too) follow at once.
        if (key !== 'locale') {
            router.reload({ only: ['shell', 'current'], onSuccess: saved });
        }
    };

    const languages = (i18n?.locales ?? []).map((option) => ({
        value: option.code,
        label: option.name,
    }));
    const canChooseLanguage =
        i18n?.preference !== null && i18n?.preference !== undefined && languages.length > 1;

    const deviceZone = new Intl.DateTimeFormat().resolvedOptions().timeZone;
    const zones = useMemo(() => timeZoneOptions(locale), [locale]);
    const example = useMemo(
        () =>
            new Intl.DateTimeFormat(current.regionalFormat ?? locale, {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZone: current.timeZone ?? undefined,
            }).format(new Date()),
        [current.regionalFormat, current.timeZone, locale],
    );

    const themes: { value: Theme; label: string; icon: IconName }[] = [
        { value: 'system', label: t('console.system_6725e7bb'), icon: 'monitor' },
        { value: 'light', label: t('console.light_dbcd5e7b'), icon: 'sun' },
        { value: 'dark', label: t('console.dark_60acc53f'), icon: 'moon' },
    ];

    return (
        <>
            <PageHeader help={help} description={t('console.account_preferences_description')} />

            <Panel title={t('console.account_preferences_language_region')} className="mt-6" flush>
                <PreferenceRow
                    label={t('console.language')}
                    hint={t('console.account_preferences_language_hint')}
                >
                    <Combobox
                        aria-label={t('console.language')}
                        value={i18n?.locale}
                        disabled={!canChooseLanguage}
                        onValueChange={(value) => save('locale', value)}
                        options={languages}
                    />
                </PreferenceRow>
                <PreferenceRow
                    label={t('console.regional_format')}
                    hint={t('console.account_preferences_format_hint', { example })}
                >
                    <Combobox
                        aria-label={t('console.regional_format')}
                        value={current.regionalFormat ?? AUTOMATIC}
                        onValueChange={(value) =>
                            save('regionalFormat', value === AUTOMATIC ? undefined : value)
                        }
                        options={[
                            {
                                value: AUTOMATIC,
                                label: t('console.account_preferences_automatic_format'),
                            },
                            ...FORMAT_CODES.map((code) => ({
                                value: code,
                                label: formatLabel(code),
                            })),
                        ]}
                    />
                </PreferenceRow>
                <PreferenceRow
                    label={t('console.account_preferences_time_zone')}
                    hint={t('console.account_preferences_time_zone_hint')}
                >
                    <Combobox
                        aria-label={t('console.account_preferences_time_zone')}
                        value={current.timeZone ?? AUTOMATIC}
                        onValueChange={(value) =>
                            save('timeZone', value === AUTOMATIC ? undefined : value)
                        }
                        options={[
                            {
                                value: AUTOMATIC,
                                label: t('console.account_preferences_automatic_zone', {
                                    zone: zoneLabel(deviceZone, locale),
                                }),
                            },
                            ...zones,
                        ]}
                    />
                </PreferenceRow>
            </Panel>

            <Panel title={t('console.account_preferences_appearance')} className="mt-6" flush>
                <PreferenceRow
                    label={t('console.theme_efb52e71')}
                    hint={t('console.account_preferences_theme_hint')}
                >
                    <fieldset className="pref-themes">
                        <legend className="sr-only">{t('console.theme_efb52e71')}</legend>
                        {themes.map((option) => (
                            <label
                                key={option.value}
                                className="pref-theme"
                                data-test={`theme-${option.value}`}
                            >
                                <input
                                    type="radio"
                                    name="theme"
                                    value={option.value}
                                    className="sr-only"
                                    checked={theme === option.value}
                                    onChange={() => {
                                        setTheme(option.value);
                                        applyTheme(option.value);
                                        save('theme', option.value);
                                    }}
                                />
                                <Icon name={option.icon} className="w-4 h-4" />
                                {option.label}
                            </label>
                        ))}
                    </fieldset>
                </PreferenceRow>
            </Panel>
        </>
    );
}

function PreferenceRow({
    label,
    hint,
    children,
}: {
    label: string;
    hint: string;
    children: ReactNode;
}) {
    return (
        <div className="pref-row">
            <div>
                <p className="pref-row-label">{label}</p>
                <p className="pref-row-hint">{hint}</p>
            </div>
            <div>{children}</div>
        </div>
    );
}

/** "Copenhagen · Central European Time": the city and the zone's name in the reader's language, never the IANA id. */
export function zoneLabel(zone: string, locale: string): string {
    const city = zone.split('/').pop()?.replaceAll('_', ' ') ?? zone;

    try {
        const name = new Intl.DateTimeFormat(locale, {
            timeZone: zone,
            timeZoneName: 'longGeneric',
        })
            .formatToParts(new Date())
            .find((part) => part.type === 'timeZoneName')?.value;

        return name === undefined || name === '' ? city : `${city} · ${name}`;
    } catch {
        return city;
    }
}

function timeZoneOptions(locale: string): { value: string; label: string; keywords: string[] }[] {
    const zones =
        typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];

    return zones
        .map((zone) => ({ value: zone, label: zoneLabel(zone, locale), keywords: [zone] }))
        .sort((a, b) => a.label.localeCompare(b.label, locale));
}

Preferences.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
