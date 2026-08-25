import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

// `title` is omitted from the DOM attributes on purpose: the HTML attribute is a
// tooltip string, and this one is a heading that may be rich content. Leaving both in
// scope would let a caller pass a ReactNode that silently became a tooltip.
export interface PanelProps extends Omit<HTMLAttributes<HTMLElement>, 'title'> {
    /** The heading. Omitted, the panel renders as a bare bordered surface. */
    title?: ReactNode;
    /** One sentence under the heading, for what the panel is FOR. */
    description?: ReactNode;
    /** The panel's own action — usually one button, on the right of the header. */
    action?: ReactNode;
    /**
     * Skip the padded body wrapper. For a panel whose content is a full-bleed table or a
     * list of `<Row>`s, where inner padding would inset the rules from the border.
     */
    flush?: boolean;
    children?: ReactNode;
}

/**
 * The console's unit of grouping: a bordered surface with an optional titled header.
 *
 * A `<section>` rather than a `<div>`, and the title is a real heading, so the page has a
 * structure somebody can navigate by landmark and by heading level instead of a flat wall
 * of boxes. The heading level is fixed at h2 because a panel always sits under the page
 * title — a panel inside a panel is a layout the design system does not have.
 */
export function Panel({
    title,
    description,
    action,
    flush = false,
    className,
    children,
    ...props
}: PanelProps) {
    const hasHeader = title !== undefined || action !== undefined;

    return (
        <section className={cn('cbx-panel', className)} {...props}>
            {hasHeader && (
                <header className="cbx-panel-header">
                    <div style={{ minWidth: 0 }}>
                        {title !== undefined && <h2 className="cbx-panel-title">{title}</h2>}
                        {description !== undefined && (
                            <p className="cbx-panel-desc">{description}</p>
                        )}
                    </div>
                    {action}
                </header>
            )}

            {flush ? children : <div className="cbx-panel-body">{children}</div>}
        </section>
    );
}
