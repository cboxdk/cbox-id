import { usePage } from '@inertiajs/react';
import { ToggleGroup } from 'radix-ui';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import { setThemePreference } from '@/lib/theme';
import type { SharedProps } from '@/types';
import { Icon, type IconProps } from '@/ui';

type Choice = 'system' | 'light' | 'dark';

const CHOICES: { value: Choice; icon: IconProps['name'] }[] = [
    { value: 'system', icon: 'monitor' },
    { value: 'light', icon: 'sun' },
    { value: 'dark', icon: 'moon' },
];

/**
 * THE THEME, AS THE ACCOUNT MENU OFFERS IT: System, Light, Dark — not a toggle.
 *
 * A toggle could only flip between the two explicit themes, so once somebody pressed it
 * their device's own setting was forgotten for good on this browser. "System" forgets the
 * explicit choice again ({@see setThemePreference}), and the server paints the next page
 * from the same cookie before any script runs.
 *
 * Icons at footer size, each named for a screen reader and given a tooltip for the eye;
 * Radix's single toggle group supplies the arrow-key movement and the checked state.
 */
export function ThemeChoice() {
    const { theme } = usePage<SharedProps>().props;
    const { t } = useTranslator();
    const [choice, setChoice] = useState<Choice>(theme ?? 'system');

    return (
        <ToggleGroup.Root
            type="single"
            value={choice}
            onValueChange={(value: string) => {
                // Radix reports '' when the pressed item is pressed again; a theme is
                // always one of the three, so that is not a choice.
                if (value === 'system' || value === 'light' || value === 'dark') {
                    setChoice(value);
                    setThemePreference(value);
                }
            }}
            aria-label={t('hosted.layout.theme')}
            className="auth-theme"
        >
            {CHOICES.map(({ value, icon }) => (
                <ToggleGroup.Item
                    key={value}
                    value={value}
                    aria-label={t(`hosted.theme.${value}`)}
                    title={t(`hosted.theme.${value}`)}
                >
                    <Icon name={icon} className="w-3.5 h-3.5" />
                </ToggleGroup.Item>
            ))}
        </ToggleGroup.Root>
    );
}
