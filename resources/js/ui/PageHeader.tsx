import type { ReactNode } from 'react';

export interface PageHeaderProps {
    /**
     * The section this page belongs to, in mono caps above the title — "LOGS",
     * "DEVELOPERS". It is how somebody with six console tabs open tells two pages named
     * "Members" apart, so it is not decoration.
     */
    eyebrow?: ReactNode;
    title: ReactNode;
    /** Rendered beside the title — a status pill, an environment badge. */
    badge?: ReactNode;
    description?: ReactNode;
    /** The page's primary actions, right-aligned and wrapping under the title on mobile. */
    actions?: ReactNode;
}

/**
 * The page's one h1. Every page has exactly one, and it is here — so no page can ship
 * with two headings competing to be the title, or with none at all.
 */
export function PageHeader({ eyebrow, title, badge, description, actions }: PageHeaderProps) {
    return (
        <header className="cbx-page-header">
            <div style={{ minWidth: 0 }}>
                {eyebrow !== undefined && <p className="cbx-page-eyebrow">{eyebrow}</p>}
                <div className="cbx-page-title-row">
                    <h1 className="cbx-page-title">{title}</h1>
                    {badge}
                </div>
                {description !== undefined && <p className="cbx-page-desc">{description}</p>}
            </div>
            {actions !== undefined && (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: '8px' }}>{actions}</div>
            )}
        </header>
    );
}
