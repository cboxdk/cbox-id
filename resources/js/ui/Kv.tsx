import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export interface KvProps {
    label: ReactNode;
    /**
     * Render the value as prose rather than mono. The default is mono because most of
     * what this console shows in a key/value list is an identifier, a timestamp or an
     * amount — things a reader compares character by character.
     */
    prose?: boolean;
    className?: string;
    children: ReactNode;
}

/** One row of a description list. Always inside `<KvList>`. */
export function Kv({ label, prose = false, className, children }: KvProps) {
    return (
        <div className={cn('cbx-kv', className)}>
            <dt>{label}</dt>
            <dd className={cn(prose && 'prose')}>{children}</dd>
        </div>
    );
}

/**
 * A description list — a real `<dl>`, so the label/value pairing is in the accessibility
 * tree and not merely in the grid columns.
 */
export function KvList({ children, className }: { children: ReactNode; className?: string }) {
    return <dl className={className}>{children}</dl>;
}
