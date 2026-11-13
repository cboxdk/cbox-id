import { initialsOf } from '@cboxdk/cbox-ui';
import { cn } from '@/lib/cn';

export interface AvatarProps {
    /** The full name or email. The initials are derived; the whole string is the label. */
    name: string;
    className?: string;
}

/**
 * Initials in a circle — two letters, worked out the way cbox-ui's shell works out the
 * account menu's ("Ada Lovelace" is "AL"), so a person is drawn the same everywhere.
 *
 * No image, deliberately. An identity console showing a remote avatar would leak every
 * administrator's session to a third-party image host on every page — and `img-src`
 * allows `https:` only so a CUSTOMER's own logo can render on their branded sign-in.
 */
export function Avatar({ name, className }: AvatarProps) {
    const initials = name.trim() === '' ? 'C' : initialsOf(name);

    return (
        <span className={cn('idc-avatar', className)} aria-hidden="true" title={name}>
            {initials}
        </span>
    );
}
