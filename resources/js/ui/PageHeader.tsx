import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { HelpContent, SharedProps } from '@/types';
import { Help } from './Help';

export interface PageHeaderProps {
    /**
     * The section this page belongs to, in mono caps above the title — "LOGS",
     * "DEVELOPERS".
     *
     * OMIT IT. The area comes from the nav registry, so the eyebrow always agrees with
     * the sidebar entry that got you here — pages that spelled their own drifted, and
     * shipped an eyebrow reading "Security" under a sidebar that said "Developers".
     * Pass a string only for a page outside the registry: a wizard, a standalone flow.
     *
     * `null` suppresses it entirely.
     */
    eyebrow?: ReactNode | null;
    /**
     * The heading.
     *
     * OMIT IT. The controller states what the page is called, the root view renders that
     * into `<title>` and this renders the same string as the h1 — so the tab and the
     * heading cannot disagree, which is the drift ConsoleAreasTest exists to catch. Pass
     * one only where the heading legitimately differs from the tab title.
     */
    title?: ReactNode;
    /** Rendered beside the title — a status pill, an environment badge. */
    badge?: ReactNode;
    /**
     * The concept this page is about, explained. A SIBLING of the h1, never a child:
     * nested, the heading's accessible name became "Members What is Members? Members are
     * the people who…" — the primary landmark a screen-reader user navigates by, carrying
     * the whole popover on every page that passed one.
     */
    help?: HelpContent;
    description?: ReactNode;
    /** The page's primary actions, right-aligned and wrapping under the title on mobile. */
    actions?: ReactNode;
}

/**
 * The page's one h1. Every page has exactly one, and it is here — so no page can ship
 * with two headings competing to be the title, or with none at all.
 */
export function PageHeader({
    eyebrow,
    title,
    badge,
    help,
    description,
    actions,
}: PageHeaderProps) {
    const { shell, title: stated } = usePage<SharedProps>().props;

    const area = shell?.areas.find((candidate) => candidate.key === shell.activeArea)?.label;
    const resolved = eyebrow === undefined ? area : eyebrow;

    return (
        <header className="cbx-page-header">
            <div style={{ minWidth: 0 }}>
                {resolved != null && <p className="cbx-page-eyebrow">{resolved}</p>}
                <div className="cbx-page-title-row">
                    <h1 className="cbx-page-title">{title ?? stated}</h1>
                    {help !== undefined && <Help help={help} />}
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
