import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Field, Icon, Input, Pill, Select } from '@/ui';
import { FormError, messageKey, type PortalChrome, PortalHeader, ValueRow } from './parts';

interface StreamRow {
    id: string;
    name: string;
    destination: string;
    endpointUrl: string;
    auth: string;
    enabled: boolean;
    lastSuccessAt: string | null;
    failing: boolean;
    testHref: string;
    removeHref: string;
}

type Props = PageProps<{
    portal: PortalChrome;
    streams: StreamRow[];
    destinations: { value: string; defaultAuth: string }[];
    urls: { create: string };
}>;

const AUTHS = ['none', 'bearer', 'splunk', 'hmac'] as const;

/**
 * LOG STREAMS — this organization's own audit log, sent to this organization's own SIEM.
 * Add a destination, send it a test entry and see whether it arrived, remove it. A signing
 * key the platform generated is shown once, on the flash channel.
 */
export default function PortalLogStreams({ portal, streams, destinations, urls }: Props) {
    const { t } = useTranslator();
    const { newSecret } = usePage().flash;

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.log_streams.title')}
                lead={t('portal.log_streams.lead')}
            />

            {typeof newSecret === 'string' && (
                <div
                    className="card p-4 mb-6"
                    style={{ borderColor: 'color-mix(in srgb, var(--warning) 40%, transparent)' }}
                >
                    <p className="flex items-center gap-2 font-semibold text-sm">
                        <Icon name="key" className="w-4 h-4" />{' '}
                        {t('portal.log_streams.secret_heading')}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--warning-strong)' }}>
                        {t('portal.log_streams.secret_once')}
                    </p>
                    <ValueRow field={t('portal.log_streams.secret_heading')} value={newSecret} />
                </div>
            )}

            <CreateStream destinations={destinations} href={urls.create} />
            <StreamList streams={streams} />
        </div>
    );
}

function CreateStream({
    destinations,
    href,
}: {
    destinations: Props['destinations'];
    href: string;
}) {
    const { t } = useTranslator();
    const first = destinations[0];
    const form = useForm({
        name: '',
        destination: first?.value ?? '',
        endpoint_url: '',
        auth: first?.defaultAuth ?? 'bearer',
        secret: '',
    });

    return (
        <section className="mb-8" aria-labelledby="add-destination">
            <h2 id="add-destination" className="text-sm font-semibold mb-3">
                {t('portal.log_streams.add_heading')}
            </h2>
            <form
                className="card p-4 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('portal.log_streams.name_label')} error={form.errors.name}>
                        <Input
                            name="name"
                            placeholder="Splunk"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('portal.log_streams.destination_label')}
                        error={form.errors.destination}
                    >
                        <Select
                            name="destination"
                            value={form.data.destination}
                            onValueChange={(destination) => {
                                const chosen = destinations.find(
                                    (candidate) => candidate.value === destination,
                                );
                                form.setData((data) => ({
                                    ...data,
                                    destination,
                                    auth: chosen?.defaultAuth ?? data.auth,
                                }));
                            }}
                            options={destinations.map((destination) => ({
                                value: destination.value,
                                label: t(
                                    messageKey(
                                        'portal.log_streams.destinations',
                                        destination.value,
                                    ),
                                ),
                            }))}
                        />
                    </Field>
                </div>
                <Field
                    label={t('portal.log_streams.endpoint_label')}
                    hint={t('portal.log_streams.endpoint_help')}
                    error={form.errors.endpoint_url}
                >
                    <Input
                        name="endpoint_url"
                        type="url"
                        className="mono"
                        placeholder="https://"
                        value={form.data.endpoint_url}
                        onChange={(event) => form.setData('endpoint_url', event.target.value)}
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('portal.log_streams.auth_label')} error={form.errors.auth}>
                        <Select
                            name="auth"
                            value={form.data.auth}
                            onValueChange={(auth) => form.setData('auth', auth)}
                            options={AUTHS.map((auth) => ({
                                value: auth,
                                label: t(`portal.log_streams.auths.${auth}`),
                            }))}
                        />
                    </Field>
                    {form.data.auth !== 'none' && (
                        <Field
                            label={t('portal.log_streams.secret_label')}
                            hint={
                                form.data.auth === 'hmac'
                                    ? t('portal.log_streams.secret_help')
                                    : undefined
                            }
                            error={form.errors.secret}
                        >
                            <Input
                                name="secret"
                                type="password"
                                autoComplete="off"
                                className="mono"
                                value={form.data.secret}
                                onChange={(event) => form.setData('secret', event.target.value)}
                            />
                        </Field>
                    )}
                </div>
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.log_streams.create')}
                </Button>
            </form>
        </section>
    );
}

function StreamList({ streams }: { streams: StreamRow[] }) {
    const { t, locale } = useTranslator();
    const { streamTest } = usePage().flash;
    const { errors } = usePage().props;
    const [removing, setRemoving] = useState<StreamRow | null>(null);
    const [testing, setTesting] = useState<string | null>(null);
    const removeVerb = t('portal.log_streams.remove');

    return (
        <section aria-labelledby="destinations">
            <h2 id="destinations" className="text-sm font-semibold mb-3">
                {t('portal.log_streams.streams_heading')}
            </h2>
            {streams.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.log_streams.empty')}
                </p>
            ) : (
                <ul className="space-y-3">
                    {streams.map((stream) => {
                        const result = streamTest?.id === stream.id ? streamTest : null;

                        return (
                            <li key={stream.id} className="card p-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="font-medium">{stream.name}</p>
                                        <p
                                            className="text-xs"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {t(
                                                messageKey(
                                                    'portal.log_streams.destinations',
                                                    stream.destination,
                                                ),
                                            )}
                                        </p>
                                        <p className="text-xs mono break-all mt-1">
                                            {stream.endpointUrl}
                                        </p>
                                        <p
                                            className="text-xs mt-1"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {stream.lastSuccessAt === null
                                                ? t('portal.log_streams.never_delivered')
                                                : t('portal.log_streams.last_delivery', {
                                                      when: new Date(
                                                          stream.lastSuccessAt,
                                                      ).toLocaleString(locale),
                                                  })}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {stream.failing ? (
                                            <Pill tone="destructive">
                                                {t('portal.log_streams.failing')}
                                            </Pill>
                                        ) : (
                                            <Pill tone={stream.enabled ? 'success' : 'warning'}>
                                                {stream.enabled
                                                    ? t('portal.log_streams.enabled')
                                                    : t('portal.log_streams.disabled')}
                                            </Pill>
                                        )}
                                        <Button
                                            size="sm"
                                            loading={testing === stream.id}
                                            aria-label={t('portal.log_streams.test_label', {
                                                name: stream.name,
                                            })}
                                            onClick={() =>
                                                router.post(
                                                    stream.testHref,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        onStart: () => setTesting(stream.id),
                                                        onFinish: () => setTesting(null),
                                                    },
                                                )
                                            }
                                        >
                                            {t('portal.log_streams.test')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="danger"
                                            aria-label={t('portal.log_streams.remove_label', {
                                                name: stream.name,
                                            })}
                                            onClick={() => setRemoving(stream)}
                                        >
                                            {removeVerb}
                                        </Button>
                                    </div>
                                </div>
                                {result !== null && (
                                    <output
                                        className="block text-sm mt-3"
                                        style={{
                                            color: result.delivered
                                                ? 'var(--success-strong, var(--success))'
                                                : 'var(--destructive)',
                                        }}
                                    >
                                        {result.delivered
                                            ? t('portal.log_streams.test_ok')
                                            : t('portal.log_streams.test_failed', {
                                                  error: result.error ?? '',
                                              })}
                                    </output>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
            <FormError message={typeof errors.stream === 'string' ? errors.stream : undefined} />

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.name ?? ''}
                verb={removeVerb}
                title={t('portal.log_streams.remove_title', { name: removing?.name ?? '' })}
                consequence={t('portal.log_streams.remove_consequence')}
                environment={null}
                cancelLabel={t('portal.confirm.cancel')}
                typeToConfirmLabel={t('portal.confirm.type_to_confirm', {
                    name: removing?.name ?? '',
                })}
                hint={t('portal.confirm.hint', { action: removeVerb })}
                onConfirm={() => {
                    const stream = removing;
                    setRemoving(null);

                    if (stream !== null) {
                        router.delete(stream.removeHref, { preserveScroll: true });
                    }
                }}
            />
        </section>
    );
}

PortalLogStreams.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
