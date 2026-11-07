import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [closing, setClosing] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.access_reviews_f0cc91be')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{review.name}</h1>
                    <Pill tone={review.open ? 'warning' : 'success'}>
                        {review.open
                            ? consoleText('console.open_ed077f3d')
                            : consoleText('console.closed_c21ead06')}
                    </Pill>
                    {review.staff && (
                        <Badge tone="info">
                            {consoleT('console.admin_support_roles_19f22d25')}
                        </Badge>
                    )}
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {review.id}
                </p>
            </div>

            <Panel
                title={consoleT('console.items_fb8e7a1a')}
                description={
                    review.open
                        ? review.staff
                            ? consoleText(
                                  'console.certify_what_is_still_needed_revoke_what_is_not_nothing_31e57691',
                              )
                            : consoleText(
                                  'console.certify_what_is_still_needed_revoke_what_is_not_nothing_4e0eb335',
                              )
                        : consoleText(
                              'console.this_review_is_closed_its_revokes_have_been_applied_2d247e0a',
                          )
                }
                action={
                    review.open ? (
                        <Button
                            size="sm"
                            variant="danger"
                            className="shrink-0"
                            onClick={() => setClosing(true)}
                        >
                            {consoleT('console.close_apply_702efee0')}
                        </Button>
                    ) : undefined
                }
                flush={items.length > 0}
            >
                {items.length === 0 ? (
                    <EmptyState
                        icon="roles"
                        title={consoleT('console.no_access_in_scope_13d3299f')}
                        description={
                            review.staff
                                ? consoleText(
                                      'console.nobody_held_an_admin_support_role_when_this_review_was_9369fb91',
                                  )
                                : consoleText(
                                      'console.this_organization_has_no_direct_role_or_membership_gran_1160edd2',
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
                href={() => window.location.pathname}
            />

            <ConfirmDelete
                open={closing}
                onOpenChange={setClosing}
                name={review.name}
                verb={consoleAttributeText('console.close_and_apply_b1b3a980')}
                consequence={consoleUiText(
                    'console.every_revoke_recorded_on_this_review_is_applied_for_rea_1abdd266',
                )}
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
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                        {consoleT('console.certify_c1567c29')}
                    </Button>
                    {/*
                        Revoke sits beside Certify, and a mis-click records a revoke against
                        the wrong person — so it asks, and the question names WHO and WHAT
                        rather than "are you sure".
                    */}
                    <Button size="sm" variant="danger" onClick={() => setConfirmingRevoke(true)}>
                        {consoleT('console.revoke_87e6d00b')}
                    </Button>
                </div>
            )}

            <ConfirmDelete
                open={confirmingRevoke}
                onOpenChange={setConfirmingRevoke}
                name={`${item.access} from ${who}`}
                verb={consoleShortText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.the_revoke_is_recorded_now_and_applied_when_this_review_6aa9195a',
                )}
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
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    if (decision === 'certified') {
        return <Pill tone="success">{consoleT('console.certified_c9996efd')}</Pill>;
    }

    if (decision === 'revoked') {
        return (
            <span className="flex items-center gap-2 flex-wrap">
                <Pill tone="destructive">{consoleT('console.revoked_f6f738d0')}</Pill>
                {!applied && (
                    <span className="text-xs" style={{ color: 'var(--destructive)' }}>
                        {consoleRich('console.not_applied_part1_a7ecc67d', {
                            part1: note !== null && ` — ${note}`,
                        })}
                    </span>
                )}
            </span>
        );
    }

    return <Pill tone="neutral">{consoleT('console.pending_331551b0')}</Pill>;
}

AccessReviewDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
