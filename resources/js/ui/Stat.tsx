import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Icon } from './Icon';
import type { IconName } from './icons';

export type StatTone = 'info' | 'success' | 'warning' | 'neutral';

const TONE: Record<StatTone, string> = {
    info: '',
    success: 'cbx-stat-icon--success',
    warning: 'cbx-stat-icon--warning',
    neutral: 'cbx-stat-icon--neutral',
};

export interface StatProps {
    icon?: IconName;
    tone?: StatTone;
    label: ReactNode;
    value: ReactNode;
    /**
     * Makes the whole tile a link. A clickable row, never a "View" button beside it.
     *
     * An Inertia visit rather than a bare `<a>`: a console overview whose tiles reload the
     * whole document throws away the shell and the scroll position, and is the one place a
     * full page load is most visible — the tiles are the first thing anybody clicks.
     */
    href?: string;
    className?: string;
}

/**
 * One number, named.
 *
 * The value comes FIRST in the DOM and the label second, so a screen reader reads "1,284,
 * active users" rather than making the listener hold the label until the number arrives.
 * Visually the order is reversed by the stylesheet.
 */
export function Stat({ icon, tone = 'info', label, value, href, className }: StatProps) {
    const body = (
        <>
            {icon !== undefined && (
                <span className={cn('cbx-stat-icon', TONE[tone])} aria-hidden="true">
                    <Icon name={icon} />
                </span>
            )}
            <div>
                <p className="cbx-stat-value">{value}</p>
                <p className="cbx-stat-label">{label}</p>
            </div>
        </>
    );

    if (href !== undefined) {
        return (
            <Link href={href} className={cn('cbx-stat', className)}>
                {body}
            </Link>
        );
    }

    return <div className={cn('cbx-stat', className)}>{body}</div>;
}
