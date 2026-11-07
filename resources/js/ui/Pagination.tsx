import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import type { Pagination as PaginationState } from '@/types';
import { Icon } from './Icon';

export interface PaginationProps {
    pagination: PaginationState;
    /** Builds the href for a page. The caller owns the query string this page lives in. */
    href: (page: number) => string;
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
export function Pagination({ pagination, href }: PaginationProps) {
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const { currentPage, lastPage, from, to, total } = pagination;

    if (lastPage <= 1) {
        return null;
    }

    return (
        <nav
            className="flex items-center justify-between gap-3 flex-wrap"
            aria-label={consoleT('console.pagination_7b3f674b')}
        >
            <p style={{ fontSize: '13px', color: 'var(--muted-foreground)' }} aria-live="polite">
                {consoleT('console.pagination_range', { from: from ?? 0, to: to ?? 0, total })}
            </p>

            <div className="flex items-center gap-2">
                {currentPage > 1 ? (
                    <Link href={href(currentPage - 1)} className="btn btn-ghost btn-sm" rel="prev">
                        {consoleRich('console._part1_previous_be47f3cf', {
                            part1: (
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4"
                                    style={{ transform: 'rotate(90deg)' }}
                                />
                            ),
                        })}
                    </Link>
                ) : (
                    <span
                        className="btn btn-ghost btn-sm"
                        aria-disabled="true"
                        style={{ opacity: 0.5 }}
                    >
                        {consoleRich('console._part1_previous_be47f3cf', {
                            part1: (
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4"
                                    style={{ transform: 'rotate(90deg)' }}
                                />
                            ),
                        })}
                    </span>
                )}

                {currentPage < lastPage ? (
                    <Link href={href(currentPage + 1)} className="btn btn-ghost btn-sm" rel="next">
                        {consoleRich('console.next_part1_c0df1ece', {
                            part1: (
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4"
                                    style={{ transform: 'rotate(-90deg)' }}
                                />
                            ),
                        })}
                    </Link>
                ) : (
                    <span
                        className="btn btn-ghost btn-sm"
                        aria-disabled="true"
                        style={{ opacity: 0.5 }}
                    >
                        {consoleRich('console.next_part1_c0df1ece', {
                            part1: (
                                <Icon
                                    name="chevron"
                                    className="w-4 h-4"
                                    style={{ transform: 'rotate(-90deg)' }}
                                />
                            ),
                        })}
                    </span>
                )}
            </div>
        </nav>
    );
}
