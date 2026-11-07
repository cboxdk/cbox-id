import { EnvironmentReadOnly } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [denying, setDenying] = useState<ApprovalRow | null>(null);
    const [denyingAction, setDenyingAction] = useState<ActionApproval | null>(null);

    return (
        <EnvironmentReadOnly>
            <>
                <PageHeader
                    help={help}
                    description={consoleT(
                        'console.what_software_in_this_environment_is_waiting_for_a_pers_7aa0659a',
                    )}
                />

                <section className="mt-6 space-y-4" aria-labelledby="held-actions">
                    <h2 id="held-actions" className="text-base font-semibold">
                        {consoleT('console.agent_actions_waiting_45074d63')}
                    </h2>

                    {waiting.length === 0 ? (
                        <div className="card">
                            <EmptyState
                                icon="magic"
                                title={consoleT('console.nothing_is_waiting_dea49736')}
                                description={consoleFinalText(
                                    'console.when_an_agent_s_key_needs_approval_for_an_action_the_re_56c5e366',
                                    { part1: approvalWindow },
                                )}
                                actions={
                                    <Button asChild variant="secondary">
                                        <Link href={agentsHref}>
                                            {consoleT('console.review_agents_13c1e3e0')}
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
                        <Panel title={consoleT('console.recently_answered_e4df8829')} flush>
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
                                                {relativeTime(approval.requestedAt, consoleLocale)}{' '}
                                                ·{' '}
                                                {absoluteTime(approval.requestedAt, consoleLocale)}
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
                            {consoleT('console.requests_to_act_as_a_user_b2dec1d3')}
                        </h2>
                        <p className="text-sm mt-0.5" style={{ color: 'var(--muted-foreground)' }}>
                            {consoleT(
                                'console.apps_asking_to_act_on_one_of_your_users_behalf_each_use_4583b737',
                            )}
                        </p>
                    </div>

                    {requests.length === 0 ? (
                        <div className="card">
                            <EmptyState
                                icon="shield"
                                title={consoleT('console.no_pending_requests_883a9f47')}
                                description={consoleT(
                                    'console.requests_from_agents_asking_to_act_on_a_user_s_behalf_a_c8d607b8',
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
                                            {consoleRich(
                                                'console._part1_is_requesting_access_c4d87651',
                                                { part1: request.app },
                                            )}
                                        </p>
                                        <p
                                            className="text-xs truncate"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {consoleRich(
                                                'console.wants_to_act_on_behalf_of_part1_5e98fe61',
                                                { part1: request.subject },
                                            )}
                                        </p>
                                    </div>
                                </div>

                                {request.bindingMessage !== null && (
                                    <div
                                        className="mt-4 rounded-lg px-3.5 py-3"
                                        style={{ background: 'var(--accent-soft)' }}
                                    >
                                        <p className="label">
                                            {consoleT(
                                                'console.confirm_this_matches_the_device_36b8bd55',
                                            )}
                                        </p>
                                        <p className="mt-1 font-medium">{request.bindingMessage}</p>
                                    </div>
                                )}

                                {request.scopes.length > 0 && (
                                    <div className="mt-4">
                                        <p className="label">
                                            {consoleRich(
                                                'console.this_will_allow_part1_to_6ac64554',
                                                {
                                                    part1: request.app,
                                                },
                                            )}
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
                                        {consoleT('console.deny_05a2d733')}
                                    </Button>
                                </div>
                            </div>
                        ))
                    )}

                    <Pagination
                        pagination={pagination}
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
                    title={consoleUiText('console.deny_agent_action_title', {
                        name: denyingAction?.agent ?? '',
                    })}
                    verb={consoleAttributeText('console.deny_05a2d733')}
                    consequence={consoleUiText(
                        'console.the_agent_is_told_no_when_it_asks_again_and_has_to_star_c28d8870',
                    )}
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
                    title={consoleUiText('console.deny_app_request_title', {
                        name: denying?.app ?? '',
                    })}
                    verb={consoleAttributeText('console.deny_05a2d733')}
                    consequence={consoleUiText(
                        'console.the_agent_is_refused_and_cannot_act_on_this_person_s_be_e1cd49d2',
                    )}
                    onConfirm={() => {
                        const request = denying;
                        setDenying(null);

                        if (request !== null) {
                            router.post(request.denyHref);
                        }
                    }}
                />
            </>
        </EnvironmentReadOnly>
    );
}

/**
 * One held action: who asked, what it would do, to what, with which arguments — and the
 * code to match against the one the agent is showing its user.
 */
function HeldAction({ approval, onDeny }: { approval: ActionApproval; onDeny: () => void }) {
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const danger = approval.action.danger;

    return (
        <article
            className="rounded-xl border p-5"
            style={{ borderColor: 'var(--border)' }}
            aria-label={consoleFinalText('console._part1_wants_to_run_part2_c2dd24ac', {
                part1: approval.agent,
                part2: approval.action.name,
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
                        {consoleRich('console._part1_wants_to_run_part2_c2dd24ac', {
                            part1: <span>{approval.agent}</span>,
                            part2: <span className="mono text-sm">{approval.action.name}</span>,
                        })}
                        {danger !== null && (
                            <Badge tone={RISK_TONE[danger]}>{RISK_LABEL(consoleT)[danger]}</Badge>
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
                            {consoleT('console.target_978354db')}
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
                                    {consoleT('console.hidden_a_secret_0637e459')}
                                </span>
                            ) : (
                                argument.value
                            )}
                        </dd>
                    </div>
                ))}
                <dt style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT('console.waiting_for_68a86b7b')}
                </dt>
                <dd>{approval.approver}</dd>
                <dt style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT('console.expires_f6725f3a')}
                </dt>
                <dd>
                    <time dateTime={approval.expiresAt}>
                        {relativeTime(approval.expiresAt, consoleLocale)} ·{' '}
                        {absoluteTime(approval.expiresAt, consoleLocale)}
                    </time>
                </dd>
            </dl>

            {approval.bindingCode !== null && (
                <div
                    className="mt-4 rounded-lg px-3.5 py-3"
                    style={{ background: 'var(--accent-soft)' }}
                >
                    <p className="label">
                        {consoleT('console.check_the_agent_shows_the_same_code_f60009a1')}
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
                        {consoleT('console.approve_6007acbe')}
                    </Button>
                )}
                {approval.denyHref !== null && (
                    <Button variant="danger" onClick={onDeny}>
                        {consoleT('console.deny_05a2d733')}
                    </Button>
                )}
                {approval.needsSudo && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.a_critical_action_you_will_confirm_your_password_first_879761cf',
                        )}
                    </span>
                )}
                {!approval.mine && (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich('console.only_part1_can_approve_it_you_can_deny_it_8264dd54', {
                            part1: approval.approver,
                        })}
                    </span>
                )}
            </div>
        </article>
    );
}

AgentApprovals.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
