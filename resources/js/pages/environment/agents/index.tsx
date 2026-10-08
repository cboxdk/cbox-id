import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { RISK_LABEL, RISK_TONE, type Risk } from '@/lib/agents';
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
    help: HelpContent;
}>;

export default function Agents({
    agents,
    inactiveCount,
    showAll,
    environmentId,
    waitingForYou,
    urls,
    help,
}: Props) {
    const { freshKey, freshKeyName } = usePage().flash;
    const [revoking, setRevoking] = useState<AgentRow | null>(null);
    const [rotating, setRotating] = useState<AgentRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description="Software that acts on this environment with a management key — Claude Code, Cursor, an internal bot, your own backend. Each holds only the scopes you gave it, and waits for your approval where you asked it to."
                actions={
                    <>
                        <Button asChild size="sm" variant="secondary">
                            <Link href={urls.connect}>Connect an agent</Link>
                        </Button>
                        <Button asChild size="sm" variant="primary" icon="plus">
                            <Link href={urls.create}>New agent</Link>
                        </Button>
                    </>
                }
            />

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
                                ? 'An agent is waiting for your approval.'
                                : `${waitingForYou} agent actions are waiting for your approval.`}
                        </span>
                        <span className="text-sm" style={{ color: 'var(--primary)' }}>
                            Review →
                        </span>
                    </Link>
                )}

                {freshKey !== undefined && (
                    <RevealedKey
                        value={freshKey}
                        title={
                            freshKeyName !== undefined
                                ? `The new key for ${freshKeyName} — copy it now, you won't be able to see it again.`
                                : undefined
                        }
                    />
                )}

                {agents.length === 0 && inactiveCount === 0 ? (
                    <div className="card">
                        <EmptyState
                            icon="magic"
                            equivalent="keys.create"
                            title="No agents yet"
                            description="Create a key for Claude Code, Cursor or your own bot. Give it only the scopes it needs, and decide which of its actions wait for your approval."
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={urls.create}>New agent</Link>
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    <Panel
                        title="Agents"
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
                                        ? 'Hide revoked and expired'
                                        : `Show ${inactiveCount} revoked or expired`}
                                </Link>
                            ) : undefined
                        }
                    >
                        {agents.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                No live agents. Revoked and expired keys stay listed for the record.
                            </p>
                        ) : (
                            <ul aria-label="Agents">
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
                title={rotating === null ? '' : `Rotate ${rotating.name}?`}
                description="A new key with the same scopes and approval policy is created and shown once. The current key keeps working for 24 hours, so whatever uses it can switch without an outage."
                footer={
                    <>
                        <Button onClick={() => setRotating(null)}>Cancel</Button>
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
                            Rotate key
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
                        : 'The agent stops working immediately. This cannot be undone.'
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
                    <Tooltip content="The most harmful thing its scopes allow">
                        <span>
                            <Badge tone={RISK_TONE[agent.risk]}>
                                {RISK_LABEL[agent.risk]}
                                <span className="sr-only"> risk</span>
                            </Badge>
                        </span>
                    </Tooltip>
                    {agent.depth > 0 && <Badge>Minted by a key</Badge>}
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
                        Created by {agent.createdBy}
                    </span>
                </p>

                <KeyTimeline lifecycle={agent.lifecycle} prefix={agent.prefix} />
            </div>

            {(agent.rotateHref !== null || agent.revokeHref !== null) && (
                <div className="flex gap-2 shrink-0">
                    {agent.rotateHref !== null && (
                        <Button size="sm" variant="secondary" onClick={onRotate}>
                            Rotate
                        </Button>
                    )}
                    {agent.revokeHref !== null && (
                        <Button size="sm" variant="danger" onClick={onRevoke}>
                            Revoke
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

Agents.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
