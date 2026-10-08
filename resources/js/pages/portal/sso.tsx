import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { type MessageKey, useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, Pill, Textarea } from '@/ui';
import {
    ChosenProvider,
    DomainManager,
    type DomainRow,
    FormError,
    type GuideField,
    GuideSteps,
    GuideValues,
    type PortalChrome,
    PortalHeader,
    ProviderPicker,
    Step,
    ValueRow,
} from './parts';

interface Guide {
    key: string;
    name: string;
    protocol: 'saml' | 'oidc';
    fields: GuideField[];
    returns: { kind: 'url' | 'xml' | 'url_or_xml' | 'oidc'; theirs: string };
    docs: string | null;
    steps: string[];
}

interface ConnectionRow {
    id: string;
    name: string;
    protocol: 'saml' | 'oidc';
    status: string;
    active: boolean;
    complete: boolean;
    values: Record<string, string | undefined>;
    idp: Record<string, string | undefined>;
    urls: { update: string; metadata: string; activate: string };
}

type Props = PageProps<{
    portal: PortalChrome;
    guides: Guide[];
    provider: string | null;
    connections: ConnectionRow[];
    domains: DomainRow[];
    urls: { self: string; start: string; addDomain: string };
}>;

const STATUSES: Partial<Record<string, MessageKey>> = {
    draft: 'portal.sso.statuses.draft',
    inactive: 'portal.sso.statuses.inactive',
};

/**
 * SINGLE SIGN-ON — five steps, in the order an identity provider demands them: pick it,
 * start the connection so OUR values exist, paste them into THEIR screen field by field,
 * bring back what their screen gives (a metadata URL or file, or OIDC credentials), prove a
 * domain, turn it on.
 *
 * The connection being set up is the newest one not yet on, of the chosen provider's
 * protocol; one already on is listed below and left alone.
 */
export default function PortalSso({ portal, guides, provider, connections, domains, urls }: Props) {
    const { t } = useTranslator();
    const guide = guides.find((candidate) => candidate.key === provider) ?? null;
    const current =
        guide === null
            ? null
            : (connections.find(
                  (connection) => !connection.active && connection.protocol === guide.protocol,
              ) ?? null);
    const verified = domains.filter((domain) => domain.verified).length;

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.sso.title')}
                lead={t('portal.sso.lead')}
            />

            <Step number={1} title={t('portal.sso.step_provider')} done={guide !== null}>
                {guide === null ? (
                    <>
                        <ProviderPicker
                            providers={guides}
                            href={urls.self}
                            label={t('portal.sso.step_provider')}
                        />
                        <p className="text-xs mt-3" style={{ color: 'var(--muted-foreground)' }}>
                            {t('portal.sso.provider_hint')}
                        </p>
                    </>
                ) : (
                    <ChosenProvider
                        providerKey={guide.key}
                        name={guide.name}
                        href={urls.self}
                        changeLabel={t('portal.sso.change_provider')}
                    />
                )}
            </Step>

            {guide !== null && (
                <>
                    <Step
                        number={2}
                        title={t('portal.sso.step_configure', { provider: guide.name })}
                        done={current !== null && current.complete}
                    >
                        {current === null ? (
                            <StartForm guide={guide} href={urls.start} />
                        ) : (
                            <>
                                <GuideValues
                                    fields={guide.fields}
                                    values={current.values}
                                    lead={t('portal.sso.values_lead', { provider: guide.name })}
                                />
                                <MetadataUrl guide={guide} connection={current} />
                                <GuideSteps
                                    steps={guide.steps}
                                    docs={guide.docs}
                                    provider={guide.name}
                                />
                            </>
                        )}
                    </Step>

                    {current !== null && (
                        <>
                            <Step
                                number={3}
                                title={t('portal.sso.step_idp', { provider: guide.name })}
                                done={current.complete}
                            >
                                <IdpDetails guide={guide} connection={current} />
                            </Step>

                            <Step
                                number={4}
                                title={t('portal.sso.step_domain')}
                                done={verified > 0}
                            >
                                <p
                                    className="text-sm mb-3"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {t('portal.sso.domain_lead')}
                                </p>
                                <DomainManager domains={domains} addHref={urls.addDomain} />
                            </Step>

                            <Step
                                number={5}
                                title={t('portal.sso.step_activate')}
                                done={current.active}
                            >
                                <ActivateStep connection={current} />
                            </Step>
                        </>
                    )}
                </>
            )}

            {connections.length > 0 && (
                <ConnectionList connections={connections} href={urls.self} />
            )}
        </div>
    );
}

/** Step 2, before there is a connection: name it and start, which mints our values. */
function StartForm({ guide, href }: { guide: Guide; href: string }) {
    const form = useForm({ provider: guide.key, name: guide.name });
    const { t } = useTranslator();

    return (
        <form
            className="card p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true });
            }}
        >
            <p className="text-sm mb-3">{t('portal.sso.start_lead', { provider: guide.name })}</p>
            <Field label={t('portal.sso.name_label')} error={form.errors.name}>
                <Input
                    name="name"
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </Field>
            <div className="mt-3">
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.sso.start', { provider: guide.name })}
                </Button>
            </div>
        </form>
    );
}

/**
 * Our SAML metadata URL — everything above as one document, for an identity provider that
 * imports service-provider metadata. Under the provider's own name for the field when the
 * guide knows it (it is then one of the values above); under ours, with a word on when to
 * use it, for every other SAML provider.
 */
function MetadataUrl({ guide, connection }: { guide: Guide; connection: ConnectionRow }) {
    const { t } = useTranslator();
    const url = connection.values.metadata_url;

    if (url === undefined || guide.fields.some((field) => field.ours === 'metadata_url')) {
        return null;
    }

    return (
        <div className="card p-4 mb-4">
            <ValueRow field={t('portal.sso.sp_metadata_url')} value={url} />
            <p className="text-xs mt-1" style={{ color: 'var(--muted-foreground)' }}>
                {t('portal.sso.sp_metadata_url_hint', { provider: guide.name })}
            </p>
        </div>
    );
}

/** Step 3: what the identity provider gives back — metadata, or OIDC's three values. */
function IdpDetails({ guide, connection }: { guide: Guide; connection: ConnectionRow }) {
    const { t } = useTranslator();
    const [manual, setManual] = useState(false);
    const received = Object.entries(connection.idp).filter(
        (entry): entry is [string, string] => entry[1] !== undefined,
    );

    return (
        <div>
            {connection.complete && received.length > 0 && (
                <div className="card p-4 mb-4">
                    <p className="text-xs font-semibold mb-2 flex items-center gap-1.5">
                        <Icon
                            name="check"
                            className="w-3.5 h-3.5"
                            style={{ color: 'var(--success)' }}
                        />
                        {t('portal.sso.received')}
                    </p>
                    <dl className="text-xs space-y-1">
                        {received.map(([key, value]) => (
                            <div key={key} className="flex flex-wrap gap-x-2">
                                <dt style={{ color: 'var(--muted-foreground)' }}>{key}</dt>
                                <dd className="mono break-all">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}

            {guide.protocol === 'oidc' ? (
                <>
                    <p className="text-sm mb-3">
                        {t('portal.sso.returns.oidc', { provider: guide.name })}
                    </p>
                    <ManualForm connection={connection} />
                </>
            ) : manual ? (
                <>
                    <ManualForm connection={connection} />
                    <button
                        type="button"
                        className="text-xs mt-2"
                        style={{ color: 'var(--accent)' }}
                        onClick={() => setManual(false)}
                    >
                        {t('portal.sso.metadata_label')}
                    </button>
                </>
            ) : (
                <>
                    <MetadataForm guide={guide} connection={connection} />
                    <button
                        type="button"
                        className="text-xs mt-2"
                        style={{ color: 'var(--accent)' }}
                        aria-expanded={manual}
                        onClick={() => setManual(true)}
                    >
                        {t('portal.sso.manual_toggle')}
                    </button>
                </>
            )}
        </div>
    );
}

function MetadataForm({ guide, connection }: { guide: Guide; connection: ConnectionRow }) {
    const form = useForm({ metadata: '' });
    const { t } = useTranslator();
    const kind = guide.returns.kind === 'oidc' ? 'url_or_xml' : guide.returns.kind;

    return (
        <form
            className="card p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(connection.urls.metadata, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <Field
                label={t('portal.sso.metadata_label')}
                hint={t(`portal.sso.returns.${kind}`, {
                    field: guide.returns.theirs,
                    provider: guide.name,
                })}
                error={form.errors.metadata}
            >
                <Textarea
                    name="metadata"
                    rows={kind === 'url' ? 2 : 5}
                    className="mono"
                    style={{ fontSize: '0.78rem' }}
                    placeholder={kind === 'xml' ? '<md:EntityDescriptor …>' : 'https://'}
                    value={form.data.metadata}
                    onChange={(event) => form.setData('metadata', event.target.value)}
                />
            </Field>
            <div className="mt-3">
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.sso.save_metadata')}
                </Button>
            </div>
        </form>
    );
}

/** The identity provider's values by hand. A secret left blank keeps the one saved. */
function ManualForm({ connection }: { connection: ConnectionRow }) {
    const saml = connection.protocol === 'saml';
    const pageErrors = usePage().props.errors;
    const form = useForm({
        idp_entity_id: connection.idp.idp_entity_id ?? '',
        idp_sso_url: connection.idp.idp_sso_url ?? '',
        idp_x509cert: '',
        issuer: connection.idp.issuer ?? '',
        client_id: connection.idp.client_id ?? '',
        client_secret: '',
        signing_key: '',
    });
    const { t } = useTranslator();
    const keepHint = connection.complete ? t('portal.sso.secret_keep') : undefined;

    return (
        <form
            className="card p-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.patch(connection.urls.update, { preserveScroll: true });
            }}
        >
            {saml ? (
                <>
                    <Field label={t('portal.sso.idp_entity_id')} error={form.errors.idp_entity_id}>
                        <Input
                            name="idp_entity_id"
                            className="mono"
                            value={form.data.idp_entity_id}
                            onChange={(event) => form.setData('idp_entity_id', event.target.value)}
                        />
                    </Field>
                    <Field label={t('portal.sso.idp_sso_url')} error={form.errors.idp_sso_url}>
                        <Input
                            name="idp_sso_url"
                            type="url"
                            className="mono"
                            value={form.data.idp_sso_url}
                            onChange={(event) => form.setData('idp_sso_url', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('portal.sso.idp_certificate')}
                        hint={connection.complete ? t('portal.sso.certificate_keep') : undefined}
                        error={form.errors.idp_x509cert}
                    >
                        <Textarea
                            name="idp_x509cert"
                            rows={4}
                            className="mono"
                            style={{ fontSize: '0.78rem' }}
                            placeholder="-----BEGIN CERTIFICATE-----"
                            value={form.data.idp_x509cert}
                            onChange={(event) => form.setData('idp_x509cert', event.target.value)}
                        />
                    </Field>
                </>
            ) : (
                <>
                    <Field label={t('portal.sso.issuer')} error={form.errors.issuer}>
                        <Input
                            name="issuer"
                            type="url"
                            className="mono"
                            placeholder="https://"
                            value={form.data.issuer}
                            onChange={(event) => form.setData('issuer', event.target.value)}
                        />
                    </Field>
                    <Field label={t('portal.sso.client_id')} error={form.errors.client_id}>
                        <Input
                            name="client_id"
                            className="mono"
                            value={form.data.client_id}
                            onChange={(event) => form.setData('client_id', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('portal.sso.client_secret')}
                        hint={keepHint}
                        error={form.errors.client_secret}
                    >
                        <Input
                            name="client_secret"
                            type="password"
                            autoComplete="off"
                            className="mono"
                            value={form.data.client_secret}
                            onChange={(event) => form.setData('client_secret', event.target.value)}
                        />
                    </Field>
                    <Field
                        label={t('portal.sso.signing_key')}
                        hint={
                            keepHint === undefined
                                ? t('portal.sso.signing_key_hint')
                                : `${t('portal.sso.signing_key_hint')} ${keepHint}`
                        }
                        error={form.errors.signing_key}
                    >
                        <Textarea
                            name="signing_key"
                            rows={3}
                            className="mono"
                            style={{ fontSize: '0.78rem' }}
                            value={form.data.signing_key}
                            onChange={(event) => form.setData('signing_key', event.target.value)}
                        />
                    </Field>
                </>
            )}
            <FormError message={typeof pageErrors.idp === 'string' ? pageErrors.idp : undefined} />
            <Button type="submit" variant="primary" loading={form.processing}>
                {t('portal.sso.save')}
            </Button>
        </form>
    );
}

function ActivateStep({ connection }: { connection: ConnectionRow }) {
    const { t } = useTranslator();
    const { errors } = usePage().props;
    const [busy, setBusy] = useState(false);
    const error = typeof errors.activate === 'string' ? errors.activate : undefined;

    if (connection.active) {
        return <Pill tone="success">{t('portal.sso.activated')}</Pill>;
    }

    return (
        <div>
            <p className="text-sm mb-3" style={{ color: 'var(--muted-foreground)' }}>
                {connection.complete
                    ? t('portal.sso.activate_lead')
                    : t('portal.sso.activate_incomplete')}
            </p>
            <Button
                variant="primary"
                disabled={!connection.complete}
                loading={busy}
                onClick={() =>
                    router.post(
                        connection.urls.activate,
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            >
                <Icon name="check" className="w-4 h-4" /> {t('portal.sso.activate')}
            </Button>
            <FormError message={error} />
        </div>
    );
}

function ConnectionList({ connections, href }: { connections: ConnectionRow[]; href: string }) {
    const { t } = useTranslator();

    return (
        <section className="mt-10 border-t pt-6" style={{ borderColor: 'var(--border)' }}>
            <h2 className="text-sm font-semibold mb-3">{t('portal.sso.connections_heading')}</h2>
            <ul className="space-y-2">
                {connections.map((connection) => {
                    const status = STATUSES[connection.status];

                    return (
                        <li
                            key={connection.id}
                            className="card p-3 flex flex-wrap items-center justify-between gap-2"
                        >
                            <span className="flex items-center gap-2 min-w-0">
                                <span className="font-medium truncate">{connection.name}</span>
                                <Pill dot={false}>{connection.protocol.toUpperCase()}</Pill>
                            </span>
                            {connection.active ? (
                                <Pill tone="success">{t('portal.sso.active')}</Pill>
                            ) : (
                                <Pill tone="warning">
                                    {status === undefined ? connection.status : t(status)}
                                </Pill>
                            )}
                        </li>
                    );
                })}
            </ul>
            <Link
                href={href}
                preserveScroll
                className="inline-block mt-3 text-xs"
                style={{ color: 'var(--accent)' }}
            >
                {t('portal.sso.set_up_another')}
            </Link>
        </section>
    );
}

PortalSso.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
