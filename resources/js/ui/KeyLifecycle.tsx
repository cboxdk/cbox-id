import { absoluteTime, relativeTime } from '@/lib/time';
import { Field } from './Field';
import { Input } from './Input';
import { Pill, type PillTone } from './Pill';
import { Select } from './Select';

/**
 * Where a machine credential stands, and when things happened to it.
 *
 * The server's `KeyLifecycleProps`, one shape for every key page — the account key page
 * and the environment key page answered this question two different ways, and one of them
 * did not answer it at all.
 */
export interface KeyLifecycle {
    status: 'active' | 'expired' | 'revoked';
    statusLabel: string;
    createdAt: string | null;
    lastUsedAt: string | null;
    expiresAt: string | null;
}

const TONE: Record<KeyLifecycle['status'], PillTone> = {
    active: 'success',
    expired: 'warning',
    revoked: 'destructive',
};

/** The key's state, in words — never only a colour. */
export function KeyStatusPill({ lifecycle }: { lifecycle: KeyLifecycle }) {
    return <Pill tone={TONE[lifecycle.status]}>{lifecycle.statusLabel}</Pill>;
}

/**
 * Created, last used, expires — each as a relative time AND the absolute one.
 *
 * Both, and both visible. A relative time alone cannot be lined up against a deploy log
 * or an incident timeline; an absolute one alone makes the reader do arithmetic to learn
 * that a key has not been used in eight months. Not in a `title` either: a tooltip is
 * unreachable by touch and gone the moment the pointer moves.
 */
export function KeyTimeline({ lifecycle, prefix }: { lifecycle: KeyLifecycle; prefix?: string }) {
    return (
        <dl
            className="flex flex-wrap gap-x-4 gap-y-1 text-xs"
            style={{ color: 'var(--muted-foreground)' }}
        >
            {prefix !== undefined && (
                <div className="flex gap-1">
                    <dt className="sr-only">Prefix</dt>
                    <dd className="mono">{prefix}…</dd>
                </div>
            )}
            {lifecycle.createdAt !== null && <Moment label="Created" at={lifecycle.createdAt} />}
            {lifecycle.lastUsedAt === null ? (
                <div className="flex gap-1">
                    <dt className="sr-only">Last used</dt>
                    <dd>Never used</dd>
                </div>
            ) : (
                <Moment label="Last used" at={lifecycle.lastUsedAt} />
            )}
            {lifecycle.expiresAt === null ? (
                <div className="flex gap-1">
                    <dt className="sr-only">Expires</dt>
                    <dd>No expiry</dd>
                </div>
            ) : (
                <Moment
                    label={lifecycle.status === 'expired' ? 'Expired' : 'Expires'}
                    at={lifecycle.expiresAt}
                />
            )}
        </dl>
    );
}

function Moment({ label, at }: { label: string; at: string }) {
    return (
        <div className="flex gap-1">
            <dt>{label}</dt>
            <dd>
                <time dateTime={at}>
                    {relativeTime(at)}{' '}
                    <span style={{ color: 'var(--faint)' }}>· {absoluteTime(at)}</span>
                </time>
            </dd>
        </div>
    );
}

export interface KeyLifetimeOption {
    value: string;
    label: string;
}

/**
 * Tomorrow in UTC, as the `min` of a date input — the server's own rule (a date after
 * today, in UTC), so the picker never offers a day the form would then refuse.
 */
function tomorrow(): string {
    return new Date(Date.now() + 86_400_000).toISOString().slice(0, 10);
}

/**
 * How long a new key lives: never, a preset, or a date.
 *
 * Both key services have always accepted an expiry, and neither form asked — so every key
 * minted in the console lived forever. The date field appears only for "Custom date", and
 * the server holds the same rule (a date after today), so a crafted request cannot mint a
 * key that is born expired.
 */
export function ExpiryField({
    lifetimes,
    lifetime,
    onLifetimeChange,
    date,
    onDateChange,
    lifetimeError,
    dateError,
}: {
    lifetimes: KeyLifetimeOption[];
    lifetime: string;
    onLifetimeChange: (value: string) => void;
    date: string;
    onDateChange: (value: string) => void;
    lifetimeError?: string;
    dateError?: string;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 items-start">
            <Field label="Expires" error={lifetimeError}>
                <Select
                    value={lifetime}
                    onValueChange={onLifetimeChange}
                    options={lifetimes.map((option) => ({
                        value: option.value,
                        label: option.label,
                    }))}
                />
            </Field>

            {lifetime === 'custom' && (
                <Field
                    label="Expiry date"
                    error={dateError}
                    hint="The key stops working at the end of this day (UTC)."
                >
                    <Input
                        type="date"
                        name="expiresOn"
                        min={tomorrow()}
                        value={date}
                        onChange={(event) => onDateChange(event.target.value)}
                    />
                </Field>
            )}
        </div>
    );
}
