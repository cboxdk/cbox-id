import { useTranslator as useConsoleTranslator } from '@/i18n';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

export interface EnvBadgeProps {
    /**
     * The environment type to name. Omitted, it is the environment THIS request acts in;
     * the context switcher passes one to label every row of its menu the same way.
     */
    type?: string | null;
}

/**
 * WHICH REALM YOU ARE IN, said in a word.
 *
 * It must survive below the `lg` breakpoint. The breadcrumb that used to carry the
 * environment name was `hidden lg:flex`, so on a phone there was no indication at all —
 * and two tabs, one staging and one production, were indistinguishable at the moment of
 * hitting Delete.
 *
 * ANNOUNCED, not merely coloured. Colour alone is not an indicator (SC 1.4.1), so the
 * word is the badge and the tint only reinforces it.
 */
export function EnvBadge({ type }: EnvBadgeProps = {}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { environment } = usePage<SharedProps>().props;
    const shown = type === undefined ? environment.type : type;

    if (shown === null || shown === '') {
        return null;
    }

    const label =
        shown === 'production'
            ? consoleFinalText('console.production_13a7c655')
            : shown === 'sandbox'
              ? consoleFinalText('console.sandbox_67fc6249')
              : shown;
    const title =
        shown === 'production'
            ? consoleFinalText('console.production_environment_label')
            : shown === 'sandbox'
              ? consoleFinalText('console.sandbox_environment_label')
              : consoleFinalText('console.named_environment_label', { name: shown });

    return (
        <span className="cbx-env-badge" data-env-type={shown} title={title}>
            {label}
        </span>
    );
}
