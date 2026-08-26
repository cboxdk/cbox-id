import { Link } from '@inertiajs/react';
import type { SimplePagination as SimplePaginationState } from '@/types';
import { Icon } from './Icon';

export interface SimplePaginationProps {
    pagination: SimplePaginationState;
    /** Builds the href for a page. The caller owns the query string this page lives in. */
    href: (page: number) => string;
    /** What is being counted — "entry", "delivery". Pluralised by the caller's own word. */
    noun?: string;
    pluralNoun?: string;
}

/**
 * Previous and next over a list whose LENGTH IS NOT KNOWN.
 *
 * The sibling {@see Pagination} says "26–50 of 391". This one cannot, and must not
 * pretend to: the server answered "is there more?" with one extra row rather than paying
 * for a COUNT(*) over an append-only table on every keystroke.
 *
 * So the position it speaks is the honest one — which page, and how many rows are on it.
 * That second number is load-bearing rather than decorative: the list redraws on a
 * debounced keystroke with no focus change, so this line is the only thing that can report
 * that a filter narrowed to nothing (WCAG 2.1 SC 4.1.3).
 */
export function SimplePagination({
    pagination,
    href,
    noun = 'result',
    pluralNoun,
}: SimplePaginationProps) {
    const { currentPage, count, hasMore } = pagination;
    const plural = pluralNoun ?? `${noun}s`;

    return (
        <nav className="flex items-center justify-between gap-3 flex-wrap" aria-label="Pagination">
            <p style={{ fontSize: '13px', color: 'var(--muted-foreground)' }} aria-live="polite">
                Page {currentPage} · {count} {count === 1 ? noun : plural} on this page
            </p>

            {(currentPage > 1 || hasMore) && (
                <div className="flex items-center gap-2">
                    {currentPage > 1 ? (
                        <Link
                            href={href(currentPage - 1)}
                            className="btn btn-ghost btn-sm"
                            rel="prev"
                        >
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

                    {hasMore ? (
                        <Link
                            href={href(currentPage + 1)}
                            className="btn btn-ghost btn-sm"
                            rel="next"
                        >
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
            )}
        </nav>
    );
}
