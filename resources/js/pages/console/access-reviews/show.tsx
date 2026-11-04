import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    ConfirmDelete,
    EmptyState,
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
    review: { id: string; name: string; open: boolean; staff: boolean };
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
    const { t: tc } = useTranslator();

    const [closing, setClosing] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={tc('console.access_reviews_f0cc91be')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{review.name}</h1>
                    <Pill tone={review.open ? 'warning' : 'success'}>
                        {review.open ? tc('console.open_ed077f3d') : tc('console.closed_c21ead06')}
                    </Pill>
                    {review.staff && (
                        <Badge tone="info">{tc('console.admin_support_roles_19f22d25')}</Badge>
                    )}
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {review.id}
                </p>
            </div>

            <Panel
                title={tc('console.items_fb8e7a1a')}
                description={
                    review.open
                        ? review.staff
                            ? tc('console.certify_what_is_still_needed_revoke_what_is__31e57691')
                            : tc('console.certify_what_is_still_needed_revoke_what_is__4e0eb335')
                        : tc('console.this_review_is_closed_its_revokes_have_been__2d247e0a')
                }
                action={
                    review.open ? (
                        <Button
                            size="sm"
                            variant="danger"
                            className="shrink-0"
                            onClick={() => setClosing(true)}
                        >
                            {tc('console.close_amp_apply_f84a7488')}
                        </Button>
                    ) : undefined
                }
                flush={items.length > 0}
            >
                {items.length === 0 ? (
                    <EmptyState
                        icon="roles"
                        title={tc('console.no_access_in_scope_13d3299f')}
                        description={
                            review.staff
                                ? tc(
                                      'console.nobody_held_an_admin_support_role_when_this__9369fb91',
                                  )
                                : tc(
                                      'console.this_organization_has_no_direct_role_or_memb_1160edd2',
                                  )
                        }
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

            <Pagination
                pagination={pagination}
                noun={tc('console.item_4a33eacd')}
                pluralNoun={tc('console.items_5f3c4f85')}
                href={() => window.location.pathname}
            />

            <ConfirmDelete
                open={closing}
                onOpenChange={setClosing}
                name={review.name}
                verb={tc('console.close_and_apply_b1b3a980')}
                consequence={tc('console.every_revoke_recorded_on_this_review_is_appl_1abdd266')}
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
    const { t: tc } = useTranslator();

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
                        {tc('console.certify_c1567c29')}
                    </Button>
                    {/*
                        Revoke sits beside Certify, and a mis-click records a revoke against
                        the wrong person — so it asks, and the question names WHO and WHAT
                        rather than "are you sure".
                    */}
                    <Button size="sm" variant="danger" onClick={() => setConfirmingRevoke(true)}>
                        {tc('console.revoke_87e6d00b')}
                    </Button>
                </div>
            )}

            <ConfirmDelete
                open={confirmingRevoke}
                onOpenChange={setConfirmingRevoke}
                name={`${item.access} from ${who}`}
                verb="Revoke"
                consequence={tc('console.the_revoke_is_recorded_now_and_applied_when__6aa9195a')}
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
    const { t: tc } = useTranslator();

    if (decision === 'certified') {
        return <Pill tone="success">{tc('console.certified_c9996efd')}</Pill>;
    }

    if (decision === 'revoked') {
        return (
            <span className="flex items-center gap-2 flex-wrap">
                <Pill tone="destructive">{tc('console.revoked_f6f738d0')}</Pill>
                {!applied && (
                    <span className="text-xs" style={{ color: 'var(--destructive)' }}>
                        {tc('console.not_applied_306f48b8')}
                        {note !== null && ` — ${note}`}
                    </span>
                )}
            </span>
        );
    }

    return <Pill tone="neutral">{tc('console.pending_331551b0')}</Pill>;
}

AccessReviewDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
