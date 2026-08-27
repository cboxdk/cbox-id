import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
    Textarea,
} from '@/ui';

type Props = PageProps<{
    connection: {
        id: string;
        name: string;
        type: 'saml' | 'oidc';
        typeLabel: string;
        active: boolean;
        status: string;
        environmentOwned: boolean;
        config: {
            idp_entity_id: string;
            idp_sso_url: string;
            sp_entity_id: string;
            sp_acs_url: string;
            issuer: string;
            client_id: string;
        };
    };
    organizationName: string | null;
    organizationHref: string | null;
    /** The connection was just activated and passwords still work — so the offer stands. */
    offeringMandate: boolean;
    passwordsStillAllowed: boolean;
    entitled: boolean;
    indexHref: string;
    urls: {
        update: string;
        activate: string;
        disable: string;
        requireSso: string;
        destroy: string;
    };
}>;

export default function ConnectionDetail({
    connection,
    organizationName,
    organizationHref,
    offeringMandate,
    passwordsStillAllowed,
    entitled,
    indexHref,
    urls,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    // The offer is dismissible without a round trip: declining changes nothing on the
    // server, so asking it to remember a "no" would be storing a non-decision.
    const [offerDeclined, setOfferDeclined] = useState(false);

    const saml = connection.type === 'saml';

    /*
     * SECRETS START EMPTY, always. A sealed certificate, signing key or client secret is
     * never sent to the browser — the server carries the stored value through when the
     * field arrives blank, which is what makes "leave it alone" expressible at all.
     */
    const form = useForm({
        name: connection.name,
        idp_entity_id: connection.config.idp_entity_id,
        idp_sso_url: connection.config.idp_sso_url,
        idp_x509cert: '',
        sp_entity_id: connection.config.sp_entity_id,
        sp_acs_url: connection.config.sp_acs_url,
        issuer: connection.config.issuer,
        client_id: connection.config.client_id,
        client_secret: '',
        signing_key: '',
    });

    return (
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
                    Single sign-on
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{connection.name}</h1>
                    <Pill tone="info">{connection.typeLabel}</Pill>
                    <Badge tone={connection.active ? 'success' : 'neutral'}>
                        {connection.active ? 'Active' : connection.status}
                    </Badge>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {connection.id}
                </p>
            </div>

            <Panel title="Organization">
                {connection.environmentOwned ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This connection belongs to the environment itself — it signs people in and
                        enrols them in no organization.
                    </p>
                ) : organizationHref !== null && organizationName !== null ? (
                    <a
                        href={organizationHref}
                        className="text-sm font-medium"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {organizationName}
                    </a>
                ) : (
                    <p className="text-sm font-medium">{organizationName}</p>
                )}
            </Panel>

            {!entitled ? (
                <div className="card">
                    <EmptyState
                        icon="connections"
                        title="Single sign-on is an Enterprise feature"
                        description="This connection can't be changed while the organization is off the Enterprise plan. Contact your account team to enable it."
                    />
                </div>
            ) : (
                <>
                    {/*
                        THE MANDATE, OFFERED AT THE MOMENT IT MAKES SENSE TO ASK.

                        This is the only place in the console that asks. An administrator
                        who has just connected an identity provider has, by that act, said
                        what they want sign-in to be — anywhere else the question is a
                        setting they have no reason to go looking for. It is an OFFER: the
                        consequences are on screen before the click, because the click ends
                        every password session in the organization and one of them is
                        probably theirs.
                    */}
                    {offeringMandate && !offerDeclined ? (
                        <div
                            role="alert"
                            className="rounded-xl border p-5"
                            style={{
                                borderColor: 'var(--accent)',
                                background: 'var(--accent-soft)',
                            }}
                        >
                            <h2 className="text-base font-semibold">
                                Make {connection.name} the only way in?
                            </h2>
                            <p
                                className="mt-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {organizationName ?? 'This organization'} can sign in with passwords
                                or with this connection. Requiring single sign-on refuses every
                                other way in, so nothing already issued can go around the provider
                                you just set up. Most companies that connect an IdP want this — but
                                read what it does first.
                            </p>
                            <ul
                                className="mt-3 space-y-1 text-sm list-disc pl-5"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <li>
                                    Passwords, emailed sign-in links, passkeys and social buttons
                                    all stop working for everyone in this organization, immediately.
                                </li>
                                <li>
                                    Every session in this organization ends —{' '}
                                    <strong>including yours</strong>.
                                </li>
                                <li>
                                    You can turn it off again on Sign-in rules; sessions that ended
                                    stay ended.
                                </li>
                            </ul>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                    variant="primary"
                                    onClick={() => router.post(urls.requireSso)}
                                >
                                    Require single sign-on
                                </Button>
                                <Button onClick={() => setOfferDeclined(true)}>
                                    Not now — keep passwords working
                                </Button>
                            </div>
                        </div>
                    ) : (
                        connection.active &&
                        !passwordsStillAllowed && (
                            /*
                                The other half of the same fact, and it belongs here rather
                                than only on the rules page: somebody debugging "why can
                                nobody sign in with a password" is looking at the
                                connection, not at a settings page they may not know exists.
                            */
                            <div
                                className="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3"
                                style={{
                                    borderColor: 'var(--border)',
                                    background: 'var(--surface-2)',
                                }}
                            >
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    <strong style={{ color: 'var(--foreground)' }}>
                                        Single sign-on is required
                                    </strong>{' '}
                                    for {organizationName ?? 'this organization'} — every other way
                                    in is refused.
                                </p>
                            </div>
                        )
                    )}

                    <Panel title="Configuration">
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field label="Connection name" error={form.errors.name}>
                                <Input
                                    name="name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            {saml ? (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            label="IdP entity ID"
                                            error={form.errors.idp_entity_id}
                                        >
                                            <Input
                                                name="idp_entity_id"
                                                className="mono"
                                                spellCheck={false}
                                                value={form.data.idp_entity_id}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'idp_entity_id',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>

                                        <Field label="IdP SSO URL" error={form.errors.idp_sso_url}>
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
                                            label="SP entity ID"
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

                                        <Field label="SP ACS URL" error={form.errors.sp_acs_url}>
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

                                    <SecretField
                                        label="IdP signing certificate"
                                        name="idp_x509cert"
                                        rows={4}
                                        value={form.data.idp_x509cert}
                                        error={form.errors.idp_x509cert}
                                        onChange={(value) => form.setData('idp_x509cert', value)}
                                    />
                                </>
                            ) : (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field label="Issuer" error={form.errors.issuer}>
                                            <Input
                                                name="issuer"
                                                type="url"
                                                className="mono"
                                                spellCheck={false}
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
                                    </div>

                                    <SecretField
                                        label="Client secret"
                                        name="client_secret"
                                        value={form.data.client_secret}
                                        error={form.errors.client_secret}
                                        onChange={(value) => form.setData('client_secret', value)}
                                    />

                                    <SecretField
                                        label="Signing key"
                                        name="signing_key"
                                        rows={3}
                                        value={form.data.signing_key}
                                        error={form.errors.signing_key}
                                        onChange={(value) => form.setData('signing_key', value)}
                                    />
                                </>
                            )}

                            <Button type="submit" variant="primary" loading={form.processing}>
                                Save changes
                            </Button>
                        </form>
                    </Panel>

                    <Panel
                        title={connection.active ? 'Disable connection' : 'Activate connection'}
                        description={
                            connection.active
                                ? 'People who sign in through this provider stop being able to. Anyone with a password here can still get in — unless single sign-on is required.'
                                : 'Nothing changes for anybody until this is on. Test a sign-in first.'
                        }
                    >
                        {connection.active ? (
                            <Button size="sm" onClick={() => router.post(urls.disable)}>
                                Disable
                            </Button>
                        ) : (
                            <Button
                                size="sm"
                                variant="primary"
                                onClick={() => router.post(urls.activate)}
                            >
                                Activate
                            </Button>
                        )}
                    </Panel>

                    <Panel
                        title="Delete connection"
                        description="People who rely on this provider lose their way in. This cannot be undone."
                    >
                        <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                            Delete connection
                        </Button>
                    </Panel>
                </>
            )}

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={connection.name}
                consequence="Anybody who signs in through this provider loses their way in immediately. This cannot be undone."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

/**
 * A field whose stored value the server will not show.
 *
 * Blank means KEEP, not clear — the sealed secret is never returned to the browser, so an
 * empty field is the only way "leave it alone" can be expressed. The hint says so, because
 * an empty box under a label that names a certificate reads as a missing certificate.
 */
function SecretField({
    label,
    name,
    rows,
    value,
    error,
    onChange,
}: {
    label: string;
    name: string;
    rows?: number;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field
            label={label}
            hint="Stored sealed and never shown again. Leave it empty to keep the current one."
            error={error}
        >
            {rows === undefined ? (
                <Input
                    name={name}
                    type="password"
                    className="mono"
                    autoComplete="off"
                    placeholder="Unchanged"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            ) : (
                <Textarea
                    name={name}
                    rows={rows}
                    className="mono"
                    spellCheck={false}
                    placeholder="Unchanged"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                />
            )}
        </Field>
    );
}

ConnectionDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
