import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type PillTone = 'neutral' | 'success' | 'warning' | 'info' | 'destructive';

const TONE: Record<PillTone, string> = {
    neutral: '',
    success: 'cbx-pill--success',
    warning: 'cbx-pill--warning',
    info: 'cbx-pill--info',
    destructive: 'cbx-pill--destructive',
};

export interface PillProps {
    tone?: PillTone;
    /**
     * Show the leading dot. On by default for a STATE ("active", "paused") because the
     * dot is what makes a row of statuses scannable down a column; off for a pill that is
     * a label rather than a state ("Enterprise").
     */
    dot?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * A status, said in words.
 *
 * The tone is never the only carrier of meaning: the label always states the state, so
 * the pill reads the same to somebody who cannot distinguish the colours. That is not a
 * nicety — a console where "green" means live and "amber" means degraded is unusable to
 * roughly one man in twelve.
 */
export function Pill({ tone = 'neutral', dot = true, className, children }: PillProps) {
    return (
        <span className={cn('cbx-pill', TONE[tone], className)}>
            {dot && <span className="dot" aria-hidden="true" />}
            {children}
        </span>
    );
}
