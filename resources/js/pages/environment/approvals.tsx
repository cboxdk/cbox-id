import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import { Badge, Button, ConfirmDelete, EmptyState, Icon, PageHeader, Pagination } from '@/ui';

interface ApprovalRow {
    id: string;
    app: string;
    subject: string;
    bindingMessage: string | null;
    scopes: { value: string; label: string }[];
    denyHref: string;
}

type Props = PageProps<{
    requests: ApprovalRow[];
    pagination: PaginationState;
}>;

export default function AgentApprovals({ requests, pagination }: Props) {
    const [denying, setDenying] = useState<ApprovalRow | null>(null);

    return (
        <>
            <PageHeader description="Requests from agents asking to act on a user's behalf. Each user approves their own. Deny one from here only if it looks like abuse — the denial is recorded in the activity log." />

            <div className="mt-6 space-y-4">
                {requests.length === 0 ? (
                    <EmptyState
                        icon="shield"
                        title="No pending requests"
                        description="Requests from agents asking to act on a user's behalf appear here as they arrive. Each user approves their own; this page is for denying one that looks like abuse."
                    />
                ) : (
                    requests.map((request) => (
                        <div
                            key={request.id}
                            className="rounded-xl border p-5"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <div className="flex items-center gap-3">
                                <span
                                    className="grid place-items-center rounded-full shrink-0"
                                    style={{
                                        width: '2.25rem',
                                        height: '2.25rem',
                                        background: 'var(--accent-soft)',
                                        color: 'var(--accent-strong)',
                                    }}
                                    aria-hidden="true"
                                >
                                    <Icon name="shield" className="w-5 h-5" />
                                </span>
                                <div className="min-w-0">
                                    <p className="font-semibold truncate">
                                        {request.app} is requesting access
                                    </p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        wants to act on behalf of {request.subject}
                                    </p>
                                </div>
                            </div>

                            {request.bindingMessage !== null && (
                                <div
                                    className="mt-4 rounded-lg px-3.5 py-3"
                                    style={{ background: 'var(--accent-soft)' }}
                                >
                                    <p className="label">Confirm this matches the device</p>
                                    <p className="mt-1 font-medium">{request.bindingMessage}</p>
                                </div>
                            )}

                            {request.scopes.length > 0 && (
                                <div className="mt-4">
                                    <p className="label">This will allow {request.app} to</p>
                                    <ul className="mt-2 space-y-2">
                                        {request.scopes.map((scope) => (
                                            <li
                                                key={scope.value}
                                                className="flex items-center gap-2.5 text-sm"
                                            >
                                                <Icon
                                                    name="check"
                                                    className="w-4 h-4 shrink-0"
                                                    style={{ color: 'var(--success-strong)' }}
                                                    aria-hidden="true"
                                                />
                                                <span>{scope.label}</span>
                                                {/*
                                                    The raw scope beside the sentence: the
                                                    operator reading this may also be the
                                                    developer who has to go and find it.
                                                */}
                                                <Badge>{scope.value}</Badge>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <div className="mt-5 flex gap-2.5">
                                <Button variant="danger" onClick={() => setDenying(request)}>
                                    Deny
                                </Button>
                            </div>
                        </div>
                    ))
                )}

                <Pagination
                    pagination={pagination}
                    noun="request"
                    href={(page) =>
                        page > 1
                            ? `${window.location.pathname}?page=${page}`
                            : window.location.pathname
                    }
                />
            </div>

            <ConfirmDelete
                open={denying !== null}
                onOpenChange={(open) => !open && setDenying(null)}
                name={denying?.app ?? ''}
                verb="Deny the request from"
                consequence="The agent is refused and cannot act on this person's behalf. The denial is recorded in the activity log. It cannot be undone — the agent would have to ask again."
                onConfirm={() => {
                    const request = denying;
                    setDenying(null);

                    if (request !== null) {
                        router.post(request.denyHref);
                    }
                }}
            />
        </>
    );
}

AgentApprovals.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
