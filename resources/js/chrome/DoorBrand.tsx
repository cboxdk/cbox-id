import type { Brand as BrandShape } from '@/types';
import { Brand } from './Brand';

/**
 * WHOSE PAGE THIS IS, at the top of a hosted surface — the sign-in doors and the Admin
 * Portal alike.
 *
 * With a brand (an organization's door, or any hosted page on a customer's environment):
 * their UPLOADED logo, served by this application, or — with none uploaded — their initial
 * on the accent beside their name, drawn exactly as the Branding page's preview draws
 * it, so the page is the one that was approved. Without one: Cbox's own mark.
 *
 * The logo is never a remote URL. It used to be one, typed on the old Appearance page, and an
 * `<img>` of it reported every visitor to whoever hosted it; the server now only ever hands
 * this a path of its own (see `BrandImages`), and the content security policy refuses any
 * other image origin anyway.
 */
export function DoorBrand({
    brand,
    home = '/',
}: {
    brand: BrandShape | null;
    home?: string | null;
}) {
    if (brand === null) {
        return home === null ? (
            <Brand />
        ) : (
            <a href={home} className="inline-block">
                <Brand />
            </a>
        );
    }

    if (brand.logo !== null) {
        return (
            <img
                src={brand.logo}
                alt={brand.name}
                style={{ maxHeight: '2.25rem', maxWidth: '12rem' }}
            />
        );
    }

    return (
        <span className="inline-flex items-center gap-2.5 select-none">
            <span
                aria-hidden="true"
                className="grid place-items-center w-8 h-8 rounded-lg text-sm font-bold"
                style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
            >
                {brand.name.charAt(0).toUpperCase()}
            </span>
            <span
                className="font-semibold tracking-tight"
                style={{ fontSize: '1.02rem', color: 'var(--foreground)' }}
            >
                {brand.name}
            </span>
        </span>
    );
}
