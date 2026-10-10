import { Link, router, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    Field,
    Icon,
    Input,
    type LinkTab,
    Pill,
    Select,
    Stat,
} from '@/ui';
import { type RadarMode, RadarFrame } from './frame';

interface Decision {
    id: string;
    assessed_at: string;
    flow: 'sign_in' | 'sign_up';
    method: string | null;
    verdict: 'allow' | 'challenge' | 'block';
    enforced: boolean;
    mode: string;
    rule: string | null;
    rule_name: string | null;
    triggered: { rule: string; name: string }[];
    reasons: string[];
    risk_score: number;
    risk_outcome: string;
    country: string | null;
    asn: number | null;
    email_domain: string | null;
    device: string | null;
    facts: Record<string, string | number | boolean>;
}

interface Filters {
    verdict: string;
    flow: string;
    rule: string;
    country: string;
    email: string;
    ip: string;
    device: string;
    from: string;
    to: string;
}

type Props = PageProps<{
    help: HelpContent;
    tabs: LinkTab[];
    mode: RadarMode;
    summary: { allow: number; challenge: number; block: number };
    decisions: Decision[];
    filters: Filters;
    ruleOptions: { value: string; label: string }[];
    nextHref: string | null;
    firstHref: string | null;
    listStoreHref: string;
}>;

const VERDICT_TONE = { allow: 'success', challenge: 'warning', block: 'destructive' } as const;
const ANY = '__any';

/**
 * THE DECISIONS EXPLORER — every attempt Radar judged, newest first, and why.
 *
 * Read it before switching to enforce: under monitor, each row says what enforcement WOULD
 * have done. Read it after somebody says they cannot sign in: filter by their address (it is
 * matched by pseudonym and never shown) and the row names the rule.
 */
export default function RadarDecisions({
    help,
    tabs,
    mode,
    summary,
    decisions,
    filters,
    ruleOptions,
    nextHref,
    firstHref,
    listStoreHref,
}: Props) {
    const [draft, setDraft] = useState<Filters>(filters);
    const [open, setOpen] = useState<string | null>(null);
    const filtered = Object.values(filters).some((value) => value !== '');

    const apply = (event: FormEvent): void => {
        event.preventDefault();
        router.get(
            window.location.pathname,
            Object.fromEntries(Object.entries(draft).filter(([, value]) => value !== '')),
            { preserveScroll: true },
        );
    };

    const set = (key: keyof Filters) => (value: string) =>
        setDraft({ ...draft, [key]: value === ANY ? '' : value });

    return (
        <RadarFrame
            help={help}
            tabs={tabs}
            mode={mode}
            description="Radar judges every sign-in and sign-up — credential stuffing, bot-like speed, impossible travel, new devices, anonymising networks, throwaway addresses, and your own rules and lists — and records why."
        >
            <section className="mt-6 grid gap-3 sm:grid-cols-3" aria-label="The last 24 hours">
                <Stat
                    icon="check"
                    tone="success"
                    label="Allowed, last 24h"
                    value={summary.allow.toLocaleString()}
                />
                <Stat
                    icon="shield"
                    tone="warning"
                    label="Challenged, last 24h"
                    value={summary.challenge.toLocaleString()}
                />
                <Stat
                    icon="warning"
                    tone="neutral"
                    label="Blocked, last 24h"
                    value={summary.block.toLocaleString()}
                />
            </section>

            <form onSubmit={apply} className="mt-6 grid gap-3 sm:grid-cols-6 items-end">
                <Field label="Verdict">
                    <Select
                        value={draft.verdict === '' ? ANY : draft.verdict}
                        onValueChange={set('verdict')}
                        options={[
                            { value: ANY, label: 'Any' },
                            { value: 'allow', label: 'Allow' },
                            { value: 'challenge', label: 'Challenge' },
                            { value: 'block', label: 'Block' },
                        ]}
                    />
                </Field>
                <Field label="Flow">
                    <Select
                        value={draft.flow === '' ? ANY : draft.flow}
                        onValueChange={set('flow')}
                        options={[
                            { value: ANY, label: 'Both' },
                            { value: 'sign_in', label: 'Sign-in' },
                            { value: 'sign_up', label: 'Sign-up' },
                        ]}
                    />
                </Field>
                <Field label="Rule" className="sm:col-span-2">
                    <Select
                        value={draft.rule === '' ? ANY : draft.rule}
                        onValueChange={set('rule')}
                        options={[{ value: ANY, label: 'Any rule' }, ...ruleOptions]}
                    />
                </Field>
                <Field label="Country">
                    <Input
                        value={draft.country}
                        maxLength={2}
                        placeholder="DK"
                        onChange={(event) =>
                            setDraft({ ...draft, country: event.target.value.toUpperCase() })
                        }
                    />
                </Field>
                <Field label="Device">
                    <Input
                        className="mono"
                        value={draft.device}
                        onChange={(event) => setDraft({ ...draft, device: event.target.value })}
                    />
                </Field>
                <Field
                    label="Email address"
                    className="sm:col-span-2"
                    hint="Matched by pseudonym; never shown."
                >
                    <Input
                        type="email"
                        value={draft.email}
                        onChange={(event) => setDraft({ ...draft, email: event.target.value })}
                    />
                </Field>
                <Field
                    label="IP address"
                    className="sm:col-span-2"
                    hint="Matched by pseudonym; never shown."
                >
                    <Input
                        className="mono"
                        value={draft.ip}
                        onChange={(event) => setDraft({ ...draft, ip: event.target.value })}
                    />
                </Field>
                <Field label="From">
                    <Input
                        type="date"
                        value={draft.from}
                        onChange={(event) => setDraft({ ...draft, from: event.target.value })}
                    />
                </Field>
                <Field label="To">
                    <Input
                        type="date"
                        value={draft.to}
                        onChange={(event) => setDraft({ ...draft, to: event.target.value })}
                    />
                </Field>
                <div className="sm:col-span-6 flex flex-wrap items-center gap-2">
                    <Button type="submit">
                        <Icon name="search" className="w-4 h-4" />
                        Filter
                    </Button>
                    {filtered && (
                        <Button asChild variant="ghost">
                            <Link href={window.location.pathname}>Clear filters</Link>
                        </Button>
                    )}
                </div>
            </form>

            <div className="mt-6">
                {decisions.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="shield"
                            equivalent="radar.decisions.list"
                            title={filtered ? 'No decisions match' : 'No decisions yet'}
                            description={
                                filtered
                                    ? 'Nothing recorded matches these filters. Widen the range or clear a filter.'
                                    : 'Every sign-in and sign-up in this environment is judged and recorded here, from the first attempt.'
                            }
                        />
                    </div>
                ) : (
                    <ul className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        {decisions.map((decision, index) => (
                            <DecisionRow
                                key={decision.id}
                                decision={decision}
                                last={index === decisions.length - 1}
                                open={open === decision.id}
                                onToggle={() => setOpen(open === decision.id ? null : decision.id)}
                                listStoreHref={listStoreHref}
                            />
                        ))}
                    </ul>
                )}
            </div>

            {(nextHref !== null || firstHref !== null) && (
                <nav className="mt-4 flex items-center gap-2" aria-label="Pagination">
                    {firstHref !== null && (
                        <Button asChild variant="ghost">
                            <Link href={firstHref} preserveScroll>
                                Newest
                            </Link>
                        </Button>
                    )}
                    {nextHref !== null && (
                        <Button asChild>
                            <Link href={nextHref} preserveScroll>
                                Older
                            </Link>
                        </Button>
                    )}
                </nav>
            )}
        </RadarFrame>
    );
}

function DecisionRow({
    decision,
    last,
    open,
    onToggle,
    listStoreHref,
}: {
    decision: Decision;
    last: boolean;
    open: boolean;
    onToggle: () => void;
    listStoreHref: string;
}) {
    const deny = useForm({ list: 'deny', kind: 'device', value: decision.device ?? '', note: '' });

    return (
        <li style={last ? undefined : { borderBottom: '1px solid var(--border)' }}>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="w-full text-left flex flex-wrap items-center gap-3 p-4 hover:bg-[var(--surface-2)]"
            >
                <Pill tone={VERDICT_TONE[decision.verdict]}>{decision.verdict}</Pill>
                {!decision.enforced && decision.verdict !== 'allow' && <Badge>monitor only</Badge>}
                <span className="text-sm font-medium">
                    {decision.flow === 'sign_up' ? 'Sign-up' : 'Sign-in'}
                    {decision.method !== null && decision.flow === 'sign_in' && (
                        <span style={{ color: 'var(--faint)' }}>
                            {' '}
                            · {decision.method.replace('_', ' ')}
                        </span>
                    )}
                </span>
                <span className="text-sm" style={{ color: 'var(--muted)' }}>
                    {decision.rule_name ?? 'No rule matched'}
                </span>
                {decision.country !== null && <Badge tone="info">{decision.country}</Badge>}
                {decision.email_domain !== null && (
                    <span className="mono text-xs" style={{ color: 'var(--faint)' }}>
                        @{decision.email_domain}
                    </span>
                )}
                <span className="ml-auto text-xs" style={{ color: 'var(--faint)' }}>
                    {absoluteTime(decision.assessed_at)}
                </span>
            </button>

            {open && (
                <div className="px-4 pb-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <h3
                            className="text-xs font-semibold uppercase tracking-wide"
                            style={{ color: 'var(--faint)' }}
                        >
                            Why
                        </h3>
                        <ul className="mt-2 grid gap-1 text-sm">
                            {decision.reasons.length === 0 ? (
                                <li style={{ color: 'var(--muted)' }}>Nothing fired.</li>
                            ) : (
                                decision.reasons.map((reason) => <li key={reason}>{reason}</li>)
                            )}
                        </ul>
                        {decision.triggered.length > 0 && (
                            <div className="mt-3 flex flex-wrap gap-1.5">
                                {decision.triggered.map((rule) => (
                                    <Badge
                                        key={rule.rule}
                                        tone={rule.rule === decision.rule ? 'warn' : 'neutral'}
                                    >
                                        {rule.name}
                                    </Badge>
                                ))}
                            </div>
                        )}
                        <p className="mt-3 text-xs" style={{ color: 'var(--faint)' }}>
                            Risk score {decision.risk_score} (
                            {decision.risk_outcome.replace('_', ' ')}) · recorded under{' '}
                            {decision.mode}
                        </p>
                    </div>
                    <div>
                        <h3
                            className="text-xs font-semibold uppercase tracking-wide"
                            style={{ color: 'var(--faint)' }}
                        >
                            Facts
                        </h3>
                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                            {Object.entries(decision.facts).map(([key, value]) => (
                                <div key={key} className="contents">
                                    <dt className="mono text-xs" style={{ color: 'var(--muted)' }}>
                                        {key}
                                    </dt>
                                    <dd className="mono text-xs">{String(value)}</dd>
                                </div>
                            ))}
                            {decision.asn !== null && (
                                <div className="contents">
                                    <dt className="mono text-xs" style={{ color: 'var(--muted)' }}>
                                        asn
                                    </dt>
                                    <dd className="mono text-xs">AS{decision.asn}</dd>
                                </div>
                            )}
                        </dl>
                        {decision.device !== null && (
                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <span
                                    className="mono text-xs break-all"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    device {decision.device.slice(0, 16)}…
                                </span>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    loading={deny.processing}
                                    onClick={() =>
                                        deny.post(listStoreHref, { preserveScroll: true })
                                    }
                                >
                                    Deny this device
                                </Button>
                                {deny.errors.value !== undefined && (
                                    <p role="alert" className="field-error">
                                        {deny.errors.value}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </li>
    );
}

RadarDecisions.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
