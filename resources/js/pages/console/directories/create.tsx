import { Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Field, Icon, Input, Panel, RadioGroup, Textarea } from '@/ui';

interface Credential {
    key: string;
    label: string;
    help: string;
    example: string;
}

interface ProviderOption {
    value: string;
    label: string;
    pull: boolean;
    setup: { steps: string[]; docs: string; credentials: Credential[] } | null;
}

type Props = PageProps<{
    providers: ProviderOption[];
    organizationChosen: boolean;
    entitled: boolean;
    indexHref: string;
    urls: { register: string; connect: string };
}>;

export default function CreateDirectory({
    providers,
    organizationChosen,
    entitled,
    indexHref,
    urls,
}: Props) {
    const form = useForm({
        provider: providers[0]?.value ?? 'scim',
        name: '',
        googleServiceAccountJson: '',
        googleAdminEmail: '',
        entraTenantId: '',
        entraClientId: '',
        entraClientSecret: '',
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

    const submit = (): void => {
        // Two different writes behind one button, because they are two different acts: one
        // MINTS a token we hand over, the other SEALS credentials we then use. The page
        // asks one question — which provider — and the answer decides which.
        form.post(pull ? urls.connect : urls.register);
    };

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
                Sync users in
            </Link>

            <h1 className="cbx-page-title mt-2">New directory</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Either point an identity provider at our SCIM endpoint, or connect Google Workspace
                or Microsoft Entra directly and we will pull your people on a schedule.
            </p>

            {!organizationChosen ? (
                // Not the upsell: this administrator holds every organization here and has
                // simply not said which one they are acting on.
                <div className="card mt-6" style={{ maxWidth: '36rem' }}>
                    <EmptyState
                        icon="layers"
                        title="Choose an organization"
                        description="A directory provisions one tenant's users, so there is nothing to connect it to yet. Pick the organization in the bar above."
                        actions={
                            <Button asChild>
                                <Link href={indexHref}>Back to Sync users in</Link>
                            </Button>
                        }
                    />
                </div>
            ) : !entitled ? (
                <div className="card mt-6" style={{ maxWidth: '36rem' }}>
                    <EmptyState
                        icon="directory"
                        title="Syncing users in is an Enterprise feature"
                        description="Contact your account team to enable it for this organization."
                        actions={
                            <Button asChild>
                                <Link href={indexHref}>Back to Sync users in</Link>
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
                    <Panel>
                        <RadioGroup
                            label="Provider"
                            value={form.data.provider}
                            onValueChange={(next) => form.setData('provider', next)}
                            options={providers.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.pull
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

                    {pull ? (
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
