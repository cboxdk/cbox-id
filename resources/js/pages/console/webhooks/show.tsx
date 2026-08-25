import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
} from '@/ui';

interface Delivery {
    id: string;
    eventType: string;
    attempt: number;
    responseCode: number | null;
    status: string;
    delivered: boolean;
    deliveredAt: string | null;
    nextRetryAt: string | null;
}

type Props = PageProps<{
    endpoint: {
        id: string;
        url: string;
        active: boolean;
        eventTypes: string[];
        /** Null means the ENVIRONMENT owns it and it receives every organization's events. */
        owner: string | null;
    };
    events: string[];
    /**
     * A tenant administrator SEES the environment's own endpoint because it receives
     * their events, but may not touch it — so the controls are not offered, rather than
     * offered and refused.
     */
    mayManage: boolean;
    deliveries: Delivery[];
    indexHref: string;
    urls: {
        update: string;
        pause: string;
        resume: string;
        rotate: string;
        destroy: string;
    };
    /** The plaintext secret, in this response and nowhere else, ever. */
    newSecret: string | null;
}>;

export default function WebhookDetail({
    endpoint,
    events,
    mayManage,
    deliveries,
    indexHref,
    urls,
    newSecret,
}: Props) {
    const [confirming, setConfirming] = useState<'rotate' | 'delete' | null>(null);
    const [secretVisible, setSecretVisible] = useState(newSecret !== null);

    const form = useForm({
        url: endpoint.url,
        eventTypes: endpoint.eventTypes,
    });

    const toggle = (event: string, checked: boolean): void => {
        form.setData(
            'eventTypes',
            checked
                ? [...form.data.eventTypes, event]
                : form.data.eventTypes.filter((type) => type !== event),
        );
    };

    return (
        <>
            <div className="space-y-6">
                <div>
                    <Link
                        href={indexHref}
                        className="text-sm inline-flex items-center gap-1"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        <Icon
                            name="chevron"
                            className="w-3.5 h-3.5"
                            style={{ transform: 'rotate(90deg)' }}
                        />
                        Webhooks
                    </Link>

                    <div className="mt-2 flex items-center gap-3 flex-wrap">
                        <h1
                            className="font-semibold tracking-tight mono truncate"
                            style={{ fontSize: '1.25rem' }}
                        >
                            {endpoint.url}
                        </h1>
                        <Pill tone={endpoint.active ? 'success' : 'warning'}>
                            {endpoint.active ? 'Active' : 'Paused'}
                        </Pill>
                        <Badge>{endpoint.owner ?? 'All organizations'}</Badge>
                    </div>

                    <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                        {endpoint.id}
                    </p>
                </div>

                {/*
                    THE ONE-TIME SIGNING SECRET, from a create hand-off or a rotation.
                    Never shown again — not to anybody, including us: only the sealed form
                    is stored.

                    Dismissable, and that matters more than it looks. This banner is a
                    live credential in plaintext on somebody's screen, and an
                    administrator who has copied it — or who is sharing that screen —
                    needs it gone now. Leaving it up until the next navigation is not an
                    answer.
                */}
                {newSecret !== null && secretVisible && (
                    <div
                        className="rounded-xl border p-5"
                        style={{
                            borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                            background: 'var(--warning-soft)',
                        }}
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0">
                                <p
                                    className="text-sm font-semibold"
                                    style={{ color: 'var(--warning-strong)' }}
                                >
                                    Copy this signing secret now — it won't be shown again.
                                </p>
                                <p className="mt-3 mono text-sm break-all select-all">
                                    {newSecret}
                                </p>
                                <p
                                    className="mt-3 text-xs"
                                    style={{ color: 'var(--warning-strong)' }}
                                >
                                    Every delivery carries an HMAC computed with this secret —
                                    verify it before you trust the payload.
                                </p>
                                <div className="mt-3">
                                    <CopyButton value={newSecret} />
                                </div>
                            </div>

                            <Button
                                size="sm"
                                className="shrink-0"
                                onClick={() => setSecretVisible(false)}
                            >
                                Dismiss
                            </Button>
                        </div>
                    </div>
                )}

                <Panel title="Subscription">
                    {mayManage ? (
                        <form
                            className="space-y-4"
                            onSubmit={(submitted) => {
                                submitted.preventDefault();
                                form.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field label="Endpoint URL" error={form.errors.url} required>
                                <Input
                                    type="url"
                                    className="mono"
                                    value={form.data.url}
                                    onChange={(event) => form.setData('url', event.target.value)}
                                />
                            </Field>

                            <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                                <legend className="label">Event types</legend>

                                <div
                                    style={{
                                        display: 'grid',
                                        gap: '8px',
                                        gridTemplateColumns:
                                            'repeat(auto-fill, minmax(17rem, 1fr))',
                                    }}
                                >
                                    {events.map((event) => (
                                        <Checkbox
                                            key={event}
                                            checked={form.data.eventTypes.includes(event)}
                                            onCheckedChange={(checked) => toggle(event, checked)}
                                            label={<span className="mono text-xs">{event}</span>}
                                        />
                                    ))}
                                </div>

                                <p
                                    className="field-error"
                                    role="alert"
                                    hidden={!form.errors.eventTypes}
                                >
                                    {form.errors.eventTypes}
                                </p>
                            </fieldset>

                            <Button type="submit" variant="primary" loading={form.processing}>
                                Save changes
                            </Button>
                        </form>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-1.5">
                                {endpoint.eventTypes.map((event) => (
                                    <Badge key={event}>
                                        <span className="mono">{event}</span>
                                    </Badge>
                                ))}
                            </div>
                            <p
                                className="mt-4 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                This endpoint belongs to the environment and receives every
                                organization's events, including yours. Your operator manages it.
                            </p>
                        </>
                    )}
                </Panel>

                {mayManage && (
                    <>
                        <Panel
                            title="Signing secret"
                            description="The secret signs every delivery's HMAC. It is stored sealed and can't be retrieved — rotating issues a new one, shown once."
                        >
                            <Button size="sm" icon="refresh" onClick={() => setConfirming('rotate')}>
                                Rotate secret
                            </Button>
                        </Panel>

                        <Panel title="Recent deliveries">
                            {deliveries.length === 0 ? (
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    No deliveries yet.
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {deliveries.map((delivery) => (
                                        <div
                                            key={delivery.id}
                                            className="flex items-center gap-3 rounded-lg border px-3 py-2"
                                            style={{ borderColor: 'var(--border)' }}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <span className="block text-sm font-medium truncate mono">
                                                    {delivery.eventType}
                                                </span>
                                                <p
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    Attempt {delivery.attempt}
                                                    {delivery.responseCode !== null &&
                                                        ` · HTTP ${delivery.responseCode}`}
                                                    {delivery.deliveredAt !== null
                                                        ? ` · delivered ${relative(delivery.deliveredAt)}`
                                                        : delivery.nextRetryAt !== null
                                                          ? ` · retry ${relative(delivery.nextRetryAt)}`
                                                          : ''}
                                                </p>
                                            </div>

                                            <Pill
                                                tone={delivery.delivered ? 'success' : 'warning'}
                                                className="shrink-0"
                                            >
                                                {delivery.delivered
                                                    ? 'Delivered'
                                                    : capitalise(delivery.status)}
                                            </Pill>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Panel>

                        <Panel title="Lifecycle">
                            <div className="flex flex-wrap gap-2">
                                {/*
                                    Pause and resume get a plain button, not a typed
                                    confirmation. Making a two-way switch cost a typed name
                                    trains people to type names without reading them, which
                                    is the failure the typed dialog exists to prevent.
                                */}
                                {endpoint.active ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(urls.pause, {}, { preserveScroll: true })
                                        }
                                    >
                                        Pause
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(urls.resume, {}, { preserveScroll: true })
                                        }
                                    >
                                        Resume
                                    </Button>
                                )}

                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => setConfirming('delete')}
                                >
                                    Delete endpoint
                                </Button>
                            </div>
                        </Panel>
                    </>
                )}
            </div>

            <ConfirmDelete
                open={confirming === 'rotate'}
                onOpenChange={(open) => setConfirming(open ? 'rotate' : null)}
                name={endpoint.url}
                verb="Rotate"
                consequence="The current signing secret stops verifying immediately and cannot be recovered — your receiver rejects every delivery until it is updated."
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate);
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => setConfirming(open ? 'delete' : null)}
                name={endpoint.url}
                consequence="This endpoint stops receiving events and its delivery history is dropped. This cannot be undone."
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </>
    );
}

/**
 * "4 minutes ago", computed in the browser.
 *
 * Deliberately not formatted on the server: this is a page people leave open while they
 * wait for a retry, and a relative time rendered once is wrong from the second frame on.
 */
function relative(iso: string): string {
    const seconds = Math.round((Date.parse(iso) - Date.now()) / 1000);
    const format = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 7],
    ];

    let value = seconds;

    for (const [unit, step] of units) {
        if (Math.abs(value) < step) {
            return format.format(Math.round(value), unit);
        }

        value /= step;
    }

    return format.format(Math.round(value), 'week');
}

function capitalise(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

WebhookDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
