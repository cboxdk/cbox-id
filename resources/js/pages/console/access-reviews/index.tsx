import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Pill,
} from '@/ui';

interface ReviewRow {
    id: string;
    name: string;
    /** Relative due date, or null when the review carries no deadline. */
    dueAt: string | null;
    /** Open, and past its due date — the one row on this list that needs attention. */
    overdue: boolean;
    open: boolean;
    /** A review of staff roles — the environment's, belonging to no organization. */
    staff: boolean;
    /** Whose access it reviews, on the list that holds every organization's. */
    organization: string | null;
    href: string;
}

type Props = PageProps<{
    reviews: ReviewRow[];
    search: string;
    createHref: string;
    help: HelpContent;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function AccessReviewsIndex({
    reviews,
    search,
    createHref,
    help,
    organizationFilter,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [term, setTerm] = useState(search);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.periodically_certify_who_holds_which_role_and_membershi_ebedf1d0',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_review_e07cc37f', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                    aria-label={consoleT('console.search_access_reviews_0759160d')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {consoleRich('console.search_results_found', { count: reviews.length })}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {reviews.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleFinalText('console.no_reviews_match_part1_825a58bd', {
                                part1: search,
                            })}
                            description={consoleT(
                                'console.no_access_review_matches_that_name_try_a_different_sear_752eb9ce',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            title={consoleT('console.no_access_reviews_yet_66466767')}
                            description={consoleT(
                                'console.access_accumulates_people_change_teams_and_keep_what_th_cd6b423c',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.open_a_review_it_snapshots_every_role_and_membership_in_2bcb5eed',
                                ),
                                consoleRuntimeText(
                                    'console.work_through_the_list_certify_what_is_still_needed_revo_9bcc79ab',
                                ),
                                consoleRuntimeText(
                                    'console.close_it_every_revoke_is_applied_for_real_and_anything_b4db8386',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_new_review_e07cc37f', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    reviews.map((review, index) => (
                        <Link
                            key={review.id}
                            href={review.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < reviews.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate">{review.name}</span>
                                {review.organization !== null && (
                                    <Badge className="ml-2">{review.organization}</Badge>
                                )}
                                {review.staff && (
                                    <Badge tone="info" className="ml-2">
                                        {consoleT('console.admin_support_roles_19f22d25')}
                                    </Badge>
                                )}
                                {review.dueAt !== null && (
                                    <p
                                        className="text-xs truncate"
                                        style={{
                                            color: review.overdue
                                                ? 'var(--destructive)'
                                                : 'var(--faint)',
                                        }}
                                    >
                                        {review.open
                                            ? consoleText('console.due_9071738f')
                                            : consoleText('console.was_due_678bb0d7')}{' '}
                                        {review.dueAt}
                                    </p>
                                )}
                            </div>

                            <Pill
                                tone={
                                    review.overdue
                                        ? 'destructive'
                                        : review.open
                                          ? 'warning'
                                          : 'success'
                                }
                            >
                                {review.overdue
                                    ? consoleText('console.overdue_d660945b')
                                    : review.open
                                      ? consoleText('console.open_ed077f3d')
                                      : consoleText('console.closed_c21ead06')}
                            </Pill>

                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>
        </>
    );
}

AccessReviewsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
