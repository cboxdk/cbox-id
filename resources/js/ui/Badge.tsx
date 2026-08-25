import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type BadgeTone = 'neutral' | 'success' | 'warn' | 'danger' | 'info';

const TONE: Record<BadgeTone, string> = {
    neutral: '',
    success: 'badge-success',
    warn: 'badge-warn',
    danger: 'badge-danger',
    info: 'badge-info',
};

/**
 * A small inline label — a plan name, a protocol, a count.
 *
 * Distinct from `<Pill>`, which says what STATE something is in and carries a dot for
 * scanning down a column. If it answers "is this working?", it is a Pill.
 */
export function Badge({
    tone = 'neutral',
    className,
    children,
}: {
    tone?: BadgeTone;
    className?: string;
    children: ReactNode;
}) {
    return <span className={cn('badge', TONE[tone], className)}>{children}</span>;
}
