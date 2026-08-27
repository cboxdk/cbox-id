import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Icon, Input, PageHeader, Pill } from '@/ui';

interface ReviewRow {
    id: string;
    name: string;
    /** Relative due date, or null when the review carries no deadline. */
    dueAt: string | null;
    /** Open, and past its due date — the one row on this list that needs attention. */
    overdue: boolean;
    open: boolean;
    href: string;
}

type Props = PageProps<{
    reviews: ReviewRow[];
    search: string;
    createHref: string;
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function AccessReviewsIndex({ reviews, search, createHref }: Props) {
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
                description="Periodically certify who holds which role and membership. Revoked access is applied when the review closes."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New review
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search access reviews"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {reviews.length} {reviews.length === 1 ? 'review' : 'reviews'} found.
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
                            title={`No reviews match “${search}”`}
                            description="No access review matches that name. Try a different search."
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            title="No access reviews yet"
                            description="Access accumulates. People change teams and keep what the old one needed, and nobody notices until an auditor asks. A review snapshots who holds what right now and makes somebody say, grant by grant, whether it is still needed."
                            steps={[
                                'Open a review — it snapshots every role and membership in this organization.',
                                'Work through the list: certify what is still needed, revoke what is not.',
                                'Close it. Every revoke is applied for real, and anything left un-reviewed follows the review’s policy.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New review
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
                                {review.dueAt !== null && (
                                    <p
                                        className="text-xs truncate"
                                        style={{
                                            color: review.overdue
                                                ? 'var(--destructive)'
                                                : 'var(--faint)',
                                        }}
                                    >
                                        {review.open ? 'Due' : 'Was due'} {review.dueAt}
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
                                {review.overdue ? 'Overdue' : review.open ? 'Open' : 'Closed'}
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
