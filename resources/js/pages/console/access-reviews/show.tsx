import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Icon,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface ReviewItem {
    id: string;
    /** The person's name or address, or null when neither resolves any more. */
    subject: string | null;
    subjectId: string;
    kind: string;
    access: string;
    decision: 'pending' | 'certified' | 'revoked';
    /** False for a revoke that could not be applied when the review closed. */
    applied: boolean;
    note: string | null;
    reviewHref: string;
}

type Props = PageProps<{
    review: { id: string; name: string; open: boolean };
    items: ReviewItem[];
    pagination: PaginationState;
    indexHref: string;
    closeHref: string;
}>;

export default function AccessReviewDetail({
    review,
    items,
    pagination,
    indexHref,
    closeHref,
}: Props) {
    const [closing, setClosing] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Access reviews
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{review.name}</h1>
                    <Pill tone={review.open ? 'warning' : 'success'}>
                        {review.open ? 'Open' : 'Closed'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {review.id}
                </p>
            </div>

            <Panel
                title="Items"
                description={
                    review.open
                        ? 'Certify what is still needed, revoke what is not. Nothing takes effect until the review is closed.'
                        : 'This review is closed. Its revokes have been applied.'
                }
                action={
                    review.open ? (
                        <Button
                            size="sm"
                            variant="danger"
                            className="shrink-0"
                            onClick={() => setClosing(true)}
                        >
                            Close &amp; apply
                        </Button>
                    ) : undefined
                }
                flush={items.length > 0}
            >
                {items.length === 0 ? (
                    <EmptyState
                        icon="roles"
                        title="No access in scope"
                        description="This organization has no direct role or membership grants to certify."
                    />
                ) : (
                    <div>
                        {items.map((item, index) => (
                            <Row
                                key={item.id}
                                item={item}
                                open={review.open}
                                last={index === items.length - 1}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            <Pagination pagination={pagination} noun="item" href={() => window.location.pathname} />

            <ConfirmDelete
                open={closing}
                onOpenChange={setClosing}
                name={review.name}
                verb="Close and apply"
                consequence="Every revoke recorded on this review is applied for real now, and anything still un-reviewed follows the review's policy — which defaults to revoke. This cannot be undone."
                onConfirm={() => {
                    setClosing(false);
                    router.post(closeHref, {}, { preserveScroll: true });
                }}
            />
        </div>
    );
}

/** One grant, and the decision about it. */
function Row({ item, open, last }: { item: ReviewItem; open: boolean; last: boolean }) {
    const [confirmingRevoke, setConfirmingRevoke] = useState(false);

    const decide = (decision: 'certified' | 'revoked'): void => {
        router.post(item.reviewHref, { decision }, { preserveScroll: true });
    };

    const who = item.subject ?? item.subjectId;

    return (
        <div
            className="flex flex-wrap items-center gap-3 px-4 py-3"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                {item.subject !== null ? (
                    <span className="font-medium truncate">{item.subject}</span>
                ) : (
                    // The person is gone from the directory but the grant is still here,
                    // which is exactly the kind of thing a review exists to catch — so the
                    // row is drawn rather than hidden, with the id it still has.
                    <span
                        className="font-medium truncate mono"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        {item.subjectId.slice(0, 16)}
                    </span>
                )}
                <p className="text-sm truncate" style={{ color: 'var(--muted-foreground)' }}>
                    <Badge>{item.kind}</Badge> {item.access}
                </p>
            </div>

            <Decision decision={item.decision} applied={item.applied} note={item.note} />

            {open && (
                <div className="flex items-center gap-2 shrink-0">
                    <Button size="sm" onClick={() => decide('certified')}>
                        Certify
                    </Button>
                    {/*
                        Revoke sits beside Certify, and a mis-click records a revoke against
                        the wrong person — so it asks, and the question names WHO and WHAT
                        rather than "are you sure".
                    */}
                    <Button size="sm" variant="danger" onClick={() => setConfirmingRevoke(true)}>
                        Revoke
                    </Button>
                </div>
            )}

            <ConfirmDelete
                open={confirmingRevoke}
                onOpenChange={setConfirmingRevoke}
                name={`${item.access} from ${who}`}
                verb="Revoke"
                consequence="The revoke is recorded now and applied when this review closes."
                onConfirm={() => {
                    setConfirmingRevoke(false);
                    decide('revoked');
                }}
            />
        </div>
    );
}

/**
 * What was decided, and — after the review closes — whether it took.
 *
 * A revoke that could not be applied is the one state that must never read as done: the
 * review says the access was removed and it is still there.
 */
function Decision({
    decision,
    applied,
    note,
}: {
    decision: ReviewItem['decision'];
    applied: boolean;
    note: string | null;
}) {
    if (decision === 'certified') {
        return <Pill tone="success">Certified</Pill>;
    }

    if (decision === 'revoked') {
        return (
            <span className="flex items-center gap-2 flex-wrap">
                <Pill tone="destructive">Revoked</Pill>
                {!applied && (
                    <span className="text-xs" style={{ color: 'var(--destructive)' }}>
                        not applied{note !== null && ` — ${note}`}
                    </span>
                )}
            </span>
        );
    }

    return <Pill tone="neutral">Pending</Pill>;
}

AccessReviewDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
