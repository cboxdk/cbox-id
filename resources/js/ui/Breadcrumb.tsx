import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedProps } from '@/types';

export interface BreadcrumbProps {
    /** The list this page belongs to — "Enterprise SSO", "Organizations". */
    href: string;
    label: ReactNode;
}

/**
 * WHERE THIS PAGE SITS: the rail area it is filed under, then the list it belongs to —
 * "Authentication / Enterprise SSO" above a connection, "Users & orgs / Organizations"
 * above Acme Corp.
 *
 * It replaces the bare "‹ Enterprise SSO" back link every detail page drew for itself. That
 * link answered "how do I get back" and nothing else: on a page opened from ⌘K, a bookmark
 * or a link in the audit log, it did not say which part of the console you were in, and
 * the rail's lit area was the only clue. The area comes from the shell — the same nav
 * registry the rail and every eyebrow read — so the crumb cannot disagree with the rail.
 *
 * The parent is still the page's own: a detail page knows which list it came from (an
 * organization's tab, a filtered list) better than any rule the shell could apply. The
 * area is dropped where it would repeat the parent ("Settings / Settings").
 *
 * A `nav` landmark with an ordered list, the WAI-ARIA breadcrumb pattern; the page's own
 * heading, right below it, is the current location, so no item here is `aria-current`.
 */
export function Breadcrumb({ href, label }: BreadcrumbProps) {
    const { shell } = usePage<SharedProps>().props;
    const area = shell?.areas.find((candidate) => candidate.key === shell.activeArea);
    const showArea = area !== undefined && area.label !== label && area.href !== href;

    return (
        <nav className="cbx-crumbs" aria-label="Breadcrumb">
            <ol>
                {showArea && (
                    <li>
                        <Link href={area.href}>{area.label}</Link>
                    </li>
                )}
                <li>
                    <Link href={href}>{label}</Link>
                </li>
            </ol>
        </nav>
    );
}
