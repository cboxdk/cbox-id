import type { HTMLAttributes, ReactNode, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';

/**
 * A data table, in its own horizontal scroller.
 *
 * `<caption>` is REQUIRED, not optional. A table announced as "table, 6 columns, 40 rows"
 * and nothing else tells somebody using a screen reader that data exists and not what
 * data — and this console has a dozen tables that would otherwise be indistinguishable.
 * Pass `captionVisible` when it should be read on screen too; the common case is that the
 * surrounding panel title already says it.
 *
 * A STRING, not a node, because the caption is also the scroller's accessible name.
 *
 * THE SCROLLER IS PART OF THE PRIMITIVE. Every page wrapped its own table in a
 * `<div class="overflow-x-auto">`, and every one of those divs was a scrollable region a
 * keyboard could not reach: at a narrow width the Actions column sits past the right edge,
 * and without `tabindex` there is no way to scroll to it without a pointer (WCAG 2.1.1).
 * Written once here, it cannot be forgotten by the next table — and a page that still has
 * a wrapper of its own is harmless, because this one clips first.
 */
export interface TableProps extends HTMLAttributes<HTMLTableElement> {
    caption: string;
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
        // A `<section>` rather than a div with role="region": same semantics, one fewer
        // attribute, and the linter is right that the element should carry its own role.
        // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex
        <section className="overflow-x-auto" tabIndex={0} aria-label={caption}>
            <table className={cn('table', className)} {...props}>
                <caption className={cn(!captionVisible && 'sr-only')}>{caption}</caption>
                {children}
            </table>
        </section>
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
