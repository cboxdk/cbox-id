import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
import {
    type IdFormat,
    idFormatOptions,
    type SignatureScheme,
    signatureSchemeLabels,
    signatureSchemeOptions,
} from './schemes';

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
        idFormat: IdFormat;
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
        idFormat: string;
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
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    /*
     * THE PLAINTEXT SECRET, on the flash channel and nowhere else.
     *
     * Not a prop, and the difference is not cosmetic: props are written into the
     * browser's history entry, so a secret shown once would be retrievable by pressing
     * Back — a live credential at rest in the session store, long after the page that
     * revealed it has gone.
     */
    const newSecret = usePage().flash.newSecret;

    const [confirming, setConfirming] = useState<
        'rotate' | 'scheme' | 'idFormat' | 'delete' | null
    >(null);
    const [scheme, setScheme] = useState<SignatureScheme>(endpoint.signatureScheme);
    const [idFormat, setIdFormat] = useState<IdFormat>(endpoint.idFormat);
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
                    <Breadcrumb href={indexHref} label={consoleT('console.webhooks_45808d75')} />

                    <div className="mt-2 flex items-center gap-3 flex-wrap">
                        <h1
                            className="font-semibold tracking-tight mono truncate"
                            style={{ fontSize: '1.25rem' }}
                        >
                            {endpoint.url}
                        </h1>
                        <Pill tone={endpoint.active ? 'success' : 'warning'}>
                            {endpoint.active
                                ? consoleText('console.active_92340695')
                                : consoleText('console.paused_e159b061')}
                        </Pill>
                        <Badge>
                            {endpoint.owner ??
                                consoleRuntimeText('console.all_organizations_e3002b23')}
                        </Badge>
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
                                    {consoleT(
                                        'console.copy_this_signing_secret_now_it_won_t_be_shown_again_2331f9ee',
                                    )}
                                </p>
                                <p className="mt-3 mono text-sm break-all select-all">
                                    {newSecret}
                                </p>
                                <p
                                    className="mt-3 text-xs"
                                    style={{ color: 'var(--warning-strong)' }}
                                >
                                    {consoleT(
                                        'console.every_delivery_carries_an_hmac_computed_with_this_secre_9e1a90b1',
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
                                {consoleT('console.dismiss_48845bff')}
                            </Button>
                        </div>
                    </div>
                )}

                <Panel title={consoleT('console.subscription_4999c6c6')}>
                    {mayManage ? (
                        <form
                            className="space-y-4"
                            onSubmit={(submitted) => {
                                submitted.preventDefault();
                                form.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field
                                label={consoleT('console.endpoint_url_2578179d')}
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
                                    {consoleT('console.event_types_8f338cc0')}
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
                                {consoleT('console.save_changes_dd0ae7a5')}
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
                                {consoleT(
                                    'console.this_endpoint_belongs_to_the_environment_and_receives_e_f1dc8df9',
                                )}
                            </p>
                        </>
                    )}
                </Panel>

                {mayManage && (
                    <>
                        <Panel
                            title={consoleT('console.signing_secret_e7ee22e1')}
                            description={consoleT(
                                'console.the_secret_signs_every_delivery_s_hmac_it_is_stored_sea_830c30dd',
                            )}
                        >
                            <Button
                                size="sm"
                                icon="refresh"
                                onClick={() => setConfirming('rotate')}
                            >
                                {consoleT('console.rotate_secret_4405518d')}
                            </Button>
                        </Panel>

                        <Panel
                            title={consoleT('console.signature_scheme_c432c67a')}
                            description={consoleT(
                                'console.which_headers_every_delivery_carries_changing_it_issues_5a2ff32e',
                            )}
                        >
                            <div className="space-y-4">
                                <RadioGroup
                                    label={consoleT('console.sign_deliveries_with_bf5fa8db')}
                                    name="signatureScheme"
                                    value={scheme}
                                    onValueChange={setScheme}
                                    options={signatureSchemeOptions(consoleOptionText)}
                                />
                                {scheme !== endpoint.signatureScheme && (
                                    <p
                                        className="text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {consoleRich(
                                            'console._part1_part2_update_your_receiver_first_from_the_next_a_d1d60a6e',
                                            {
                                                part1:
                                                    scheme === 'standard_webhooks'
                                                        ? consoleText(
                                                              'console.your_receiver_keeps_the_same_secret_a_64_character_hex_c0d890ee',
                                                          )
                                                        : consoleText(
                                                              'console.your_receiver_keeps_the_same_secret_used_exactly_as_wri_431d83f5',
                                                          ),
                                                part2: ' ',
                                            },
                                        )}
                                    </p>
                                )}
                                <Button
                                    size="sm"
                                    disabled={scheme === endpoint.signatureScheme}
                                    onClick={() => setConfirming('scheme')}
                                >
                                    {consoleT('console.change_scheme_6816bb41')}
                                </Button>
                            </div>
                        </Panel>

                        <Panel
                            title={consoleT('console.ids_in_deliveries_fbfc89f6')}
                            description={consoleT(
                                'console.which_form_of_id_every_delivery_carries_move_7eecdf37',
                            )}
                        >
                            <div className="space-y-4">
                                <RadioGroup
                                    label={consoleT('console.ids_in_deliveries_fbfc89f6')}
                                    name="idFormat"
                                    value={idFormat}
                                    onValueChange={setIdFormat}
                                    options={idFormatOptions(consoleOptionText)}
                                />
                                <Button
                                    size="sm"
                                    disabled={idFormat === endpoint.idFormat}
                                    onClick={() => setConfirming('idFormat')}
                                >
                                    {consoleT('console.change_id_format_7b79ed9d')}
                                </Button>
                            </div>
                        </Panel>

                        <Panel title={consoleT('console.recent_deliveries_9d067ec8')}>
                            {deliveries.length === 0 ? (
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {consoleT('console.no_deliveries_yet_835fd2f4')}
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
                                                    {consoleRich(
                                                        'console.attempt_part1_part2_part3_a162fa99',
                                                        {
                                                            part1: delivery.attempt,
                                                            part2:
                                                                delivery.responseCode !== null &&
                                                                ` · HTTP ${delivery.responseCode}`,
                                                            part3:
                                                                delivery.deliveredAt !== null
                                                                    ? consoleT(
                                                                          'console.webhook_delivered_at',
                                                                          {
                                                                              time: relativeTime(
                                                                                  delivery.deliveredAt,
                                                                                  consoleLocale,
                                                                              ),
                                                                          },
                                                                      )
                                                                    : delivery.nextRetryAt !== null
                                                                      ? consoleT(
                                                                            'console.webhook_retry_at',
                                                                            {
                                                                                time: relativeTime(
                                                                                    delivery.nextRetryAt,
                                                                                    consoleLocale,
                                                                                ),
                                                                            },
                                                                        )
                                                                      : '',
                                                        },
                                                    )}
                                                </p>
                                            </div>

                                            <Pill
                                                tone={delivery.delivered ? 'success' : 'warning'}
                                                className="shrink-0"
                                            >
                                                {delivery.delivered
                                                    ? consoleText('console.delivered_90611565')
                                                    : capitalise(delivery.status)}
                                            </Pill>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Panel>

                        <Panel title={consoleT('console.lifecycle_46459b1f')}>
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
                                        {consoleT('console.pause_858e4ba7')}
                                    </Button>
                                ) : (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(urls.resume, {}, { preserveScroll: true })
                                        }
                                    >
                                        {consoleT('console.resume_d640c742')}
                                    </Button>
                                )}

                                <Button
                                    size="sm"
                                    variant="danger"
                                    onClick={() => setConfirming('delete')}
                                >
                                    {consoleT('console.delete_endpoint_6772d678')}
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
                verb={consoleAttributeText('console.rotate_secret_4405518d')}
                title={consoleT('console.rotate_this_endpoint_s_signing_secret_18226fc7')}
                consequence={consoleUiText(
                    'console.the_current_signing_secret_stops_verifying_immediately_54edeb63',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate);
                }}
            />

            <ConfirmDelete
                open={confirming === 'scheme'}
                onOpenChange={(open) => setConfirming(open ? 'scheme' : null)}
                name={endpoint.url}
                verb={consoleAttributeText('console.change_scheme_6816bb41')}
                title={consoleFinalText(
                    'console.sign_this_endpoint_s_deliveries_with_part1_bf8f1f3e',
                    { part1: signatureSchemeLabels[scheme] },
                )}
                consequence={consoleUiText(
                    'console.no_new_secret_is_issued_from_the_next_attempt_retries_i_eba13c7b',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.scheme, { signatureScheme: scheme }, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'idFormat'}
                onOpenChange={(open) => setConfirming(open ? 'idFormat' : null)}
                name={endpoint.url}
                verb={consoleAttributeText('console.change_id_format_7b79ed9d')}
                title={consoleFinalText(
                    'console.change_the_ids_this_endpoint_s_deliveries_ca_50dc93e2',
                )}
                consequence={consoleUiText(
                    'console.from_the_next_delivery_every_id_in_the_paylo_b8c391de',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.idFormat, { idFormat }, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => setConfirming(open ? 'delete' : null)}
                name={endpoint.url}
                consequence={consoleUiText(
                    'console.this_endpoint_stops_receiving_events_and_its_delivery_h_b6a65cf3',
                )}
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
