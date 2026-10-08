import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { RISK_LABEL, RISK_TONE, type Risk } from '@/lib/agents';
import { absoluteTime, relativeTime } from '@/lib/time';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Icon,
    PageHeader,
    Pagination,
    Panel,
    Pill,
    type PillTone,
} from '@/ui';

/** `App\Http\Props\Console\ActionApprovalRowProps` — an agent's held action. */
interface ActionApproval {
    id: string;
    agent: string;
    agentId: string | null;
    action: { name: string; summary: string | null; danger: Risk | null };
    target: string | null;
    arguments: { field: string; value: string; redacted: boolean }[];
    status: 'pending' | 'approved' | 'denied' | 'expired' | 'consumed';
    statusLabel: string;
    bindingCode: string | null;
    requestedAt: string | null;
    expiresAt: string;
    /** "You", or the person it waits for. */
    approver: string;
    mine: boolean;
    /** Approving asks for the password first: a critical action, and no fresh step-up. */
    needsSudo: boolean;
    approveHref: string | null;
    denyHref: string | null;
}

/** A request from an app to act as one of the environment's users (OIDC CIBA). */
interface ApprovalRow {
    id: string;
    app: string;
    subject: string;
    bindingMessage: string | null;
    scopes: { value: string; label: string }[];
    denyHref: string;
}

const STATUS_TONE: Record<ActionApproval['status'], PillTone> = {
    pending: 'info',
    approved: 'success',
    consumed: 'success',
    denied: 'destructive',
    expired: 'warning',
};

type Props = PageProps<{
    waiting: ActionApproval[];
    decided: ActionApproval[];
    agentsHref: string;
    requests: ApprovalRow[];
    pagination: PaginationState;
    help: HelpContent;
}>;

export default function AgentApprovals({
    waiting,
    decided,
    agentsHref,
    requests,
    pagination,
    help,
}: Props) {
    const [denying, setDenying] = useState<ApprovalRow | null>(null);
    const [denyingAction, setDenyingAction] = useState<ActionApproval | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description="What software in this environment is waiting for a person to allow. An agent whose key needs approval stops and asks the person who created the key — answer here or on your phone."
            />

            <section className="mt-6 space-y-4" aria-labelledby="held-actions">
                <h2 id="held-actions" className="text-base font-semibold">
                    Agent actions waiting
                </h2>

                {waiting.length === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="magic"
                            title="Nothing is waiting"
                            description="When an agent's key needs approval for an action, the request appears here and on the phone of the person who created the key. It waits up to fifteen minutes."
                            actions={
                                <Button asChild variant="secondary">
                                    <Link href={agentsHref}>Review agents</Link>
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    waiting.map((approval) => (
                        <HeldAction
                            key={approval.id}
                            approval={approval}
                            onDeny={() => setDenyingAction(approval)}
                        />
                    ))
                )}

                {decided.length > 0 && (
                    <Panel title="Recently answered" flush>
                        <ul>
                            {decided.map((approval, index) => (
                                <li
                                    key={approval.id}
                                    className="flex items-center gap-3 flex-wrap px-4 py-3 text-sm"
                                    style={
                                        index === decided.length - 1
                                            ? undefined
                                            : { borderBottom: '1px solid var(--border)' }
                                    }
                                >
                                    <Pill tone={STATUS_TONE[approval.status]}>
                                        {approval.statusLabel}
                                    </Pill>
                                    <span className="font-medium">{approval.agent}</span>
                                    <span className="mono text-xs">{approval.action.name}</span>
                                    {approval.target !== null && (
                                        <span style={{ color: 'var(--muted-foreground)' }}>
                                            {approval.target}
                                        </span>
                                    )}
                                    {approval.requestedAt !== null && (
                                        <time
                                            dateTime={approval.requestedAt}
                                            className="ml-auto text-xs"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {relativeTime(approval.requestedAt)} ·{' '}
                                            {absoluteTime(approval.requestedAt)}
                                        </time>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
            </section>

            <section className="mt-10 space-y-4" aria-labelledby="user-requests">
                <div>
                    <h2 id="user-requests" className="text-base font-semibold">
                        Requests to act as a user
                    </h2>
                    <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        Apps asking to act on one of your users' behalf. Each user approves their
                        own; deny one here only if it looks like abuse — the denial is recorded in
                        the audit log.
                    </p>
                </div>

                {requests.length === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="shield"
                            title="No pending requests"
                            description="Requests from agents asking to act on a user's behalf appear here as they arrive. Each user approves their own; this page is for denying one that looks like abuse."
                        />
                    </div>
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
            </section>

            <ConfirmDelete
                open={denyingAction !== null}
                onOpenChange={(open) => !open && setDenyingAction(null)}
                name={denyingAction?.agent ?? ''}
                verb="Deny the action from"
                consequence="The agent is told no when it asks again, and has to start over. The denial is recorded in the audit log."
                onConfirm={() => {
                    const approval = denyingAction;
                    setDenyingAction(null);

                    if (approval?.denyHref) {
                        router.post(approval.denyHref, {}, { preserveScroll: true });
                    }
                }}
            />

            <ConfirmDelete
                open={denying !== null}
                onOpenChange={(open) => !open && setDenying(null)}
                name={denying?.app ?? ''}
                verb="Deny the request from"
                consequence="The agent is refused and cannot act on this person's behalf. The denial is recorded in the audit log. It cannot be undone — the agent would have to ask again."
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

/**
 * One held action: who asked, what it would do, to what, with which arguments — and the
 * code to match against the one the agent is showing its user.
 */
function HeldAction({ approval, onDeny }: { approval: ActionApproval; onDeny: () => void }) {
    const danger = approval.action.danger;

    return (
        <article
            className="rounded-xl border p-5"
            style={{ borderColor: 'var(--border)' }}
            aria-label={`${approval.agent} wants to run ${approval.action.name}`}
        >
            <div className="flex items-start gap-3">
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
                    <Icon name="magic" className="w-5 h-5" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="font-semibold flex items-center gap-2 flex-wrap">
                        <span>{approval.agent}</span>
                        <span className="font-normal" style={{ color: 'var(--muted-foreground)' }}>
                            wants to run
                        </span>
                        <span className="mono text-sm">{approval.action.name}</span>
                        {danger !== null && (
                            <Badge tone={RISK_TONE[danger]}>{RISK_LABEL[danger]}</Badge>
                        )}
                    </p>
                    {approval.action.summary !== null && (
                        <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                            {approval.action.summary}
                        </p>
                    )}
                </div>
            </div>

            <dl className="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[9rem_1fr]">
                {approval.target !== null && (
                    <>
                        <dt style={{ color: 'var(--muted-foreground)' }}>On</dt>
                        <dd>{approval.target}</dd>
                    </>
                )}
                {approval.arguments.map((argument) => (
                    <div key={argument.field} className="contents">
                        <dt
                            className="mono text-xs pt-0.5"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {argument.field}
                        </dt>
                        <dd className="break-all">
                            {argument.redacted ? (
                                <span style={{ color: 'var(--muted-foreground)' }}>
                                    hidden — a secret
                                </span>
                            ) : (
                                argument.value
                            )}
                        </dd>
                    </div>
                ))}
                <dt style={{ color: 'var(--muted-foreground)' }}>Waiting for</dt>
                <dd>{approval.approver}</dd>
                <dt style={{ color: 'var(--muted-foreground)' }}>Expires</dt>
                <dd>
                    <time dateTime={approval.expiresAt}>
                        {relativeTime(approval.expiresAt)} · {absoluteTime(approval.expiresAt)}
                    </time>
                </dd>
            </dl>

            {approval.bindingCode !== null && (
                <div
                    className="mt-4 rounded-lg px-3.5 py-3"
                    style={{ background: 'var(--accent-soft)' }}
                >
                    <p className="label">Check the agent shows the same code</p>
                    <p className="mt-1 mono text-lg font-semibold tracking-widest">
                        {approval.bindingCode}
                    </p>
                </div>
            )}

            <div className="mt-5 flex flex-wrap items-center gap-2.5">
                {approval.approveHref !== null && (
                    <Button
                        variant="primary"
                        onClick={() =>
                            approval.approveHref !== null &&
                            router.post(approval.approveHref, {}, { preserveScroll: true })
                        }
                    >
                        Approve
                    </Button>
                )}
                {approval.denyHref !== null && (
                    <Button variant="danger" onClick={onDeny}>
                        Deny
                    </Button>
                )}
                {approval.needsSudo && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        A critical action — you will confirm your password first.
                    </span>
                )}
                {!approval.mine && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Only {approval.approver} can approve it. You can deny it.
                    </span>
                )}
            </div>
        </article>
    );
}

AgentApprovals.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
