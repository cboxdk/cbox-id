import { Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    EmptyState,
    Field,
    Icon,
    Input,
    OrganizationPickerField,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

interface Credential {
    key: string;
    label: string;
    help: string;
    example: string;
    secret: boolean;
    required: boolean;
}

interface ProviderOption {
    value: string;
    label: string;
    pull: boolean;
    /** An HR system: its fields come from `setup.credentials`, and it posts to `urls.hris`. */
    hris: boolean;
    setup: {
        steps: string[];
        docs: string;
        incremental: boolean;
        credentials: Credential[];
    } | null;
}

type Props = PageProps<{
    providers: ProviderOption[];
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    entitled: boolean;
    indexHref: string;
    urls: { register: string; connect: string; hris: string };
}>;

export default function CreateDirectory({
    providers,
    organization,
    entitled,
    indexHref,
    urls,
}: Props) {
    const form = useForm<{
        provider: string;
        organization: string;
        name: string;
        googleServiceAccountJson: string;
        googleAdminEmail: string;
        entraTenantId: string;
        entraClientId: string;
        entraClientSecret: string;
        credentials: Record<string, string>;
        customAttributes: string;
    }>({
        provider: providers[0]?.value ?? 'scim',
        organization: organization?.selected?.id ?? '',
        name: '',
        googleServiceAccountJson: '',
        googleAdminEmail: '',
        entraTenantId: '',
        entraClientId: '',
        entraClientSecret: '',
        credentials: {},
        customAttributes: '',
    });

    /*
     * WHY THE CREDENTIALS WERE REFUSED is a refusal about the SET of them, not about one
     * box: Google's arrives as a pasted JSON key that has to contain two specific
     * properties. So the server reports it under a key no input owns, and the page reads
     * it from the shared bag rather than from the form's own field errors.
     */
    const credentialError = usePage().props.errors.credentials;

    const provider = useMemo(
        () => providers.find((candidate) => candidate.value === form.data.provider) ?? providers[0],
        [providers, form.data.provider],
    );

    const pull = provider?.pull === true;
    const hris = provider?.hris === true;

    const submit = (): void => {
        // Different writes behind one button, because they are different acts: one MINTS a
        // token we hand over, the others SEAL credentials we then use — an identity
        // directory's, or an HR system's. The page asks one question — which provider —
        // and the answer decides which.
        form.post(hris ? urls.hris : pull ? urls.connect : urls.register);
    };

    return (
        <>
            <Breadcrumb href={indexHref} label="Directory Sync" />

            <h1 className="cbx-page-title mt-2">New directory</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Point an identity provider at our SCIM endpoint, connect Google Workspace or
                Microsoft Entra, or connect your HR system — Workday, BambooHR, Rippling, HiBob or
                Personio — and we will pull your people on a schedule.
            </p>

            {!entitled ? (
                <div className="card mt-6" style={{ maxWidth: '36rem' }}>
                    <EmptyState
                        icon="directory"
                        title="Syncing users in is an Enterprise feature"
                        description="Contact your account team to enable it for this organization."
                        actions={
                            <Button asChild>
                                <Link href={indexHref}>Back to Directory Sync</Link>
                            </Button>
                        }
                    />
                </div>
            ) : (
                <form
                    className="mt-6 space-y-6"
                    style={{ maxWidth: '40rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    {organization !== null && (
                        <Panel>
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint="A directory provisions one organization's people."
                            />
                        </Panel>
                    )}

                    <Panel>
                        <RadioGroup
                            label="Provider"
                            value={form.data.provider}
                            onValueChange={(next) => form.setData('provider', next)}
                            options={providers.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.hris
                                    ? 'Your HR system: people get an account on their start date and lose it after their last day.'
                                    : option.pull
                                      ? 'We fetch your people from them on a schedule.'
                                      : 'Your provider posts changes to us as they happen.',
                            }))}
                        />
                        {form.errors.provider !== undefined && (
                            <p className="field-error" role="alert">
                                {form.errors.provider}
                            </p>
                        )}
                    </Panel>

                    {/*
                        The provider's own steps, from the catalogue the connector is
                        checked against. This page used to have nothing to say: the guide
                        for connecting Google as a DIRECTORY existed beside the one for
                        connecting Google for SIGN-IN, and the screen could reach neither —
                        so somebody who had just done the sign-in half got an empty
                        credential box and no hint that a directory wants a service account
                        rather than the OAuth client in front of them.
                    */}
                    {provider?.setup != null && (
                        <Panel
                            title={`Setting up ${provider.label}`}
                            action={
                                <Button asChild size="sm" className="shrink-0">
                                    <a href={provider.setup.docs} target="_blank" rel="noreferrer">
                                        Provider guide
                                        <Icon name="external" className="w-3.5 h-3.5" />
                                    </a>
                                </Button>
                            }
                        >
                            <ol
                                className="space-y-1.5 text-sm"
                                style={{ listStyle: 'decimal outside', paddingLeft: '1.25rem' }}
                            >
                                {provider.setup.steps.map((step) => (
                                    <li key={step}>{step}</li>
                                ))}
                            </ol>
                        </Panel>
                    )}

                    {hris && provider?.setup != null ? (
                        <Panel
                            title="Credentials"
                            description="Checked against your HR system before anything is stored. Never shown again."
                        >
                            <div className="space-y-4">
                                {provider.setup.credentials.map((credential, index) => (
                                    <Field
                                        key={credential.key}
                                        label={
                                            credential.label +
                                            (credential.required ? '' : ' (optional)')
                                        }
                                        hint={credential.help}
                                        error={index === 0 ? credentialError : undefined}
                                    >
                                        <Input
                                            name={`credentials.${credential.key}`}
                                            type={credential.secret ? 'password' : 'text'}
                                            className="mono"
                                            autoComplete="off"
                                            placeholder={credential.example}
                                            value={form.data.credentials[credential.key] ?? ''}
                                            onChange={(event) =>
                                                form.setData('credentials', {
                                                    ...form.data.credentials,
                                                    [credential.key]: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                ))}

                                <Field
                                    label="Fields to pass through (optional)"
                                    hint="The HR system's own field names to copy onto each person, one per line — a cost centre, a location."
                                    error={form.errors.customAttributes}
                                >
                                    <Textarea
                                        name="customAttributes"
                                        rows={2}
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.customAttributes}
                                        onChange={(event) =>
                                            form.setData('customAttributes', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </Panel>
                    ) : pull ? (
                        <Panel
                            title="Credentials"
                            description="Verified against the provider before anything is stored."
                        >
                            <div className="space-y-4">
                                {form.data.provider === 'google_workspace' ? (
                                    <>
                                        <Field
                                            label="Service-account JSON key"
                                            hint={
                                                credentialHelp(provider, 'private_key') ??
                                                'The whole file, as downloaded — it must contain client_email and private_key.'
                                            }
                                            error={credentialError}
                                        >
                                            <Textarea
                                                name="googleServiceAccountJson"
                                                rows={5}
                                                className="mono"
                                                spellCheck={false}
                                                value={form.data.googleServiceAccountJson}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'googleServiceAccountJson',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>

                                        <Field
                                            label="Admin email to impersonate"
                                            hint={credentialHelp(provider, 'admin_email')}
                                        >
                                            <Input
                                                name="googleAdminEmail"
                                                type="email"
                                                className="mono"
                                                autoComplete="off"
                                                value={form.data.googleAdminEmail}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'googleAdminEmail',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </>
                                ) : (
                                    <>
                                        <Field
                                            label="Tenant ID"
                                            hint={credentialHelp(provider, 'tenant_id')}
                                            error={credentialError}
                                        >
                                            <Input
                                                name="entraTenantId"
                                                className="mono"
                                                autoComplete="off"
                                                value={form.data.entraTenantId}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'entraTenantId',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>

                                        <Field
                                            label="Client ID"
                                            hint={credentialHelp(provider, 'client_id')}
                                        >
                                            <Input
                                                name="entraClientId"
                                                className="mono"
                                                autoComplete="off"
                                                value={form.data.entraClientId}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'entraClientId',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>

                                        <Field
                                            label="Client secret"
                                            hint={credentialHelp(provider, 'client_secret')}
                                        >
                                            <Input
                                                name="entraClientSecret"
                                                type="password"
                                                className="mono"
                                                autoComplete="off"
                                                value={form.data.entraClientSecret}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'entraClientSecret',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </Field>
                                    </>
                                )}
                            </div>
                        </Panel>
                    ) : (
                        <Panel
                            title="Name it"
                            description="Whatever your team calls this provider — it appears on the list and in the audit trail."
                        >
                            <Field label="Directory name" error={form.errors.name}>
                                <Input
                                    name="name"
                                    placeholder="Okta"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>
                        </Panel>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {pull ? 'Verify and connect' : 'Register directory'}
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

/** One credential field's help text, from the connector's own declaration. */
function credentialHelp(provider: ProviderOption | undefined, key: string): string | undefined {
    return provider?.setup?.credentials.find((credential) => credential.key === key)?.help;
}

CreateDirectory.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
