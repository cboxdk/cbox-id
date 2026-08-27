import { cn } from '@/lib/cn';

export interface AvatarProps {
    /** The full name or email. The initial is derived; the whole string is the label. */
    name: string;
    className?: string;
}

/**
 * An initial in a circle.
 *
 * No image, deliberately. An identity console showing a remote avatar would leak every
 * administrator's session to a third-party image host on every page — and `img-src`
 * allows `https:` only so a CUSTOMER's own logo can render on their branded sign-in.
 */
export function Avatar({ name, className }: AvatarProps) {
    const initial = name.trim().charAt(0).toUpperCase() || 'C';

    return (
        <span className={cn('cbx-avatar', className)} aria-hidden="true" title={name}>
            {initial}
        </span>
    );
}
