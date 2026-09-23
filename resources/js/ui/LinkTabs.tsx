import { Link } from '@inertiajs/react';

/** `App\Http\Props\Shared\LinkTabProps` */
export interface LinkTab {
    key: string;
    label: string;
    href: string;
    current: boolean;
}

export interface LinkTabsProps {
    tabs: LinkTab[];
    /** Names the set for a screen reader — "Key types". */
    label: string;
}

/**
 * TABS THAT ARE PAGES. Each tab is its own URL, so it can be linked, bookmarked and
 * reloaded, and the back button moves between them — the URL is the state, not a
 * component's memory of which tab was clicked.
 *
 * Drawn with the same underline as the in-page {@see Tabs}, and a `nav` of links rather
 * than a tablist: activating one loads a page, which is what a link announces.
 *
 * One tab is not a set of tabs; with fewer than two there is nothing to choose between,
 * so nothing is drawn.
 */
export function LinkTabs({ tabs, label }: LinkTabsProps) {
    if (tabs.length < 2) {
        return null;
    }

    return (
        <nav className="cbx-tabs" aria-label={label}>
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    className="cbx-tab"
                    data-state={tab.current ? 'active' : 'inactive'}
                    aria-current={tab.current ? 'page' : undefined}
                    preserveScroll
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
