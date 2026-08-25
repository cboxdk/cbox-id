import type { HTMLAttributes, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * A data table.
 *
 * `<caption>` is REQUIRED, not optional. A table announced as "table, 6 columns, 40 rows"
 * and nothing else tells somebody using a screen reader that data exists and not what
 * data — and this console has a dozen tables that would otherwise be indistinguishable.
 * Pass `visuallyHidden` when the surrounding panel title already says it on screen, which
 * is the common case.
 */
export interface TableProps extends HTMLAttributes<HTMLTableElement> {
    caption: ReactNode;
    captionVisible?: boolean;
    children: ReactNode;
}

export function Table({
    caption,
    captionVisible = false,
    className,
    children,
    ...props
}: TableProps) {
    return (
        <table className={cn('table', className)} {...props}>
            <caption className={cn(!captionVisible && 'sr-only')}>{caption}</caption>
            {children}
        </table>
    );
}

export function Th({ scope = 'col', className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return <th scope={scope} className={className} {...props} />;
}

export function Td({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={className} {...props} />;
}

/**
 * A cell holding an identifier, a timestamp or an amount — mono and tabular, so digits
 * line up down the column and two ids can be compared character by character.
 */
export function TdMono({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return (
        <td
            className={cn('mono', className)}
            style={{ fontVariantNumeric: 'tabular-nums' }}
            {...props}
        />
    );
}
