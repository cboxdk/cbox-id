import { useTranslator } from '@/i18n';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { relativeTime } from '@/lib/time';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
    Input,
    Panel,
    Pill,
    RadioGroup,
} from '@/ui';
import { type SignatureScheme, signatureSchemeLabels, signatureSchemeOptions } from './schemes';

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
        signatureScheme: SignatureScheme;
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
        scheme: string;
        destroy: string;
    };
}>;

export default function WebhookDetail({
    endpoint,
    events,
    mayManage,
    deliveries,
    indexHref,
    urls,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    /*
     * THE PLAINTEXT SECRET, on the flash channel and nowhere else.
     *
     * Not a prop, and the difference is not cosmetic: props are written into the
     * browser's history entry, so a secret shown once would be retrievable by pressing
     * Back — a live credential at rest in the session store, long after the page that
     * revealed it has gone.
     */
    const newSecret = usePage().flash.newSecret;

    const [confirming, setConfirming] = useState<'rotate' | 'scheme' | 'delete' | null>(null);
    const [scheme, setScheme] = useState<SignatureScheme>(endpoint.signatureScheme);
    const [secretVisible, setSecretVisible] = useState(true);

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
                    <Breadcrumb href={indexHref} label={tc('console.webhooks_45808d75')} />

                    <div className="mt-2 flex items-center gap-3 flex-wrap">
                        <h1
                            className="font-semibold tracking-tight mono truncate"
                            style={{ fontSize: '1.25rem' }}
                        >
                            {endpoint.url}
                        </h1>
                        <Pill tone={endpoint.active ? 'success' : 'warning'}>
                            {endpoint.active
                                ? tc('console.active_92340695')
                                : tc('console.paused_e159b061')}
                        </Pill>
                        <Badge>{endpoint.owner ?? tc('console.all_organizations_e3002b23')}</Badge>
                        <Badge>{signatureSchemeLabels[endpoint.signatureScheme]}</Badge>
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
                {newSecret !== undefined && secretVisible && (
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
                                    {tc(
                                        'console.copy_this_signing_secret_now_it_won_t_be_sho_2331f9ee',
                                    )}
                                </p>
                                <p className="mt-3 mono text-sm break-all select-all">
                                    {newSecret}
                                </p>
                                <p
                                    className="mt-3 text-xs"
                                    style={{ color: 'var(--warning-strong)' }}
                                >
                                    {tc(
                                        'console.every_delivery_carries_an_hmac_computed_with_9e1a90b1',
                                    )}
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
                                {tc('console.dismiss_48845bff')}
                            </Button>
                        </div>
                    </div>
                )}

                <Panel title={tc('console.subscription_4999c6c6')}>
                    {mayManage ? (
                        <form
                            className="space-y-4"
                            onSubmit={(submitted) => {
                                submitted.preventDefault();
                                form.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field
                                label={tc('console.endpoint_url_2578179d')}
                                error={form.errors.url}
                                required
                            >
                                <Input
                                    type="url"
                                    className="mono"
                                    value={form.data.url}
                                    onChange={(event) => form.setData('url', event.target.value)}
                                />
                            </Field>

                            <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                                <legend className="label">
                                    {tc('console.event_types_8f338cc0')}
                                </legend>

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
                                {tc('console.save_changes_dd0ae7a5')}
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
                                {tc(
                                    'console.this_endpoint_belongs_to_the_environment_and_f1dc8df9',
                                )}
                            </p>
                        </>
                    )}
                </Panel>

                {mayManage && (
                    <>
                        <Panel
                            title={tc('console.signing_secret_e7ee22e1')}
                            description={tc(
                                'console.the_secret_signs_every_delivery_s_hmac_it_is_830c30dd',
                            )}
                        >
                            <Button
                                size="sm"
                                icon="refresh"
                                onClick={() => setConfirming('rotate')}
                            >
                                {tc('console.rotate_secret_4405518d')}
                            </Button>
                        </Panel>

                        <Panel
                            title={tc('console.signature_scheme_c432c67a')}
                            description={tc(
                                'console.which_headers_every_delivery_carries_changin_5a2ff32e',
                            )}
                        >
                            <div className="space-y-4">
                                <RadioGroup
                                    label={tc('console.sign_deliveries_with_bf5fa8db')}
                                    name="signatureScheme"
                                    value={scheme}
                                    onValueChange={setScheme}
                                    options={signatureSchemeOptions(tc)}
                                />
                                {scheme !== endpoint.signatureScheme && (
                                    <p
                                        className="text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {scheme === 'standard_webhooks'
                                            ? tc(
                                                  'console.your_receiver_keeps_the_same_secret_a_64_cha_c0d890ee',
                                              )
                                            : tc(
                                                  'console.your_receiver_keeps_the_same_secret_used_exa_431d83f5',
                                              )}{' '}
                                        {tc(
                                            'console.update_your_receiver_first_from_the_next_att_11a2b061',
                                        )}
                                    </p>
                                )}
                                <Button
                                    size="sm"
                                    disabled={scheme === endpoint.signatureScheme}
                                    onClick={() => setConfirming('scheme')}
                                >
                                    {tc('console.change_scheme_6816bb41')}
                                </Button>
                            </div>
                        </Panel>

                        <Panel title={tc('console.recent_deliveries_9d067ec8')}>
                            {deliveries.length === 0 ? (
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {tc('console.no_deliveries_yet_835fd2f4')}
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
                                                    {tc('console.attempt_51c78c77')}
                                                    {delivery.attempt}
                                                    {delivery.responseCode !== null &&
                                                        ` · HTTP ${delivery.responseCode}`}
                                                    {delivery.deliveredAt !== null
                                                        ? tc('console.delivered_value0_78c6d946', {
                                                              value0: relativeTime(
                                                                  delivery.deliveredAt,
                                                                  locale,
                                                              ),
                                                          })
                                                        : delivery.nextRetryAt !== null
                                                          ? tc('console.retry_value0_31685fff', {
                                                                value0: relativeTime(
                                                                    delivery.nextRetryAt,
                                                                    locale,
                                                                ),
                                                            })
                                                          : ''}
                                                </p>
                                            </div>

                                            <Pill
                                                tone={delivery.delivered ? 'success' : 'warning'}
                                                className="shrink-0"
                                            >
                                                {delivery.delivered
                                                    ? tc('console.delivered_90611565')
                                                    : capitalise(delivery.status)}
                                            </Pill>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Panel>

                        <Panel title={tc('console.lifecycle_46459b1f')}>
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
                                        {tc('console.pause_858e4ba7')}
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(urls.resume, {}, { preserveScroll: true })
                                        }
                                    >
                                        {tc('console.resume_d640c742')}
                                    </Button>
                                )}

                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => setConfirming('delete')}
                                >
                                    {tc('console.delete_endpoint_6772d678')}
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
                verb={tc('console.rotate_secret_4405518d')}
                title={tc('console.rotate_this_endpoint_s_signing_secret_18226fc7')}
                consequence={tc('console.the_current_signing_secret_stops_verifying_i_54edeb63')}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate);
                }}
            />

            <ConfirmDelete
                open={confirming === 'scheme'}
                onOpenChange={(open) => setConfirming(open ? 'scheme' : null)}
                name={endpoint.url}
                verb={tc('console.change_scheme_6816bb41')}
                title={tc('console.sign_this_endpoint_s_deliveries_with_value0_34d681d1', {
                    value0: signatureSchemeLabels[scheme],
                })}
                consequence={tc('console.no_new_secret_is_issued_from_the_next_attemp_eba13c7b')}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.scheme, { signatureScheme: scheme }, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => setConfirming(open ? 'delete' : null)}
                name={endpoint.url}
                consequence={tc('console.this_endpoint_stops_receiving_events_and_its_b6a65cf3')}
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </>
    );
}

function capitalise(value: string): string {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

WebhookDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
