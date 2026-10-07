import type { NavArea } from '@/types';

/**
 * Something on a page waiting for the person reading the navigation — the agent actions
 * held for THEIR approval — as a number beside the page's label.
 *
 * The number is spelled out for a screen reader too: "Approvals, 2 waiting for you" is a
 * sentence, a lone "2" after a link name is a riddle.
 */
export function NavCount({ count }: { count: number | null | undefined }) {
    if (count === null || count === undefined || count <= 0) {
        return null;
    }

    return (
        <span className="cbx-nav-count">
            <span aria-hidden="true">{count > 99 ? '99+' : count}</span>
            <span className="sr-only">, {count} waiting for you</span>
        </span>
    );
}

/** The total an area's pages are waiting on — the dot on the rail while it is collapsed. */
export function areaCount(area: NavArea): number {
    return area.pages.reduce((total, page) => total + (page.count ?? 0), 0);
}
