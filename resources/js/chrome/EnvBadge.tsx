import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

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
export function EnvBadge() {
    const { environment } = usePage<SharedProps>().props;

    if (environment.type === null) {
        return null;
    }

    return (
        <span
            className="cbx-env-badge"
            data-env-type={environment.type}
            title={`${environment.type.charAt(0).toUpperCase()}${environment.type.slice(1)} environment`}
        >
            {environment.type.toUpperCase()}
        </span>
    );
}
