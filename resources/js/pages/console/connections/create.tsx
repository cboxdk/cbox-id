import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Checkbox,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

type Props = PageProps<{
    /** An environment administrator has not chosen an organization yet. */
    needsOrganization: boolean;
    entitled: boolean;
    mayScopeEnvironmentWide: boolean;
    indexHref: string;
    storeHref: string;
    importHref: string;
}>;

export default function CreateConnection({
    needsOrganization,
    entitled,
    mayScopeEnvironmentWide,
    indexHref,
    storeHref,
    importHref,
}: Props) {
    // The importer's answer, on the flash channel: it is the result of one action and it
    // fills the form rather than describing the page.
    const imported = usePage().flash.metadata;

    const form = useForm({
        name: '',
        type: 'saml',
        environmentWide: false,
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

    const metadata = useForm({ metadata: '' });

    // The import fills the SAML half and switches to it: a person who pasted an IdP's
    // metadata has answered "which kind" more clearly than the radio could.
    useEffect(() => {
        if (imported === undefined) {
            return;
        }

        form.setData((current) => ({
            ...current,
            type: 'saml',
            idp_entity_id: imported.idp_entity_id,
            idp_sso_url: imported.idp_sso_url,
            idp_x509cert: imported.idp_x509cert,
        }));
        // The flash is one-shot, so this runs once per import and never again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [imported]);

    const saml = form.data.type === 'saml';

    // The environment may own a connection itself, but an ORGANIZATION-owned one needs an
    // organization to own it — and on this plane one has to be chosen first.
    const blocked = needsOrganization && !form.data.environmentWide;

    return (
        <>
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
                Single sign-on
            </Link>

            <h1 className="cbx-page-title mt-2">New connection</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Point Cbox ID at the identity provider your company already uses. It starts as a
                draft — nothing changes for anybody until you activate it.
            </p>

            {!needsOrganization && !entitled ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="connections"
                        title="Single sign-on is an Enterprise feature"
                        description="Contact your account team to enable it for this organization."
                    />
                </div>
            ) : (
                <form
                    className="mt-6 space-y-6"
                    style={{ maxWidth: '42rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(storeHref);
                    }}
                >
                    <Panel>
                        <Field
                            label="Name"
                            hint="What your team will see on the sign-in page."
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder="Acme Entra ID"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <div className="mt-5">
                            <RadioGroup
                                label="Protocol"
                                value={form.data.type}
                                onValueChange={(type) => form.setData('type', type)}
                                options={[
                                    {
                                        value: 'saml',
                                        label: 'SAML 2.0',
                                        hint: 'Entra ID, Okta, ADFS, most enterprise IdPs.',
                                    },
                                    {
                                        value: 'oidc',
                                        label: 'OpenID Connect',
                                        hint: 'Google Workspace, Auth0, anything OIDC-native.',
                                    },
                                ]}
                            />
                        </div>

                        {mayScopeEnvironmentWide && (
                            <div className="mt-5">
                                <Checkbox
                                    checked={form.data.environmentWide}
                                    onCheckedChange={(checked) =>
                                        form.setData('environmentWide', checked)
                                    }
                                    label="Owned by this environment rather than by an organization"
                                    hint="For an environment that does not use organizations — it signs people in and enrols them nowhere."
                                />
                            </div>
                        )}

                        {blocked && (
                            <p className="mt-4 text-sm" style={{ color: 'var(--warning-strong)' }}>
                                Choose an organization in the bar at the top of the page, or make
                                this the environment's own connection.
                            </p>
                        )}
                    </Panel>

                    {saml && (
                        <Panel
                            title="Import from metadata"
                            description="Paste the provider's metadata XML, or its metadata URL, and the fields below fill themselves."
                        >
                            <Field label="Metadata" error={metadata.errors.metadata}>
                                <Textarea
                                    name="metadata"
                                    rows={3}
                                    className="mono"
                                    spellCheck={false}
                                    placeholder="https://login.microsoftonline.com/…/federationmetadata.xml"
                                    value={metadata.data.metadata}
                                    onChange={(event) =>
                                        metadata.setData('metadata', event.target.value)
                                    }
                                />
                            </Field>

                            <Button
                                type="button"
                                className="mt-3"
                                loading={metadata.processing}
                                onClick={() => metadata.post(importHref, { preserveScroll: true })}
                            >
                                Import
                            </Button>
                        </Panel>
                    )}

                    {saml ? (
                        <Panel title="SAML configuration">
                            <div className="space-y-4">
                                <Field label="IdP entity ID" error={form.errors.idp_entity_id}>
                                    <Input
                                        name="idp_entity_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_entity_id}
                                        onChange={(event) =>
                                            form.setData('idp_entity_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field label="IdP sign-on URL" error={form.errors.idp_sso_url}>
                                    <Input
                                        name="idp_sso_url"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_sso_url}
                                        onChange={(event) =>
                                            form.setData('idp_sso_url', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label="IdP signing certificate"
                                    hint="The X.509 certificate, PEM or base64."
                                    error={form.errors.idp_x509cert}
                                >
                                    <Textarea
                                        name="idp_x509cert"
                                        rows={4}
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_x509cert}
                                        onChange={(event) =>
                                            form.setData('idp_x509cert', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label="Service provider entity ID"
                                    error={form.errors.sp_entity_id}
                                >
                                    <Input
                                        name="sp_entity_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.sp_entity_id}
                                        onChange={(event) =>
                                            form.setData('sp_entity_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label="Assertion consumer service URL"
                                    hint="Where the provider posts its assertion — give this to whoever configures the IdP."
                                    error={form.errors.sp_acs_url}
                                >
                                    <Input
                                        name="sp_acs_url"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.sp_acs_url}
                                        onChange={(event) =>
                                            form.setData('sp_acs_url', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </Panel>
                    ) : (
                        <Panel
                            title="OpenID Connect configuration"
                            description="The authorization and token endpoints are discovered from the issuer, so there is nothing else to paste."
                        >
                            <div className="space-y-4">
                                <Field label="Issuer" error={form.errors.issuer}>
                                    <Input
                                        name="issuer"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        placeholder="https://accounts.google.com"
                                        value={form.data.issuer}
                                        onChange={(event) =>
                                            form.setData('issuer', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field label="Client ID" error={form.errors.client_id}>
                                    <Input
                                        name="client_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.client_id}
                                        onChange={(event) =>
                                            form.setData('client_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field label="Client secret" error={form.errors.client_secret}>
                                    <Input
                                        name="client_secret"
                                        type="password"
                                        className="mono"
                                        autoComplete="off"
                                        value={form.data.client_secret}
                                        onChange={(event) =>
                                            form.setData('client_secret', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label="Signing key"
                                    hint="The key Cbox ID uses to sign its own requests to this provider."
                                    error={form.errors.signing_key}
                                >
                                    <Textarea
                                        name="signing_key"
                                        rows={3}
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.signing_key}
                                        onChange={(event) =>
                                            form.setData('signing_key', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </Panel>
                    )}

                    <div className="flex items-center gap-2">
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={blocked}
                            loading={form.processing}
                        >
                            Create connection
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            )}
        </>
    );
}

CreateConnection.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
