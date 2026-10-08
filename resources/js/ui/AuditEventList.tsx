import { absoluteTime } from '@/lib/time';
import { Badge } from './Badge';

/** One audit event an app sent, as the console and the Admin Portal draw it. */
export interface AuditEventRow {
    id: string;
    occurredAt: string;
    action: string;
    actor: { id: string; type: string; name: string | null };
    targets: { id: string; type: string; name: string | null }[];
    location: string | null;
    /** Metadata as name/value pairs, values already strings. */
    metadata: [string, string][];
    /** Whose organization — named on the environment-wide list only. */
    organization?: string | null;
    /** The chain position and hash — shown to the app's own team, not in the portal. */
    sequence?: number;
    hash?: string;
}

/** The words around the values — English on the console, the visitor's language in the portal. */
export interface AuditEventListLabels {
    caption: string;
    actor: string;
    targets: string;
    location: string;
}

/**
 * A list of audit events, newest first.
 *
 * A list rather than a table: an event's targets and metadata are as long as the app made
 * them, and a table squeezes them into a column nobody can read at a laptop's width. Each
 * row says the four things a person scans for — when, what, who, to what — and opens to
 * the rest. Every value is something an app SENT, so it is rendered as text and only ever
 * as text.
 */
export function AuditEventList({
    events,
    labels,
}: {
    events: AuditEventRow[];
    labels: AuditEventListLabels;
}) {
    return (
        <ol
            aria-label={labels.caption}
            className="rounded-xl border overflow-hidden"
            style={{ borderColor: 'var(--border)' }}
        >
            {events.map((event, index) => (
                <li
                    key={event.id}
                    style={
                        index < events.length - 1
                            ? { borderBottom: '1px solid var(--border)' }
                            : undefined
                    }
                >
                    <details className="group">
                        <summary className="flex flex-wrap items-center gap-x-3 gap-y-1 p-4 cursor-pointer hover:bg-[var(--surface-2)]">
                            <time
                                dateTime={event.occurredAt}
                                className="mono text-xs shrink-0"
                                style={{ color: 'var(--faint)', minWidth: '11rem' }}
                            >
                                {absoluteTime(event.occurredAt)}
                            </time>
                            <span className="mono text-sm font-medium">{event.action}</span>
                            <span className="text-sm" style={{ color: 'var(--muted)' }}>
                                <span className="sr-only">{labels.actor}: </span>
                                {event.actor.name ?? event.actor.id}
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    {' '}
                                    ({event.actor.type})
                                </span>
                            </span>
                            {event.targets.length > 0 && (
                                <span className="flex flex-wrap gap-1">
                                    <span className="sr-only">{labels.targets}: </span>
                                    {event.targets.map((target) => (
                                        <Badge key={`${target.type}:${target.id}`}>
                                            {target.type}: {target.name ?? target.id}
                                        </Badge>
                                    ))}
                                </span>
                            )}
                            {event.organization !== undefined && event.organization !== null && (
                                <span className="text-xs ml-auto" style={{ color: 'var(--faint)' }}>
                                    {event.organization}
                                </span>
                            )}
                        </summary>
                        <dl
                            className="grid gap-x-4 gap-y-1 px-4 pb-4 text-xs"
                            style={{ gridTemplateColumns: 'max-content 1fr' }}
                        >
                            <dt style={{ color: 'var(--faint)' }}>{labels.actor}</dt>
                            <dd className="mono break-all">
                                {event.actor.type} · {event.actor.id}
                            </dd>
                            {event.targets.map((target) => (
                                <TargetRow
                                    key={`${target.type}:${target.id}`}
                                    label={labels.targets}
                                    target={target}
                                />
                            ))}
                            {event.location !== null && (
                                <>
                                    <dt style={{ color: 'var(--faint)' }}>{labels.location}</dt>
                                    <dd className="mono break-all">{event.location}</dd>
                                </>
                            )}
                            {event.metadata.map(([name, value]) => (
                                <MetadataRow key={name} name={name} value={value} />
                            ))}
                            {event.sequence !== undefined && event.hash !== undefined && (
                                <>
                                    <dt style={{ color: 'var(--faint)' }}>#{event.sequence}</dt>
                                    <dd
                                        className="mono break-all"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {event.hash}
                                    </dd>
                                </>
                            )}
                        </dl>
                    </details>
                </li>
            ))}
        </ol>
    );
}

function TargetRow({ label, target }: { label: string; target: AuditEventRow['targets'][number] }) {
    return (
        <>
            <dt style={{ color: 'var(--faint)' }}>{label}</dt>
            <dd className="mono break-all">
                {target.type} · {target.id}
                {target.name !== null ? ` · ${target.name}` : ''}
            </dd>
        </>
    );
}

function MetadataRow({ name, value }: { name: string; value: string }) {
    return (
        <>
            <dt className="mono" style={{ color: 'var(--faint)' }}>
                {name}
            </dt>
            <dd className="mono break-all">{value}</dd>
        </>
    );
}
