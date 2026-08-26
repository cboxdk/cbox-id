import { Link } from '@inertiajs/react';
import type { Pagination as PaginationState } from '@/types';
import { Icon } from './Icon';

export interface PaginationProps {
    pagination: PaginationState;
    /** Builds the href for a page. The caller owns the query string this page lives in. */
    href: (page: number) => string;
    /** What is being counted — "endpoint", "member". */
    noun?: string;
    /**
     * Its plural, when adding an s is wrong.
     *
     * "directorys" and "policys" are what the default produces, and a paginator that
     * cannot spell the thing it is counting reads as a bug in the product.
     */
    pluralNoun?: string;
}

/**
 * Moving through a list too long to show at once.
 *
 * Previous and next only, with a spoken position — not a row of numbered page links. The
 * numbers are what Laravel's paginator hands a blade template, and they are the wrong
 * control here: at 375px a run of eleven of them wraps to three lines, and on a list
 * ordered by recency nobody wants page seven, they want the next page or a filter.
 *
 * The position line is a live region, because paging is the one navigation on these pages
 * that does not move focus — without it a screen-reader user gets a silently replaced
 * list and no confirmation that anything happened at all.
 */
export function Pagination({ pagination, href, noun = 'result', pluralNoun }: PaginationProps) {
    const { currentPage, lastPage, from, to, total } = pagination;

    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav className="flex items-center justify-between gap-3 flex-wrap" aria-label="Pagination">
            <p style={{ fontSize: '13px', color: 'var(--muted-foreground)' }} aria-live="polite">
                {from}–{to} of {total} {total === 1 ? noun : (pluralNoun ?? `${noun}s`)}
            </p>

            <div className="flex items-center gap-2">
                {currentPage > 1 ? (
                    <Link href={href(currentPage - 1)} className="btn btn-ghost btn-sm" rel="prev">
                        <Icon
                            name="chevron"
                            className="w-4 h-4"
                            style={{ transform: 'rotate(90deg)' }}
                        />
                        Previous
                    </Link>
                ) : (
                    <span
                        className="btn btn-ghost btn-sm"
                        aria-disabled="true"
                        style={{ opacity: 0.5 }}
                    >
                        <Icon
                            name="chevron"
                            className="w-4 h-4"
                            style={{ transform: 'rotate(90deg)' }}
                        />
                        Previous
                    </span>
                )}

                {currentPage < lastPage ? (
                    <Link href={href(currentPage + 1)} className="btn btn-ghost btn-sm" rel="next">
                        Next
                        <Icon
                            name="chevron"
                            className="w-4 h-4"
                            style={{ transform: 'rotate(-90deg)' }}
                        />
                    </Link>
                ) : (
                    <span
                        className="btn btn-ghost btn-sm"
                        aria-disabled="true"
                        style={{ opacity: 0.5 }}
                    >
                        Next
                        <Icon
                            name="chevron"
                            className="w-4 h-4"
                            style={{ transform: 'rotate(-90deg)' }}
                        />
                    </span>
                )}
            </div>
        </nav>
    );
}
