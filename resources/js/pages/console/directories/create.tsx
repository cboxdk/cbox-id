import { useTranslator } from '@/i18n';
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
    /** The provider the link asked for (`?provider=`), preselected; null for the default. */
    selected: string | null;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    entitled: boolean;
    indexHref: string;
    urls: { register: string; connect: string; hris: string };
}>;

export default function CreateDirectory({
    providers,
    selected,
    organization,
    entitled,
    indexHref,
    urls,
}: Props) {
    const { t: tc } = useTranslator();

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
        provider: selected ?? providers[0]?.value ?? 'scim',
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
            <Breadcrumb href={indexHref} label={tc('console.directory_sync_ea18bba8')} />

            <h1 className="cbx-page-title mt-2">{tc('console.new_directory_000ddb55')}</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.point_an_identity_provider_at_our_scim_endpo_9f06ef10')}
            </p>

            {!entitled ? (
                <div className="card mt-6" style={{ maxWidth: '36rem' }}>
                    <EmptyState
                        icon="directory"
                        title={tc('console.syncing_users_in_is_an_enterprise_feature_c3b68cec')}
                        description={tc(
                            'console.contact_your_account_team_to_enable_it_for_t_c4ba4d29',
                        )}
                        actions={
                            <Button asChild>
                                <Link href={indexHref}>
                                    {tc('console.back_to_directory_sync_bb4632fa')}
                                </Link>
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
                                hint={tc(
                                    'console.a_directory_provisions_one_organization_s_pe_7b70fa4a',
                                )}
                            />
                        </Panel>
                    )}

                    <Panel>
                        <RadioGroup
                            label={tc('console.provider_472590ae')}
                            value={form.data.provider}
                            onValueChange={(next) => form.setData('provider', next)}
                            options={providers.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.hris
                                    ? tc(
                                          'console.your_hr_system_people_get_an_account_on_thei_6be42da6',
                                      )
                                    : option.pull
                                      ? tc(
                                            'console.we_fetch_your_people_from_them_on_a_schedule_01113643',
                                        )
                                      : tc(
                                            'console.your_provider_posts_changes_to_us_as_they_ha_727ce8fe',
                                        ),
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
                            title={tc('console.setting_up_value0_15873a1b', {
                                value0: provider.label,
                            })}
                            action={
                                <Button asChild size="sm" className="shrink-0">
                                    <a href={provider.setup.docs} target="_blank" rel="noreferrer">
                                        {tc('console.provider_guide_56aa8a4b')}
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
                            title={tc('console.credentials_d48a617c')}
                            description={tc(
                                'console.checked_against_your_hr_system_before_anythi_f01ca7fa',
                            )}
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
                                    label={tc('console.fields_to_pass_through_optional_f7728139')}
                                    hint={tc(
                                        'console.the_hr_system_s_own_field_names_to_copy_onto_2c74edb8',
                                    )}
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
                            title={tc('console.credentials_d48a617c')}
                            description={tc(
                                'console.verified_against_the_provider_before_anythin_0b07010b',
                            )}
                        >
                            <div className="space-y-4">
                                {form.data.provider === 'google_workspace' ? (
                                    <>
                                        <Field
                                            label={tc('console.service_account_json_key_5d7ca514')}
                                            hint={
                                                credentialHelp(provider, 'private_key') ??
                                                tc(
                                                    'console.the_whole_file_as_downloaded_it_must_contain_2e7fe164',
                                                )
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
                                            label={tc(
                                                'console.admin_email_to_impersonate_cdae519b',
                                            )}
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
                                            label={tc('console.tenant_id_de1f5fff')}
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
                                            label={tc('console.client_id_8726db01')}
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
                                            label={tc('console.client_secret_4aded5fa')}
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
                            title={tc('console.name_it_615f0f0b')}
                            description={tc(
                                'console.whatever_your_team_calls_this_provider_it_ap_aa44d5b2',
                            )}
                        >
                            <Field
                                label={tc('console.directory_name_96fff2c7')}
                                error={form.errors.name}
                            >
                                <Input
                                    name="name"
                                    placeholder={tc('console.okta_7006a191')}
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>
                        </Panel>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {pull
                                ? tc('console.verify_and_connect_1b825034')
                                : tc('console.register_directory_2558e9b5')}
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
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
