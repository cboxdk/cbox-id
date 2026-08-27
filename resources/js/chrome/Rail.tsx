import { Link } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import type { NavArea } from '@/types';
import { Icon } from '@/ui';
import { cn } from '@/lib/cn';

export interface RailProps {
    areas: NavArea[];
    brandHref: string;
    brandLabel: string;
    pinned: boolean;
    onTogglePin: () => void;
    /** The account menu, which lives at the BOTTOM OF THE RAIL on every plane. */
    foot: ReactNode;
}

/**
 * TIER 1 — one icon per area, in a 52px floating pill.
 *
 * Three states, as the app-shell guideline specifies: minimised (icons only, in flow),
 * open-on-hover (expands in place to 210px as an overlay, so nothing on the page moves),
 * and pinned (expanded and in flow). The 64px of flow space is reserved by a spacer, which
 * is what lets the hover state be an overlay rather than a reflow.
 *
 * `onFocus`/`onBlur` alongside the pointer handlers, and not as an afterthought: without
 * them the rail expanded for a mouse and never for a keyboard, so tabbing through the
 * primary navigation moved focus between unlabelled icons whose only text was a `title`
 * attribute — which never appears for a keyboard or a touch user at all.
 */
export function Rail({ areas, brandHref, brandLabel, pinned, onTogglePin, foot }: RailProps) {
    const [hover, setHover] = useState(false);

    return (
        <>
            {/*
                The pointer handlers are on the <aside> and the rule that objects to that
                is right in general and wrong here: hovering is not how the rail is USED.
                Every link inside works collapsed, the focus handlers expand it for a
                keyboard, and the pin makes the expansion permanent. Widening the labels
                on hover is sugar for a pointer, not an interaction only a pointer can
                reach — which is what the rule exists to catch.
            */}
            {/* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */}
            <aside
                className={cn('cbx-rail', 'hidden lg:flex', (pinned || hover) && 'open')}
                onMouseEnter={() => setHover(true)}
                onMouseLeave={() => setHover(false)}
                onFocus={() => setHover(true)}
                onBlur={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget)) {
                        setHover(false);
                    }
                }}
                aria-label="Areas"
            >
                <div className="cbx-rail-hd">
                    <Link href={brandHref} className="cbx-rail-brand" aria-label={brandLabel}>
                        <svg viewBox="0 0 64 64" aria-hidden="true">
                            <rect x="2" y="2" width="60" height="60" rx="14" fill="var(--primary)" />
                            <text
                                x="32"
                                y="44"
                                textAnchor="middle"
                                fill="var(--primary-foreground)"
                                fontFamily="var(--font-display)"
                                fontWeight="700"
                                fontSize="30"
                                letterSpacing="-0.04em"
                            >
                                ID
                            </text>
                        </svg>
                    </Link>

                    <button
                        type="button"
                        className={cn('cbx-pin-btn', 'cbx-pintoggle', pinned && 'is-pinned')}
                        onClick={onTogglePin}
                        title={pinned ? 'Unpin navigation' : 'Pin navigation open'}
                        aria-pressed={pinned}
                        aria-label="Pin navigation"
                    >
                        <Icon name="pin" className="w-[17px] h-[17px]" />
                    </button>
                </div>

                <nav className="flex-1 overflow-y-auto" style={{ scrollbarWidth: 'none' }}>
                    {areas.map((area) => (
                        <Link
                            key={area.key}
                            href={area.href}
                            title={area.label}
                            className={cn(area.active && 'cbx-on')}
                            aria-current={area.current ? 'page' : undefined}
                            // The rail is the most-used control in the console and every
                            // click on it is a page somebody already knows they want.
                            // Prefetching on hover turns the wait into nothing on the
                            // overwhelming majority of them.
                            prefetch="hover"
                        >
                            <Icon name={area.icon} className="w-[18px] h-[18px]" />
                            <span className="lbl">{area.label}</span>
                        </Link>
                    ))}
                </nav>

                <div className="cbx-rail-foot">{foot}</div>
            </aside>

            {/*
                Reserves the collapsed rail's 52px of flow space, so opening it as an
                overlay never pushes the page sideways under the cursor.
            */}
            <div className="cbx-rail-spacer" aria-hidden="true" />
        </>
    );
}
