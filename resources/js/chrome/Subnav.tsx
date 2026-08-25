import { Link } from '@inertiajs/react';
import type { NavPage } from '@/types';
import { Icon, Tooltip } from '@/ui';
import { cn } from '@/lib/cn';

export interface SubnavProps {
    label: string;
    pages: NavPage[];
    collapsed: boolean;
    onToggle: () => void;
}

/**
 * TIER 2 — the 176px contextual column for the active area's pages.
 *
 * Rendered only when the area has more than one page; a single-page area is fully
 * addressed by its rail icon. The caller gates on that, so this component can never be
 * asked to draw an empty column.
 *
 * THE COLLAPSED STRIP IS A BUTTON. It persists in localStorage, and in the version this
 * replaces it was a `<div>` with a click handler — so a keyboard user who collapsed the
 * sub-nav lost tier-2 navigation permanently: no tabindex, no role, no key handler, and
 * the only way back was a shortcut documented in the title of the control that had just
 * disappeared.
 */
export function Subnav({ label, pages, collapsed, onToggle }: SubnavProps) {
    return (
        <aside className={cn('cbx-subnav', 'hidden lg:flex', collapsed && 'collapsed')}>
            <button
                type="button"
                className="cbx-strip"
                onClick={onToggle}
                aria-label={`Expand ${label} navigation`}
            >
                <span className="vlabel">{label}</span>
                <Icon name="chevron" className="w-3.5 h-3.5" style={{ transform: 'rotate(-90deg)' }} />
            </button>

            <div className="cbx-subnav-hd">
                <span>{label}</span>
                <button
                    type="button"
                    className="cbx-subnav-toggle"
                    onClick={onToggle}
                    title="Collapse (⌘.)"
                    aria-label="Collapse subnav"
                >
                    <Icon name="chevron" className="w-4 h-4" style={{ transform: 'rotate(90deg)' }} />
                </button>
            </div>

            <nav aria-label={label}>
                {pages.map((page) => (
                    <Link
                        key={page.route}
                        href={page.href}
                        className={cn(page.active && 'cbx-on')}
                        aria-current={page.active ? 'page' : undefined}
                        prefetch="hover"
                    >
                        <span>{page.label}</span>

                        {page.badge !== null && (
                            /*
                                A GLYPH, not the word. At 176px the spelled-out
                                "ENTERPRISE" ate 64px and truncated "Single sign-on" and
                                "Outbound sync"; the lock costs 14px and keeps every label
                                whole. The wording still reaches a screen reader and the
                                tooltip, and the mobile sheet — which has the room — keeps
                                the full text.
                            */
                            <Tooltip content={page.badge}>
                                <span
                                    className="cnt shrink-0"
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        color: 'var(--primary)',
                                    }}
                                >
                                    <Icon name="lock" className="w-3.5 h-3.5" />
                                    <span className="sr-only">{page.badge}</span>
                                </span>
                            </Tooltip>
                        )}
                    </Link>
                ))}
            </nav>
        </aside>
    );
}
