import { ServiceMark } from './AppSwitcher';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { NavArea } from '@/types';
import { Icon } from '@/ui';
import { cn } from '@/lib/cn';
import { areaCount } from './NavCount';

export interface RailProps {
    areas: NavArea[];
    brandHref: string;
    brandLabel: string;
    pinned: boolean;
    onTogglePin: () => void;
}

/**
 * TIER 1 — one entry per area, in a floating card.
 *
 * Three states, as the app-shell guideline specifies: minimised (icons only, in flow),
 * open-on-hover (expands in place to 210px as an overlay, so nothing on the page moves),
 * and pinned (expanded and in flow). The 64px of flow space is reserved by a spacer, which
 * is what lets the hover state be an overlay rather than a reflow.
 *
 * PINNED — LABELS SHOWING — IS THE DEFAULT ({@see \App\Platform\Console\NavPin}). The icon
 * strip asked somebody new to learn a dozen glyphs before they could find anything.
 *
 * Nothing at the foot any more: who you are is the avatar menu, top right, beside the
 * rest of the context the topbar carries.
 *
 * `onFocus`/`onBlur` alongside the pointer handlers, and not as an afterthought: without
 * them the rail expanded for a mouse and never for a keyboard, so tabbing through the
 * primary navigation moved focus between unlabelled icons whose only text was a `title`
 * attribute — which never appears for a keyboard or a touch user at all.
 */
export function Rail({ areas, brandHref, brandLabel, pinned, onTogglePin }: RailProps) {
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                aria-label={consoleT('console.areas_2d36715a')}
            >
                <div className="cbx-rail-hd">
                    <Link
                        href={brandHref}
                        className="cbx-rail-brand"
                        aria-label={`Cbox ID · ${brandLabel}`}
                    >
                        <span className="cbx-rail-wordmark" aria-hidden="true" />
                        <ServiceMark service="id" />
                        <span className="cbx-service-name">ID</span>
                    </Link>

                    <button
                        type="button"
                        className={cn('cbx-pin-btn', 'cbx-pintoggle', pinned && 'is-pinned')}
                        onClick={onTogglePin}
                        title={
                            pinned
                                ? consoleText('console.unpin_navigation_5e6e075c')
                                : consoleText('console.pin_navigation_open_8807310f')
                        }
                        aria-pressed={pinned}
                        aria-label={consoleT('console.pin_navigation_e8acb50c')}
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
                            <span className="cbx-rail-ico">
                                <Icon name={area.icon} className="w-[18px] h-[18px]" />
                                {areaCount(area) > 0 && (
                                    <span className="cbx-rail-dot" aria-hidden="true" />
                                )}
                            </span>
                            <span className="lbl">{area.label}</span>
                            {areaCount(area) > 0 && (
                                <span className="sr-only">
                                    {consoleRich('console._part1_waiting_for_you_d2821019', {
                                        part1: areaCount(area),
                                    })}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>
            </aside>

            {/*
                Reserves the collapsed rail's 52px of flow space, so opening it as an
                overlay never pushes the page sideways under the cursor.
            */}
            <div className="cbx-rail-spacer" aria-hidden="true" />
        </>
    );
}
