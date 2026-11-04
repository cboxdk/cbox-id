import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { riskLabel, RISK_TONE, type Risk } from '@/lib/agents';
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
    /** How long a held action waits for its person, in words: "5 minutes". */
    approvalWindow: string;
    requests: ApprovalRow[];
    pagination: PaginationState;
    help: HelpContent;
}>;

export default function AgentApprovals({
    waiting,
    decided,
    agentsHref,
    approvalWindow,
    requests,
    pagination,
    help,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const [denying, setDenying] = useState<ApprovalRow | null>(null);
    const [denyingAction, setDenyingAction] = useState<ActionApproval | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.what_software_in_this_environment_is_waiting_7aa0659a')}
            />

            <section className="mt-6 space-y-4" aria-labelledby="held-actions">
                <h2 id="held-actions" className="text-base font-semibold">
                    {tc('console.agent_actions_waiting_45074d63')}
                </h2>

                {waiting.length === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="magic"
                            title={tc('console.nothing_is_waiting_dea49736')}
                            description={tc(
                                'console.when_an_agent_s_key_needs_approval_for_an_ac_5bb82a16',
                                { value0: approvalWindow },
                            )}
                            actions={
                                <Button asChild variant="secondary">
                                    <Link href={agentsHref}>
                                        {tc('console.review_agents_13c1e3e0')}
                                    </Link>
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
                    <Panel title={tc('console.recently_answered_e4df8829')} flush>
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
                                            {relativeTime(approval.requestedAt, locale)} ·{' '}
                                            {absoluteTime(approval.requestedAt, locale)}
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
                        {tc('console.requests_to_act_as_a_user_b2dec1d3')}
                    </h2>
                    <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.apps_asking_to_act_on_one_of_your_users_beha_4583b737')}
                    </p>
                </div>

                {requests.length === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="shield"
                            title={tc('console.no_pending_requests_883a9f47')}
                            description={tc(
                                'console.requests_from_agents_asking_to_act_on_a_user_c8d607b8',
                            )}
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
                                        {request.app}
                                        {tc('console.is_requesting_access_5416657d')}
                                    </p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {tc('console.wants_to_act_on_behalf_of_db9c7d2a')}
                                        {request.subject}
                                    </p>
                                </div>
                            </div>

                            {request.bindingMessage !== null && (
                                <div
                                    className="mt-4 rounded-lg px-3.5 py-3"
                                    style={{ background: 'var(--accent-soft)' }}
                                >
                                    <p className="label">
                                        {tc('console.confirm_this_matches_the_device_36b8bd55')}
                                    </p>
                                    <p className="mt-1 font-medium">{request.bindingMessage}</p>
                                </div>
                            )}

                            {request.scopes.length > 0 && (
                                <div className="mt-4">
                                    <p className="label">
                                        {tc('console.this_will_allow_80def0a5')}
                                        {request.app}
                                        {tc('console.to_84d0f765')}
                                    </p>
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
                                    {tc('console.deny_05a2d733')}
                                </Button>
                            </div>
                        </div>
                    ))
                )}

                <Pagination
                    pagination={pagination}
                    noun={tc('console.request_1f58b914')}
                    pluralNoun={tc('console.requests_ec72420d')}
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
                verb={tc('console.deny_the_action_from_2b8cd68b')}
                consequence={tc('console.the_agent_is_told_no_when_it_asks_again_and__c28d8870')}
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
                verb={tc('console.deny_the_request_from_f6bd6e83')}
                consequence={tc('console.the_agent_is_refused_and_cannot_act_on_this__e1cd49d2')}
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const danger = approval.action.danger;

    return (
        <article
            className="rounded-xl border p-5"
            style={{ borderColor: 'var(--border)' }}
            aria-label={tc('console.value0_wants_to_run_value1_242631f7', {
                value0: approval.agent,
                value1: approval.action.name,
            })}
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
                            {tc('console.wants_to_run_7517007a')}
                        </span>
                        <span className="mono text-sm">{approval.action.name}</span>
                        {danger !== null && (
                            <Badge tone={RISK_TONE[danger]}>{riskLabel(danger, tc)}</Badge>
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
                        <dt style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.on_13001175')}
                        </dt>
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
                                    {tc('console.hidden_a_secret_0637e459')}
                                </span>
                            ) : (
                                argument.value
                            )}
                        </dd>
                    </div>
                ))}
                <dt style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.waiting_for_68a86b7b')}
                </dt>
                <dd>{approval.approver}</dd>
                <dt style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.expires_f6725f3a')}
                </dt>
                <dd>
                    <time dateTime={approval.expiresAt}>
                        {relativeTime(approval.expiresAt, locale)} ·{' '}
                        {absoluteTime(approval.expiresAt, locale)}
                    </time>
                </dd>
            </dl>

            {approval.bindingCode !== null && (
                <div
                    className="mt-4 rounded-lg px-3.5 py-3"
                    style={{ background: 'var(--accent-soft)' }}
                >
                    <p className="label">
                        {tc('console.check_the_agent_shows_the_same_code_f60009a1')}
                    </p>
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
                        {tc('console.approve_6007acbe')}
                    </Button>
                )}
                {approval.denyHref !== null && (
                    <Button variant="danger" onClick={onDeny}>
                        {tc('console.deny_05a2d733')}
                    </Button>
                )}
                {approval.needsSudo && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.a_critical_action_you_will_confirm_your_pass_879761cf')}
                    </span>
                )}
                {!approval.mine && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.only_c42d075a')}
                        {approval.approver}
                        {tc('console.can_approve_it_you_can_deny_it_169f7ab8')}
                    </span>
                )}
            </div>
        </article>
    );
}

AgentApprovals.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
