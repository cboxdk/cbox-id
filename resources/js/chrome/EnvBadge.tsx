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
    const { environment } = usePage<SharedProps>().props;
    const shown = type === undefined ? environment.type : type;

    if (shown === null || shown === '') {
        return null;
    }

    return (
        <span
            className="cbx-env-badge"
            data-env-type={shown}
            title={`${shown.charAt(0).toUpperCase()}${shown.slice(1)} environment`}
        >
            {shown.toUpperCase()}
        </span>
    );
}
