import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types';

/**
 * WHOSE DOOR THIS IS, in the sign-in surface's top bar — where the console's rail carries
 * its mark, and at the same size.
 *
 * Cbox's own door wears the rail's mark exactly: the Cbox wordmark, the ID monogram, "ID".
 * The monogram is an inline svg filled from `--primary` (not the catalog's `<img>`), so it
 * is drawn on a signed-out device that has never fetched the catalog, follows the theme,
 * and follows a customer's palette with nothing uploaded.
 *
 * A branded door — an organization's, or any door on a customer's environment, the
 * platform root's included when its Branding page has a logo — wears THEIR logo at the
 * height of a mark, or their initial and name. Never ours beside it.
 *
 * The logo is always a path this application serves ({@see \App\Platform\Appearance\BrandImages});
 * the content security policy refuses any other image origin.
 */
export function DoorMark() {
    const { app, brand } = usePage<SharedProps>().props;

    if (brand !== null) {
        return (
            <span className="auth-brand">
                {brand.logo !== null ? (
                    <img src={brand.logo} alt={brand.name} className="auth-brand-logo" />
                ) : (
                    <>
                        <span aria-hidden="true" className="auth-brand-initial">
                            {brand.name.charAt(0).toUpperCase()}
                        </span>
                        <span className="auth-brand-name">{brand.name}</span>
                    </>
                )}
            </span>
        );
    }

    return (
        <a href="/" className="auth-brand" aria-label={app.name}>
            <span className="auth-wordmark" aria-hidden="true" />
            <IdMonogram />
            <span className="auth-brand-name" aria-hidden="true">
                ID
            </span>
        </a>
    );
}

/** The ID app icon, drawn from the theme's tokens. */
export function IdMonogram({ className = 'auth-monogram' }: { className?: string }) {
    return (
        <svg viewBox="0 0 64 64" className={className} aria-hidden="true" focusable="false">
            <rect x="0" y="0" width="64" height="64" rx="15" fill="var(--primary)" />
            <text
                x="32"
                y="43.5"
                textAnchor="middle"
                fill="var(--primary-foreground)"
                fontFamily="var(--font-display)"
                fontWeight="700"
                fontSize="29"
                letterSpacing="-0.04em"
            >
                ID
            </text>
        </svg>
    );
}
