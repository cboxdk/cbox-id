import { useTranslator } from '@/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { riskLabel, RISK_TONE, type Risk } from '@/lib/agents';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    Dialog,
    EmptyState,
    Icon,
    type KeyLifecycle,
    KeyStatusPill,
    KeyTimeline,
    type LinkTab,
    LinkTabs,
    PageHeader,
    Panel,
    RevealedKey,
    Tooltip,
} from '@/ui';

/** `App\Http\Props\Console\AgentRowProps` */
interface AgentRow {
    id: string;
    name: string;
    description: string | null;
    prefix: string;
    scopes: string[];
    scopeSummary: string;
    risk: Risk;
    approval: { label: string; minDanger: string | null; actions: string[] };
    createdBy: string;
    parentId: string | null;
    rotatedFromId: string | null;
    depth: number;
    /** Live keys this one minted, all the way down — revoked with it. */
    descendants: number;
    lifecycle: KeyLifecycle;
    rotateHref: string | null;
    revokeHref: string | null;
}

type Props = PageProps<{
    agents: AgentRow[];
    inactiveCount: number;
    showAll: boolean;
    environmentId: string;
    waitingForYou: number;
    urls: { create: string; connect: string; approvals: string; index: string };
    /** The key kinds, as on API keys — this page is the Secret keys tab. */
    tabs: LinkTab[];
    help: HelpContent;
}>;

export default function Agents({
    agents,
    inactiveCount,
    showAll,
    environmentId,
    waitingForYou,
    urls,
    tabs,
    help,
}: Props) {
    const { t: tc } = useTranslator();

    const { freshKey, freshKeyName } = usePage().flash;
    const [revoking, setRevoking] = useState<AgentRow | null>(null);
    const [rotating, setRotating] = useState<AgentRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.software_that_acts_on_this_environment_with__24a4b34d')}
                actions={
                    <>
                        <Button asChild size="sm" variant="secondary">
                            <Link href={urls.connect}>
                                {tc('console.connect_an_agent_ce504be3')}
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="primary" icon="plus">
                            <Link href={urls.create}>{tc('console.new_agent_98a23e6d')}</Link>
                        </Button>
                    </>
                }
            />

            <div className="mt-4">
                <LinkTabs tabs={tabs} label={tc('console.key_types_0562e0e6')} />
            </div>

            <div className="mt-6 space-y-6">
                {waitingForYou > 0 && (
                    <Link
                        href={urls.approvals}
                        className="flex items-center gap-3 rounded-xl border px-4 py-3"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--primary) 35%, transparent)',
                            background: 'var(--accent-soft)',
                        }}
                    >
                        <Icon name="shield" className="w-5 h-5 shrink-0" />
                        <span className="text-sm font-medium flex-1">
                            {waitingForYou === 1
                                ? tc('console.an_agent_is_waiting_for_your_approval_14b2c367')
                                : tc(
                                      'console.value0_agent_actions_are_waiting_for_your_ap_de2e475c',
                                      { value0: waitingForYou },
                                  )}
                        </span>
                        <span className="text-sm" style={{ color: 'var(--primary)' }}>
                            {tc('console.review_ab7681ea')}
                        </span>
                    </Link>
                )}

                {freshKey !== undefined && (
                    <RevealedKey
                        value={freshKey}
                        title={
                            freshKeyName !== undefined
                                ? tc(
                                      'console.the_new_key_for_value0_copy_it_now_you_won_t_28a892ec',
                                      { value0: freshKeyName },
                                  )
                                : undefined
                        }
                    />
                )}

                {agents.length === 0 && inactiveCount === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="magic"
                            equivalent="keys.create"
                            title={tc('console.no_agents_yet_971b61f8')}
                            description={tc(
                                'console.create_a_key_for_claude_code_cursor_or_your__90d822be',
                            )}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={urls.create}>
                                        {tc('console.new_agent_98a23e6d')}
                                    </Link>
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    <Panel
                        title={tc('console.agents_279b44d2')}
                        flush={agents.length > 0}
                        action={
                            inactiveCount > 0 ? (
                                <Link
                                    href={showAll ? urls.index : `${urls.index}?all=1`}
                                    className="text-sm"
                                    style={{ color: 'var(--muted-foreground)' }}
                                    preserveScroll
                                >
                                    {showAll
                                        ? tc('console.hide_revoked_and_expired_c783740b')
                                        : tc('console.show_value0_revoked_or_expired_9dbdd15e', {
                                              value0: inactiveCount,
                                          })}
                                </Link>
                            ) : undefined
                        }
                    >
                        {agents.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                {tc(
                                    'console.no_live_agents_revoked_and_expired_keys_stay_8dd5437a',
                                )}
                            </p>
                        ) : (
                            <ul aria-label={tc('console.agents_279b44d2')}>
                                {agents.map((agent, index) => (
                                    <AgentItem
                                        key={agent.id}
                                        agent={agent}
                                        last={index === agents.length - 1}
                                        onRotate={() => setRotating(agent)}
                                        onRevoke={() => setRevoking(agent)}
                                    />
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}
            </div>

            <Dialog
                open={rotating !== null}
                onOpenChange={(open) => !open && setRotating(null)}
                title={
                    rotating === null
                        ? ''
                        : tc('console.rotate_value0_c01d8336', { value0: rotating.name })
                }
                description={tc('console.a_new_key_with_the_same_scopes_and_approval__c8fff425')}
                footer={
                    <>
                        <Button onClick={() => setRotating(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="primary"
                            onClick={() => {
                                const agent = rotating;
                                setRotating(null);

                                if (agent?.rotateHref) {
                                    router.post(agent.rotateHref, {}, { preserveScroll: true });
                                }
                            }}
                        >
                            {tc('console.rotate_key_c66b8b38')}
                        </Button>
                    </>
                }
            />

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.name ?? ''}
                verb="Revoke"
                consequence={
                    revoking !== null && revoking.descendants > 0
                        ? `The agent stops working immediately — and so do the ${revoking.descendants} ${revoking.descendants === 1 ? 'key' : 'keys'} it minted, which are revoked with it. This cannot be undone.`
                        : tc('console.the_agent_stops_working_immediately_this_can_910eb1ce')
                }
                onConfirm={() => {
                    const agent = revoking;
                    setRevoking(null);

                    if (agent?.revokeHref) {
                        router.delete(agent.revokeHref, {
                            data: { environment: environmentId },
                            preserveScroll: true,
                        });
                    }
                }}
            />
        </>
    );
}

function AgentItem({
    agent,
    last,
    onRotate,
    onRevoke,
}: {
    agent: AgentRow;
    last: boolean;
    onRotate: () => void;
    onRevoke: () => void;
}) {
    const { t: tc } = useTranslator();

    const inactive = agent.lifecycle.status !== 'active';

    return (
        <li
            className="flex items-start gap-3 flex-wrap px-4 py-3.5"
            style={{
                borderBottom: last ? undefined : '1px solid var(--border)',
                // A key a key minted sits under its parent, so revoking one visibly takes
                // the branch with it.
                paddingLeft: `calc(1rem + ${Math.min(agent.depth, 4) * 1.5}rem)`,
                opacity: inactive ? 0.7 : undefined,
            }}
        >
            {agent.depth > 0 && (
                <span
                    aria-hidden="true"
                    className="mono text-sm shrink-0"
                    style={{ color: 'var(--faint)', marginLeft: '-1.25rem', width: '1rem' }}
                >
                    ↳
                </span>
            )}

            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-medium truncate">{agent.name}</span>
                    <KeyStatusPill lifecycle={agent.lifecycle} />
                    <Tooltip
                        content={tc('console.the_most_harmful_thing_its_scopes_allow_5b434c86')}
                    >
                        <span>
                            <Badge tone={RISK_TONE[agent.risk]}>
                                {riskLabel(agent.risk, tc)}
                                <span className="sr-only">{tc('console.risk_56ce854b')}</span>
                            </Badge>
                        </span>
                    </Tooltip>
                    {agent.depth > 0 && <Badge>{tc('console.minted_by_a_key_fd2e1030')}</Badge>}
                </div>

                {agent.description !== null && (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {agent.description}
                    </p>
                )}

                <p className="text-sm flex flex-wrap gap-x-2 gap-y-0.5">
                    <Tooltip content={agent.scopes.join(', ')}>
                        <span className="underline decoration-dotted underline-offset-2">
                            {agent.scopeSummary}
                        </span>
                    </Tooltip>
                    <span aria-hidden="true" style={{ color: 'var(--faint)' }}>
                        ·
                    </span>
                    <span className="inline-flex items-center gap-1">
                        {agent.approval.minDanger !== null || agent.approval.actions.length > 0 ? (
                            <Icon name="shield" className="w-3.5 h-3.5" />
                        ) : null}
                        {agent.approval.label}
                    </span>
                    <span aria-hidden="true" style={{ color: 'var(--faint)' }}>
                        ·
                    </span>
                    <span style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.created_by_b2742e6c')}
                        {agent.createdBy}
                    </span>
                </p>

                <KeyTimeline lifecycle={agent.lifecycle} prefix={agent.prefix} />
            </div>

            {(agent.rotateHref !== null || agent.revokeHref !== null) && (
                <div className="flex gap-2 shrink-0">
                    {agent.rotateHref !== null && (
                        <Button size="sm" variant="secondary" onClick={onRotate}>
                            {tc('console.rotate_c3613b17')}
                        </Button>
                    )}
                    {agent.revokeHref !== null && (
                        <Button size="sm" variant="danger" onClick={onRevoke}>
                            {tc('console.revoke_87e6d00b')}
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

Agents.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
