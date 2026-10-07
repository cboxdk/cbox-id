import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { type MessageKey, useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import {
    Button,
    ConfirmDelete,
    CopyButton,
    Field,
    Icon,
    Input,
    PageHeader,
    Pill,
    Select,
    Textarea,
} from '@/ui';

interface DomainRow {
    id: string;
    domain: string;
    verified: boolean;
    verifyHref: string;
    removeHref: string;
}

interface ConnectionRow {
    id: string;
    name: string;
    protocol: string;
    status: string;
    active: boolean;
    activateHref: string;
}

interface DirectoryRow {
    id: string;
    name: string;
    active: boolean;
}

/** The statuses a connection that is not active can show, in the visitor's language. */
const CONNECTION_STATUSES: Partial<Record<string, MessageKey>> = {
    draft: 'portal.setup.connection.statuses.draft',
    inactive: 'portal.setup.connection.statuses.inactive',
};

type Props = PageProps<{
    organizationName: string | null;
    showSso: boolean;
    showScim: boolean;
    domains: DomainRow[];
    connections: ConnectionRow[];
    directories: DirectoryRow[];
    scimBaseUrl: string;
    urls: {
        addDomain: string;
        createConnection: string;
        registerDirectory: string;
        finish: string;
    };
}>;

export default function PortalSetup({
    organizationName,
    showSso,
    showScim,
    domains,
    connections,
    directories,
    scimBaseUrl,
    urls,
}: Props) {
    const finish = useForm({});
    const { t } = useTranslator();

    return (
        <div>
            <PageHeader
                eyebrow={null}
                title={
                    organizationName === null
                        ? t('portal.setup.heading')
                        : t('portal.setup.heading_for', { organization: organizationName })
                }
                description={t('portal.setup.description')}
            />

            {showSso && (
                <>
                    <DomainStep domains={domains} href={urls.addDomain} />
                    <ConnectionStep connections={connections} href={urls.createConnection} />
                </>
            )}

            {showScim && (
                <DirectoryStep
                    directories={directories}
                    href={urls.registerDirectory}
                    scimBaseUrl={scimBaseUrl}
                    step={
                        showSso
                            ? t('portal.setup.step', { number: 3 })
                            : t('portal.setup.directory.step')
                    }
                />
            )}

            <div
                className="flex items-center justify-end gap-2 border-t pt-5"
                style={{ borderColor: 'var(--border)' }}
            >
                <Button
                    variant="primary"
                    loading={finish.processing}
                    onClick={() => finish.post(urls.finish)}
                >
                    <Icon name="check" className="w-4 h-4" /> {t('portal.setup.finish')}
                </Button>
            </div>
        </div>
    );
}

/** Step 1 — a verified domain is what routes a customer's users to their SSO connection. */
function DomainStep({ domains, href }: { domains: DomainRow[]; href: string }) {
    const form = useForm({ domain: '' });
    const dns = usePage().flash.dns;
    const [removing, setRemoving] = useState<DomainRow | null>(null);
    const { errors } = usePage().props;
    const verifyError = typeof errors.domain === 'string' ? errors.domain : null;
    const { t, rich } = useTranslator();
    const removeVerb = t('portal.setup.domain.remove');

    return (
        <section className="mb-8">
            <div className="mb-3">
                <p className="cbx-page-eyebrow">{t('portal.setup.step', { number: 1 })}</p>
                <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                    <Icon name="shield" className="w-4 h-4" /> {t('portal.setup.domain.heading')}
                </h2>
                <p className="text-xs mt-1" style={{ color: 'var(--muted)' }}>
                    {t('portal.setup.domain.lead')}
                </p>
            </div>

            <form
                className="flex gap-2 mb-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Input
                    name="domain"
                    placeholder="acme.com"
                    aria-label={t('portal.setup.domain.label')}
                    aria-invalid={form.errors.domain !== undefined || undefined}
                    value={form.data.domain}
                    onChange={(event) => form.setData('domain', event.target.value)}
                />
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.setup.domain.add')}
                </Button>
            </form>

            {(form.errors.domain ?? verifyError) !== undefined &&
                (form.errors.domain ?? verifyError) !== null && (
                    <p className="field-error -mt-2 mb-4" role="alert">
                        {form.errors.domain ?? verifyError}
                    </p>
                )}

            {/*
                THE CHALLENGE, ON THE FLASH CHANNEL — shown on the render that answered.
                It is re-issuable from the list, so it does not need to survive a Back.
            */}
            {dns !== undefined && (
                <div
                    className="card p-4 mb-4"
                    style={{ borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)' }}
                >
                    <p className="text-sm font-semibold">
                        {rich('portal.setup.domain.record', {
                            domain: <span className="mono">{dns.domain}</span>,
                        })}
                    </p>
                    <div
                        className="mt-3 grid gap-2 text-sm"
                        style={{ gridTemplateColumns: 'auto 1fr' }}
                    >
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            {t('portal.setup.domain.record_type')}
                        </span>
                        <span className="mono">TXT</span>
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            {t('portal.setup.domain.record_host')}
                        </span>
                        <span className="mono break-all select-all">{dns.host}</span>
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            {t('portal.setup.domain.record_value')}
                        </span>
                        <span className="mono break-all select-all">{dns.token}</span>
                    </div>
                </div>
            )}

            {domains.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.setup.domain.empty')}
                </p>
            ) : (
                domains.map((domain) => (
                    <div
                        key={domain.id}
                        className="card p-3 mb-2 flex items-center justify-between gap-3"
                    >
                        <span className="mono text-sm">{domain.domain}</span>
                        <div className="flex items-center gap-2">
                            {domain.verified ? (
                                <Pill tone="success">{t('portal.setup.domain.verified')}</Pill>
                            ) : (
                                <>
                                    <Pill tone="warning">{t('portal.setup.domain.pending')}</Pill>
                                    <Button
                                        size="sm"
                                        aria-label={t('portal.setup.domain.check_label', {
                                            domain: domain.domain,
                                        })}
                                        onClick={() =>
                                            router.post(
                                                domain.verifyHref,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('portal.setup.domain.check')}
                                    </Button>
                                </>
                            )}
                            <Button
                                size="sm"
                                variant="danger"
                                aria-label={t('portal.setup.domain.remove_label', {
                                    domain: domain.domain,
                                })}
                                onClick={() => setRemoving(domain)}
                            >
                                {removeVerb}
                            </Button>
                        </div>
                    </div>
                ))
            )}

            {/*
                Type-to-confirm: removing a verified domain stops routing everybody at it to
                this connection, and the person doing it is a third party who may not be the
                one who notices.
            */}
            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                verb={removeVerb}
                title={t('portal.setup.domain.remove_title', { domain: removing?.domain ?? '' })}
                consequence={t('portal.setup.domain.remove_consequence')}
                environment={null}
                cancelLabel={t('portal.confirm.cancel')}
                typeToConfirmLabel={rich('portal.confirm.type_to_confirm', {
                    name: <span className="mono">{removing?.domain ?? ''}</span>,
                })}
                hint={t('portal.confirm.hint', { action: removeVerb })}
                onConfirm={() => {
                    const domain = removing;
                    setRemoving(null);

                    if (domain !== null) {
                        router.delete(domain.removeHref, { preserveScroll: true });
                    }
                }}
            />
        </section>
    );
}

/** Step 2 — the connection itself, created as a draft. */
function ConnectionStep({ connections, href }: { connections: ConnectionRow[]; href: string }) {
    const [creating, setCreating] = useState(false);
    const { t } = useTranslator();

    /** A status this page has words for, else the status itself. */
    const connectionStatus = (status: string): string => {
        const key = CONNECTION_STATUSES[status];

        return key === undefined ? status : t(key);
    };

    return (
        <section className="mb-8">
            <div className="flex items-center justify-between gap-3 mb-3">
                <div>
                    <p className="cbx-page-eyebrow">{t('portal.setup.step', { number: 2 })}</p>
                    <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                        <Icon name="connections" className="w-4 h-4" />{' '}
                        {t('portal.setup.connection.heading')}
                    </h2>
                </div>
                <Button
                    variant="primary"
                    size="sm"
                    aria-expanded={creating}
                    onClick={() => setCreating((open) => !open)}
                >
                    <Icon name="plus" className="w-4 h-4" /> {t('portal.setup.connection.new')}
                </Button>
            </div>

            {creating && <ConnectionForm href={href} onDone={() => setCreating(false)} />}

            <div className="space-y-3">
                {connections.length === 0 ? (
                    <p className="text-sm px-1" style={{ color: 'var(--muted-foreground)' }}>
                        {t('portal.setup.connection.empty')}
                    </p>
                ) : (
                    connections.map((connection) => (
                        <div key={connection.id} className="card p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <p className="font-semibold truncate">{connection.name}</p>
                                        <Pill dot={false}>{connection.protocol}</Pill>
                                        {connection.active ? (
                                            <Pill tone="success">
                                                {t('portal.setup.connection.active')}
                                            </Pill>
                                        ) : (
                                            <Pill tone="warning">
                                                <span className="capitalize">
                                                    {connectionStatus(connection.status)}
                                                </span>
                                            </Pill>
                                        )}
                                    </div>
                                    <p
                                        className="mt-1 text-xs mono truncate"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {connection.id}
                                    </p>
                                </div>
                                {!connection.active && (
                                    <Button
                                        variant="primary"
                                        size="sm"
                                        aria-label={t('portal.setup.connection.activate_label', {
                                            name: connection.name,
                                        })}
                                        onClick={() =>
                                            router.post(
                                                connection.activateHref,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Icon name="check" className="w-4 h-4" />{' '}
                                        {t('portal.setup.connection.activate')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

function ConnectionForm({ href, onDone }: { href: string; onDone: () => void }) {
    const form = useForm({
        type: 'saml',
        connName: '',
        idp_entity_id: '',
        idp_sso_url: '',
        idp_x509cert: '',
        sp_entity_id: '',
        sp_acs_url: '',
        issuer: '',
        client_id: '',
        client_secret: '',
        signing_key: '',
    });

    const saml = form.data.type === 'saml';
    const { t } = useTranslator();

    return (
        <form
            className="card p-5 mb-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label={t('portal.setup.connection.name_label')} error={form.errors.connName}>
                    <Input
                        name="connName"
                        placeholder="Acme Okta"
                        value={form.data.connName}
                        onChange={(event) => form.setData('connName', event.target.value)}
                    />
                </Field>

                <Field label={t('portal.setup.connection.protocol_label')} error={form.errors.type}>
                    <Select
                        value={form.data.type}
                        onValueChange={(type) => form.setData('type', type)}
                        options={[
                            { value: 'saml', label: 'SAML 2.0' },
                            { value: 'oidc', label: 'OpenID Connect' },
                        ]}
                    />
                </Field>
            </div>

            {saml ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={t('portal.setup.connection.idp_entity_id')}
                            error={form.errors.idp_entity_id}
                        >
                            <Input
                                name="idp_entity_id"
                                className="mono"
                                placeholder="https://idp.example.com/metadata"
                                value={form.data.idp_entity_id}
                                onChange={(event) =>
                                    form.setData('idp_entity_id', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('portal.setup.connection.idp_sso_url')}
                            error={form.errors.idp_sso_url}
                        >
                            <Input
                                name="idp_sso_url"
                                type="url"
                                className="mono"
                                placeholder="https://idp.example.com/sso"
                                value={form.data.idp_sso_url}
                                onChange={(event) =>
                                    form.setData('idp_sso_url', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('portal.setup.connection.sp_entity_id')}
                            error={form.errors.sp_entity_id}
                        >
                            <Input
                                name="sp_entity_id"
                                className="mono"
                                placeholder="https://cbox-id/sp"
                                value={form.data.sp_entity_id}
                                onChange={(event) =>
                                    form.setData('sp_entity_id', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('portal.setup.connection.sp_acs_url')}
                            error={form.errors.sp_acs_url}
                        >
                            <Input
                                name="sp_acs_url"
                                type="url"
                                className="mono"
                                placeholder="https://cbox-id/sso/saml/…/acs"
                                value={form.data.sp_acs_url}
                                onChange={(event) => form.setData('sp_acs_url', event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field
                        label={t('portal.setup.connection.idp_certificate')}
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
                    <div className="grid gap-4 sm:grid-cols-2">
                        {/*
                            FOUR FIELDS, and the endpoints are DISCOVERED from the issuer's
                            own `.well-known` document. Asking an IT admin to copy four URLs
                            by hand is asking for a mistake that surfaces days later as a
                            sign-in that fails for everybody.
                        */}
                        <Field
                            label={t('portal.setup.connection.issuer')}
                            error={form.errors.issuer}
                        >
                            <Input
                                name="issuer"
                                type="url"
                                className="mono"
                                placeholder="https://idp.example.com"
                                value={form.data.issuer}
                                onChange={(event) => form.setData('issuer', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('portal.setup.connection.client_id')}
                            error={form.errors.client_id}
                        >
                            <Input
                                name="client_id"
                                className="mono"
                                placeholder="cbox-id-app"
                                value={form.data.client_id}
                                onChange={(event) => form.setData('client_id', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('portal.setup.connection.client_secret')}
                            error={form.errors.client_secret}
                        >
                            <Input
                                name="client_secret"
                                type="password"
                                autoComplete="off"
                                className="mono"
                                placeholder="••••••••"
                                value={form.data.client_secret}
                                onChange={(event) =>
                                    form.setData('client_secret', event.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <Field
                        label={t('portal.setup.connection.signing_key')}
                        error={form.errors.signing_key}
                    >
                        <Textarea
                            name="signing_key"
                            rows={4}
                            className="mono"
                            style={{ fontSize: '0.78rem' }}
                            placeholder="-----BEGIN PUBLIC KEY-----"
                            value={form.data.signing_key}
                            onChange={(event) => form.setData('signing_key', event.target.value)}
                        />
                    </Field>
                </>
            )}

            <div className="flex items-center gap-2">
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.setup.connection.create')}
                </Button>
                <Button type="button" onClick={onDone}>
                    {t('portal.setup.connection.cancel')}
                </Button>
            </div>
        </form>
    );
}

/** Step 3 — directory sync, and the one credential this page mints. */
function DirectoryStep({
    directories,
    href,
    scimBaseUrl,
    step,
}: {
    directories: DirectoryRow[];
    href: string;
    scimBaseUrl: string;
    step: string;
}) {
    const form = useForm({ dirName: '' });
    const [creating, setCreating] = useState(false);
    const { newToken, newTokenName } = usePage().flash;
    const { t } = useTranslator();

    return (
        <section className="mb-8">
            <div className="flex items-center justify-between gap-3 mb-3">
                <div>
                    <p className="cbx-page-eyebrow">{step}</p>
                    <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                        <Icon name="directory" className="w-4 h-4" />{' '}
                        {t('portal.setup.directory.heading')}
                    </h2>
                </div>
                <Button
                    variant="primary"
                    size="sm"
                    aria-expanded={creating}
                    onClick={() => setCreating((open) => !open)}
                >
                    <Icon name="plus" className="w-4 h-4" /> {t('portal.setup.directory.new')}
                </Button>
            </div>

            <div className="card p-4 mb-4">
                <p className="text-xs" style={{ color: 'var(--muted)' }}>
                    {t('portal.setup.directory.base_url_help')}
                </p>
                <div className="mt-2 flex items-center gap-2">
                    <p
                        className="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1 min-w-0"
                        style={{
                            background: 'var(--surface-2)',
                            border: '1px solid var(--border)',
                        }}
                    >
                        {scimBaseUrl}
                    </p>
                    <CopyButton
                        value={scimBaseUrl}
                        aria-label={t('portal.setup.directory.copy_base_url')}
                        label={t('portal.copy.copy')}
                        copiedLabel={t('portal.copy.copied')}
                        failedLabel={t('portal.copy.failed')}
                    />
                </div>
            </div>

            {/*
                THE BEARER TOKEN, SHOWN ONCE. It authenticates every inbound provisioning
                call for this organization — a credential, handed to a third party, so it
                travels the flash channel and never becomes a prop in their history.
            */}
            {typeof newToken === 'string' && (
                <div
                    className="card p-5 mb-4"
                    style={{ borderColor: 'color-mix(in srgb, var(--warning) 40%, transparent)' }}
                >
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="flex items-center gap-2 font-semibold">
                                <Icon name="key" className="w-4 h-4" />{' '}
                                {t('portal.setup.directory.token_heading', {
                                    name: newTokenName ?? '',
                                })}
                            </p>
                            <p className="mt-1 text-sm" style={{ color: 'var(--warning-strong)' }}>
                                {t('portal.setup.directory.token_once')}
                            </p>
                        </div>
                        <CopyButton
                            value={newToken}
                            label={t('portal.setup.directory.copy_token')}
                            copiedLabel={t('portal.copy.copied')}
                            failedLabel={t('portal.copy.failed')}
                        />
                    </div>
                    <p
                        className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                        style={{
                            background: 'var(--surface-2)',
                            border: '1px solid var(--border)',
                        }}
                    >
                        {newToken}
                    </p>
                </div>
            )}

            {creating && (
                <form
                    className="card p-4 mb-4 flex flex-wrap items-end gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(href, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                setCreating(false);
                            },
                        });
                    }}
                >
                    <div className="flex-1" style={{ minWidth: '14rem' }}>
                        <Field
                            label={t('portal.setup.directory.name_label')}
                            error={form.errors.dirName}
                        >
                            <Input
                                name="dirName"
                                placeholder="Acme Okta SCIM"
                                value={form.data.dirName}
                                onChange={(event) => form.setData('dirName', event.target.value)}
                            />
                        </Field>
                    </div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {t('portal.setup.directory.register')}
                    </Button>
                    <Button type="button" onClick={() => setCreating(false)}>
                        {t('portal.setup.directory.cancel')}
                    </Button>
                </form>
            )}

            <div className="space-y-3">
                {directories.length === 0 ? (
                    <p className="text-sm px-1" style={{ color: 'var(--muted-foreground)' }}>
                        {t('portal.setup.directory.empty')}
                    </p>
                ) : (
                    directories.map((directory) => (
                        <div
                            key={directory.id}
                            className="card p-4 flex items-center justify-between gap-3"
                        >
                            <div className="min-w-0">
                                <p className="font-medium truncate">{directory.name}</p>
                                <p
                                    className="text-xs mono truncate"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {directory.id}
                                </p>
                            </div>
                            {directory.active ? (
                                <Pill tone="success">{t('portal.setup.directory.active')}</Pill>
                            ) : (
                                <Pill tone="warning">{t('portal.setup.directory.paused')}</Pill>
                            )}
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

PortalSetup.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
