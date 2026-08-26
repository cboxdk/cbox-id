import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
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

    return (
        <div>
            <PageHeader
                eyebrow={null}
                title={`Set up enterprise sign-in${organizationName === null ? '' : ` · ${organizationName}`}`}
                description="You were invited to configure single sign-on for this organization. Nothing else on the account is accessible from here."
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
                    step={showSso ? 'Step 3' : 'Directory sync'}
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
                    <Icon name="check" className="w-4 h-4" /> Finish setup
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

    return (
        <section className="mb-8">
            <div className="mb-3">
                <p className="cbx-page-eyebrow">Step 1</p>
                <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                    <Icon name="shield" className="w-4 h-4" /> Verify your domain
                </h2>
                <p className="text-xs mt-1" style={{ color: 'var(--muted)' }}>
                    Add a DNS record to prove you own the domain your team signs in with. This is
                    what sends those users to SSO.
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
                    aria-label="Domain"
                    aria-invalid={form.errors.domain !== undefined || undefined}
                    value={form.data.domain}
                    onChange={(event) => form.setData('domain', event.target.value)}
                />
                <Button type="submit" variant="primary" loading={form.processing}>
                    Add domain
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
                        Add this TXT record for <span className="mono">{dns.domain}</span>, then
                        click Check.
                    </p>
                    <div
                        className="mt-3 grid gap-2 text-sm"
                        style={{ gridTemplateColumns: 'auto 1fr' }}
                    >
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            Type
                        </span>
                        <span className="mono">TXT</span>
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            Host
                        </span>
                        <span className="mono break-all select-all">{dns.host}</span>
                        <span className="text-xs" style={{ color: 'var(--muted)' }}>
                            Value
                        </span>
                        <span className="mono break-all select-all">{dns.token}</span>
                    </div>
                </div>
            )}

            {domains.length === 0 ? (
                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    No domains added yet.
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
                                <Pill tone="success">Verified</Pill>
                            ) : (
                                <>
                                    <Pill tone="warning">Pending DNS</Pill>
                                    <Button
                                        size="sm"
                                        aria-label={`Check DNS for ${domain.domain}`}
                                        onClick={() =>
                                            router.post(
                                                domain.verifyHref,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Check
                                    </Button>
                                </>
                            )}
                            <Button
                                size="sm"
                                variant="danger"
                                aria-label={`Remove ${domain.domain}`}
                                onClick={() => setRemoving(domain)}
                            >
                                Remove
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
                verb="Remove"
                consequence="Anyone signing in with an address at this domain stops being routed here."
                environment={null}
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

    return (
        <section className="mb-8">
            <div className="flex items-center justify-between gap-3 mb-3">
                <div>
                    <p className="cbx-page-eyebrow">Step 2</p>
                    <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                        <Icon name="connections" className="w-4 h-4" /> SSO connection
                    </h2>
                </div>
                <Button
                    variant="primary"
                    size="sm"
                    aria-expanded={creating}
                    onClick={() => setCreating((open) => !open)}
                >
                    <Icon name="plus" className="w-4 h-4" /> New connection
                </Button>
            </div>

            {creating && <ConnectionForm href={href} onDone={() => setCreating(false)} />}

            <div className="space-y-3">
                {connections.length === 0 ? (
                    <p className="text-sm px-1" style={{ color: 'var(--muted-foreground)' }}>
                        No SSO connections yet.
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
                                            <Pill tone="success">Active</Pill>
                                        ) : (
                                            <Pill tone="warning">
                                                <span className="capitalize">
                                                    {connection.status}
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
                                        aria-label={`Activate ${connection.name}`}
                                        onClick={() =>
                                            router.post(
                                                connection.activateHref,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Icon name="check" className="w-4 h-4" /> Activate
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

    return (
        <form
            className="card p-5 mb-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Connection name" error={form.errors.connName}>
                    <Input
                        name="connName"
                        placeholder="Acme Okta"
                        value={form.data.connName}
                        onChange={(event) => form.setData('connName', event.target.value)}
                    />
                </Field>

                <Field label="Protocol" error={form.errors.type}>
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
                        <Field label="IdP entity ID" error={form.errors.idp_entity_id}>
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
                        <Field label="IdP SSO URL" error={form.errors.idp_sso_url}>
                            <Input
                                name="idp_sso_url"
                                type="url"
                                className="mono"
                                placeholder="https://idp.example.com/sso"
                                value={form.data.idp_sso_url}
                                onChange={(event) => form.setData('idp_sso_url', event.target.value)}
                            />
                        </Field>
                        <Field label="SP entity ID" error={form.errors.sp_entity_id}>
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
                        <Field label="SP ACS URL" error={form.errors.sp_acs_url}>
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

                    <Field label="IdP X.509 certificate" error={form.errors.idp_x509cert}>
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
                        <Field label="Issuer" error={form.errors.issuer}>
                            <Input
                                name="issuer"
                                type="url"
                                className="mono"
                                placeholder="https://idp.example.com"
                                value={form.data.issuer}
                                onChange={(event) => form.setData('issuer', event.target.value)}
                            />
                        </Field>
                        <Field label="Client ID" error={form.errors.client_id}>
                            <Input
                                name="client_id"
                                className="mono"
                                placeholder="cbox-id-app"
                                value={form.data.client_id}
                                onChange={(event) => form.setData('client_id', event.target.value)}
                            />
                        </Field>
                        <Field label="Client secret" error={form.errors.client_secret}>
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

                    <Field label="Signing key" error={form.errors.signing_key}>
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
                    Create connection
                </Button>
                <Button type="button" onClick={onDone}>
                    Cancel
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

    return (
        <section className="mb-8">
            <div className="flex items-center justify-between gap-3 mb-3">
                <div>
                    <p className="cbx-page-eyebrow">{step}</p>
                    <h2 className="text-sm font-semibold flex items-center gap-2 mt-1">
                        <Icon name="directory" className="w-4 h-4" /> Directory sync (SCIM)
                    </h2>
                </div>
                <Button
                    variant="primary"
                    size="sm"
                    aria-expanded={creating}
                    onClick={() => setCreating((open) => !open)}
                >
                    <Icon name="plus" className="w-4 h-4" /> New directory
                </Button>
            </div>

            <div className="card p-4 mb-4">
                <p className="text-xs" style={{ color: 'var(--muted)' }}>
                    Point your identity provider (Okta, Microsoft Entra) at this base URL and
                    authenticate with a directory&rsquo;s bearer token.
                </p>
                <div className="mt-2 flex items-center gap-2">
                    <p
                        className="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1 min-w-0"
                        style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                    >
                        {scimBaseUrl}
                    </p>
                    <CopyButton value={scimBaseUrl} aria-label="Copy the SCIM base URL" />
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
                                <Icon name="key" className="w-4 h-4" /> Bearer token for &ldquo;
                                {newTokenName}&rdquo;
                            </p>
                            <p className="mt-1 text-sm" style={{ color: 'var(--warning-strong)' }}>
                                Copy this now — it is shown only once and cannot be retrieved
                                again.
                            </p>
                        </div>
                        <CopyButton value={newToken} label="Copy token" />
                    </div>
                    <p
                        className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                        style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
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
                        <Field label="Directory name" error={form.errors.dirName}>
                            <Input
                                name="dirName"
                                placeholder="Acme Okta SCIM"
                                value={form.data.dirName}
                                onChange={(event) => form.setData('dirName', event.target.value)}
                            />
                        </Field>
                    </div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Register directory
                    </Button>
                    <Button type="button" onClick={() => setCreating(false)}>
                        Cancel
                    </Button>
                </form>
            )}

            <div className="space-y-3">
                {directories.length === 0 ? (
                    <p className="text-sm px-1" style={{ color: 'var(--muted-foreground)' }}>
                        No directories connected yet.
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
                                <Pill tone="success">Active</Pill>
                            ) : (
                                <Pill tone="warning">Paused</Pill>
                            )}
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}

PortalSetup.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
