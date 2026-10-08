import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    Pill,
    RadioGroup,
    Select,
    Textarea,
} from '@/ui';
import { FormError, messageKey, type PortalChrome, PortalHeader, ValueRow } from './parts';

type Health = 'healthy' | 'degraded' | 'paused' | 'action_required';

interface StreamRow {
    id: string;
    name: string;
    destination: string;
    endpointUrl: string;
    auth: string;
    enabled: boolean;
    health: Health;
    lastError: string | null;
    lastSuccessAt: string | null;
    /** An S3 bucket's AWS steps; the external ID and trust policy only for an assumed role. */
    aws: {
        externalId: string | null;
        trustPolicy: string | null;
        permissionsPolicy: string | null;
    } | null;
    testHref: string;
    removeHref: string;
}

type Props = PageProps<{
    portal: PortalChrome;
    streams: StreamRow[];
    destinations: { value: string; defaultAuth: string }[];
    datadogSites: { value: string; label: string }[];
    assumedRoleAvailable: boolean;
    urls: { create: string };
}>;

const AUTHS = ['none', 'bearer', 'splunk', 'hmac'] as const;
const CLOUD = ['datadog', 's3', 'gcs'];

interface StreamOptions {
    site: string;
    service: string;
    source: string;
    tags: string;
    hostname: string;
    bucket: string;
    region: string;
    prefix: string;
    access_key_id: string;
    role_arn: string;
    sse: string;
    kms_key_id: string;
    gzip: boolean;
}

interface StreamForm {
    name: string;
    destination: string;
    endpoint_url: string;
    auth: string;
    secret: string;
    credential: 'access_key' | 'role';
    options: StreamOptions;
}

const EMPTY_OPTIONS: StreamOptions = {
    site: 'datadoghq.com',
    service: '',
    source: '',
    tags: '',
    hostname: '',
    bucket: '',
    region: '',
    prefix: '',
    access_key_id: '',
    role_arn: '',
    sse: '',
    kms_key_id: '',
    gzip: true,
};

/**
 * LOG STREAMS — this organization's own audit log, sent to this organization's own SIEM or
 * bucket. Add a destination, send it a test entry and see whether it arrived, remove it. A
 * signing key the platform generated is shown once, on the flash channel; a credential the
 * IT admin typed is never shown back. An S3 bucket reached through an assumed role shows
 * the external ID and the policies to paste into AWS, opened as soon as it is added.
 */
export default function PortalLogStreams({
    portal,
    streams,
    destinations,
    datadogSites,
    assumedRoleAvailable,
    urls,
}: Props) {
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

            <CreateStream
                destinations={destinations}
                sites={datadogSites}
                assumedRoleAvailable={assumedRoleAvailable}
                href={urls.create}
            />
            <StreamList streams={streams} />
        </div>
    );
}

function CreateStream({
    destinations,
    sites,
    assumedRoleAvailable,
    href,
}: {
    destinations: Props['destinations'];
    sites: Props['datadogSites'];
    assumedRoleAvailable: boolean;
    href: string;
}) {
    const { t } = useTranslator();
    const first = destinations[0];
    const form = useForm<StreamForm>({
        name: '',
        destination: first?.value ?? '',
        endpoint_url: '',
        auth: first?.defaultAuth ?? 'bearer',
        secret: '',
        credential: 'access_key',
        options: EMPTY_OPTIONS,
    });
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const cloud = CLOUD.includes(data.destination);
    const role = data.destination === 's3' && data.credential === 'role';
    const option = <K extends keyof StreamOptions>(key: K, value: StreamOptions[K]) =>
        form.setData((current) => ({ ...current, options: { ...current.options, [key]: value } }));
    const text = (key: Exclude<keyof StreamOptions, 'gzip'>) => ({
        name: `options.${key}`,
        value: data.options[key],
        onChange: (event: React.ChangeEvent<HTMLInputElement>) => option(key, event.target.value),
    });

    const secretLabel =
        data.destination === 'datadog'
            ? t('portal.log_streams.datadog.api_key_label')
            : data.destination === 's3'
              ? t('portal.log_streams.storage.secret_access_key_label')
              : data.destination === 'gcs'
                ? t('portal.log_streams.storage.service_account_label')
                : t('portal.log_streams.secret_label');
    const secretHint =
        data.destination === 'datadog'
            ? t('portal.log_streams.datadog.api_key_help')
            : data.destination === 'gcs'
              ? t('portal.log_streams.storage.service_account_help')
              : !cloud && data.auth === 'hmac'
                ? t('portal.log_streams.secret_help')
                : undefined;

    return (
        <section className="mb-8" aria-labelledby="add-destination">
            <h2 id="add-destination" className="text-sm font-semibold mb-3">
                {t('portal.log_streams.add_heading')}
            </h2>
            <form
                className="card p-4 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    // Only the S3 credential in use is sent: a role holds no key.
                    form.transform((current) =>
                        current.destination === 's3'
                            ? {
                                  ...current,
                                  secret: current.credential === 'role' ? '' : current.secret,
                                  options: {
                                      ...current.options,
                                      access_key_id:
                                          current.credential === 'role'
                                              ? ''
                                              : current.options.access_key_id,
                                      role_arn:
                                          current.credential === 'role'
                                              ? current.options.role_arn
                                              : '',
                                  },
                              }
                            : current,
                    );
                    form.post(href, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                        onFinish: () => form.setData('secret', ''),
                    });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label={t('portal.log_streams.name_label')} error={errors.name}>
                        <Input
                            name="name"
                            placeholder="Splunk"
                            value={data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('portal.log_streams.destination_label')}
                        error={errors.destination}
                    >
                        <Select
                            name="destination"
                            value={data.destination}
                            onValueChange={(destination) => {
                                const chosen = destinations.find(
                                    (candidate) => candidate.value === destination,
                                );
                                form.setData((current) => ({
                                    ...current,
                                    destination,
                                    auth: chosen?.defaultAuth ?? current.auth,
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

                {!cloud && (
                    <Field
                        label={t('portal.log_streams.endpoint_label')}
                        hint={t('portal.log_streams.endpoint_help')}
                        error={errors.endpoint_url}
                    >
                        <Input
                            name="endpoint_url"
                            type="url"
                            className="mono"
                            placeholder="https://"
                            value={data.endpoint_url}
                            onChange={(event) => form.setData('endpoint_url', event.target.value)}
                        />
                    </Field>
                )}

                {data.destination === 'datadog' && (
                    <>
                        <Field
                            label={t('portal.log_streams.datadog.site_label')}
                            hint={t('portal.log_streams.datadog.site_help')}
                            error={errors['options.site']}
                        >
                            <Select
                                name="options.site"
                                value={data.options.site}
                                onValueChange={(site) => option('site', site)}
                                options={sites}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('portal.log_streams.datadog.service_label')}
                                hint={t('portal.log_streams.datadog.service_help')}
                                error={errors['options.service']}
                            >
                                <Input {...text('service')} className="mono" />
                            </Field>
                            <Field
                                label={t('portal.log_streams.datadog.source_label')}
                                hint={t('portal.log_streams.datadog.source_help')}
                                error={errors['options.source']}
                            >
                                <Input {...text('source')} className="mono" />
                            </Field>
                            <Field
                                label={t('portal.log_streams.datadog.tags_label')}
                                hint={t('portal.log_streams.datadog.tags_help')}
                                error={errors['options.tags']}
                            >
                                <Input {...text('tags')} className="mono" placeholder="env:prod" />
                            </Field>
                            <Field
                                label={t('portal.log_streams.datadog.hostname_label')}
                                hint={t('portal.log_streams.datadog.hostname_help')}
                                error={errors['options.hostname']}
                            >
                                <Input {...text('hostname')} className="mono" />
                            </Field>
                        </div>
                    </>
                )}

                {(data.destination === 's3' || data.destination === 'gcs') && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('portal.log_streams.storage.bucket_label')}
                                error={errors['options.bucket']}
                            >
                                <Input {...text('bucket')} className="mono" />
                            </Field>
                            {data.destination === 's3' && (
                                <Field
                                    label={t('portal.log_streams.storage.region_label')}
                                    hint={t('portal.log_streams.storage.region_help')}
                                    error={errors['options.region']}
                                >
                                    <Input
                                        {...text('region')}
                                        className="mono"
                                        placeholder="eu-west-1"
                                    />
                                </Field>
                            )}
                        </div>
                        <Field
                            label={t('portal.log_streams.storage.prefix_label')}
                            hint={t('portal.log_streams.storage.prefix_help')}
                            error={errors['options.prefix']}
                        >
                            <Input {...text('prefix')} className="mono" />
                        </Field>
                        <Checkbox
                            label={t('portal.log_streams.storage.gzip')}
                            checked={data.options.gzip}
                            onCheckedChange={(gzip) => option('gzip', gzip)}
                        />
                    </>
                )}

                {data.destination === 's3' && (
                    <>
                        <RadioGroup
                            label={t('portal.log_streams.storage.credential_label')}
                            value={data.credential}
                            onValueChange={(credential) => form.setData('credential', credential)}
                            options={[
                                {
                                    value: 'access_key',
                                    label: t('portal.log_streams.storage.credential_access_key'),
                                    hint: t(
                                        'portal.log_streams.storage.credential_access_key_help',
                                    ),
                                },
                                {
                                    value: 'role',
                                    label: t('portal.log_streams.storage.credential_role'),
                                    hint: assumedRoleAvailable
                                        ? t('portal.log_streams.storage.credential_role_help')
                                        : t(
                                              'portal.log_streams.storage.credential_role_unavailable',
                                          ),
                                    disabled: !assumedRoleAvailable,
                                },
                            ]}
                        />
                        {role ? (
                            <Field
                                label={t('portal.log_streams.storage.role_arn_label')}
                                error={errors['options.role_arn']}
                            >
                                <Input
                                    {...text('role_arn')}
                                    className="mono"
                                    placeholder="arn:aws:iam::123456789012:role/…"
                                />
                            </Field>
                        ) : (
                            <Field
                                label={t('portal.log_streams.storage.access_key_id_label')}
                                error={errors['options.access_key_id']}
                            >
                                <Input
                                    {...text('access_key_id')}
                                    className="mono"
                                    autoComplete="off"
                                />
                            </Field>
                        )}
                    </>
                )}

                {!cloud && (
                    <Field label={t('portal.log_streams.auth_label')} error={errors.auth}>
                        <Select
                            name="auth"
                            value={data.auth}
                            onValueChange={(auth) => form.setData('auth', auth)}
                            options={AUTHS.map((auth) => ({
                                value: auth,
                                label: t(`portal.log_streams.auths.${auth}`),
                            }))}
                        />
                    </Field>
                )}

                {(cloud ? !role : data.auth !== 'none') && (
                    <Field label={secretLabel} hint={secretHint} error={errors.secret}>
                        {data.destination === 'gcs' ? (
                            <Textarea
                                name="secret"
                                className="mono"
                                rows={5}
                                autoComplete="off"
                                spellCheck={false}
                                value={data.secret}
                                onChange={(event) => form.setData('secret', event.target.value)}
                            />
                        ) : (
                            <Input
                                name="secret"
                                type="password"
                                autoComplete="off"
                                className="mono"
                                value={data.secret}
                                onChange={(event) => form.setData('secret', event.target.value)}
                            />
                        )}
                    </Field>
                )}

                {data.destination === 's3' && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('portal.log_streams.storage.sse_label')}
                            error={errors['options.sse']}
                        >
                            <Select
                                name="options.sse"
                                value={data.options.sse === '' ? 'default' : data.options.sse}
                                onValueChange={(sse) => option('sse', sse === 'default' ? '' : sse)}
                                options={[
                                    {
                                        value: 'default',
                                        label: t('portal.log_streams.storage.sse_default'),
                                    },
                                    { value: 'AES256', label: 'SSE-S3 (AES256)' },
                                    { value: 'aws:kms', label: 'SSE-KMS (aws:kms)' },
                                ]}
                            />
                        </Field>
                        {data.options.sse === 'aws:kms' && (
                            <Field
                                label={t('portal.log_streams.storage.kms_key_label')}
                                hint={t('portal.log_streams.storage.kms_key_help')}
                                error={errors['options.kms_key_id']}
                            >
                                <Input {...text('kms_key_id')} className="mono" />
                            </Field>
                        )}
                        <Field
                            label={t('portal.log_streams.storage.endpoint_label')}
                            hint={t('portal.log_streams.storage.endpoint_help')}
                            error={errors.endpoint_url}
                        >
                            <Input
                                name="endpoint_url"
                                type="url"
                                className="mono"
                                placeholder="https://"
                                value={data.endpoint_url}
                                onChange={(event) =>
                                    form.setData('endpoint_url', event.target.value)
                                }
                            />
                        </Field>
                    </div>
                )}

                <FormError message={errors.options} />

                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.log_streams.create')}
                </Button>
            </form>
        </section>
    );
}

const HEALTH_TONE: Record<Health, 'success' | 'warning' | 'destructive'> = {
    healthy: 'success',
    degraded: 'warning',
    paused: 'warning',
    action_required: 'destructive',
};

function StreamList({ streams }: { streams: StreamRow[] }) {
    const { t, locale } = useTranslator();
    const { streamTest, awsSetup } = usePage().flash;
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
                                        {stream.enabled && stream.health !== 'healthy' ? (
                                            <Pill tone={HEALTH_TONE[stream.health]}>
                                                {t(
                                                    messageKey(
                                                        'portal.log_streams.health',
                                                        stream.health,
                                                    ),
                                                )}
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
                                {stream.lastError !== null && stream.health !== 'healthy' && (
                                    <p
                                        className="text-xs mt-2 mono break-all"
                                        style={{ color: 'var(--destructive)' }}
                                    >
                                        {t('portal.log_streams.last_error', {
                                            error: stream.lastError,
                                        })}
                                    </p>
                                )}
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
                                {stream.aws !== null && (
                                    <AwsSteps aws={stream.aws} open={awsSetup === stream.id} />
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

/**
 * What to paste into AWS for an S3 destination: for an assumed role, the external ID and
 * the trust policy that requires it (nothing is written until it is in place), and for
 * either credential the write-only permissions policy. Opened right after it is added.
 */
function AwsSteps({ aws, open }: { aws: NonNullable<StreamRow['aws']>; open: boolean }) {
    const { t } = useTranslator();

    return (
        <details className="mt-3" open={open}>
            <summary className="text-sm font-medium cursor-pointer">
                {t('portal.log_streams.aws.show')}
            </summary>
            <div className="mt-2 space-y-3">
                {aws.externalId !== null && aws.trustPolicy !== null && (
                    <>
                        <p className="text-sm">{t('portal.log_streams.aws.trust_lead')}</p>
                        <ValueRow
                            field={t('portal.log_streams.aws.external_id')}
                            value={aws.externalId}
                        />
                        <Policy
                            label={t('portal.log_streams.aws.trust_policy')}
                            policy={aws.trustPolicy}
                        />
                    </>
                )}
                {aws.permissionsPolicy !== null && (
                    <>
                        <p className="text-sm">{t('portal.log_streams.aws.permissions_lead')}</p>
                        <Policy
                            label={t('portal.log_streams.aws.permissions_policy')}
                            policy={aws.permissionsPolicy}
                        />
                    </>
                )}
            </div>
        </details>
    );
}

/** A JSON policy as it is pasted: preformatted, scrollable, with its own copy button. */
function Policy({ label, policy }: { label: string; policy: string }) {
    const { t } = useTranslator();

    return (
        <div>
            <p className="text-xs font-medium" style={{ color: 'var(--muted-foreground)' }}>
                {label}
            </p>
            <div className="mt-1 flex items-start gap-2">
                <pre
                    // Scrollable sideways, so reachable by keyboard (WCAG 2.1.1).
                    // oxlint-disable-next-line jsx-a11y/no-noninteractive-tabindex
                    tabIndex={0}
                    aria-label={label}
                    className="mono text-xs rounded-lg px-3 py-2 overflow-x-auto flex-1 min-w-0 m-0"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    {policy}
                </pre>
                <CopyButton
                    value={policy}
                    aria-label={`${t('portal.copy.copy')}: ${label}`}
                    label={t('portal.copy.copy')}
                    copiedLabel={t('portal.copy.copied')}
                    failedLabel={t('portal.copy.failed')}
                />
            </div>
        </div>
    );
}

PortalLogStreams.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
