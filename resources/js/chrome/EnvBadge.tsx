import { useUiTranslator } from '@/i18n/ui';
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
    const { t: tc } = useUiTranslator();
    const { environment } = usePage<SharedProps>().props;
    const shown = type === undefined ? environment.type : type;

    if (shown === null || shown === '') {
        return null;
    }

    const labels: Record<string, string> = {
        production: tc('console.production_13a7c655', 'Production'),
        sandbox: tc('console.sandbox_67fc6249', 'Sandbox'),
        development: tc('console.development_21b6a798', 'Development'),
        staging: tc('console.staging_a8e7ac92', 'Staging'),
        test: tc('console.test_532eaabd', 'Test'),
    };
    const label = labels[shown] ?? shown.charAt(0).toUpperCase() + shown.slice(1);

    return (
        <span
            className="cbx-env-badge"
            data-env-type={shown}
            title={tc('console.name_environment_20a85a3f', ':name environment', { name: label })}
        >
            {label.toUpperCase()}
        </span>
    );
}
