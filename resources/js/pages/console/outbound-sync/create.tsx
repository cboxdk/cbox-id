import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    Field,
    Input,
    OrganizationPickerField,
    PageHeader,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

interface SchemeOption {
    value: string;
    label: string;
    hint: string;
}

type Props = PageProps<{
    schemes: SchemeOption[];
    mayScopeEnvironmentWide: boolean;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateOutboundSync({
    schemes,
    mayScopeEnvironmentWide,
    organization,
    indexHref,
    storeHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({
        name: '',
        baseUrl: '',
        scheme: schemes[0]?.value ?? 'bearer',
        secret: '',
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
        tokenUrl: '',
        clientId: '',
        scope: '',
    });

    // OAuth needs a token endpoint and a client id; a bearer connection does not, and
    // asking for them anyway is how a form teaches people to fill in fields that mean
    // nothing. The server requires them for the same scheme and only that one.
    const usesClientCredentials = form.data.scheme === 'oauth2_client_credentials';

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.outbound_provisioning_e49a114e')} />

            <div className="mt-2">
                <PageHeader
                    description={tc(
                        'console.a_scim_endpoint_we_push_your_people_to_joins_629f70bd',
                    )}
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '40rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { onFinish: () => form.setData('secret', '') });
                }}
            >
                <Panel title={tc('console.where_it_goes_4d1ca74f')}>
                    <div className="space-y-4">
                        <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder={tc('console.datadog_8bd7b9fd')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={tc('console.scim_base_url_be8fb61f')}
                            hint={tc(
                                'console.the_endpoint_the_app_documents_for_scim_usua_75ad1a23',
                            )}
                            error={form.errors.baseUrl}
                        >
                            <Input
                                name="baseUrl"
                                type="url"
                                className="mono"
                                placeholder="https://app.example.com/scim/v2"
                                value={form.data.baseUrl}
                                onChange={(event) => form.setData('baseUrl', event.target.value)}
                            />
                        </Field>

                        {mayScopeEnvironmentWide && (
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label={tc(
                                    'console.send_every_organization_in_this_environment_ca82c33e',
                                )}
                                hint={tc(
                                    'console.every_organization_s_people_are_pushed_to_th_27734b94',
                                )}
                            />
                        )}

                        {organization !== null && !form.data.environmentWide && (
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint={tc(
                                    'console.whose_people_are_pushed_or_tick_the_box_abov_bb15010f',
                                )}
                            />
                        )}
                    </div>
                </Panel>

                <Panel
                    title={tc('console.how_it_authenticates_3288609b')}
                    description={tc('console.what_we_present_to_the_app_on_every_push_e608e295')}
                >
                    <div className="space-y-4">
                        <RadioGroup
                            label={tc('console.auth_scheme_4a894695')}
                            value={form.data.scheme}
                            onValueChange={(scheme) => form.setData('scheme', scheme)}
                            options={schemes.map((scheme) => ({
                                value: scheme.value,
                                label: scheme.label,
                                hint: scheme.hint,
                            }))}
                        />

                        <Field
                            label={
                                usesClientCredentials
                                    ? tc('console.client_secret_4aded5fa')
                                    : tc('console.bearer_token_b22ac30e')
                            }
                            hint={tc('console.sealed_at_rest_and_never_shown_again_42b0dd3e')}
                            error={form.errors.secret}
                        >
                            <Textarea
                                name="secret"
                                rows={2}
                                className="mono"
                                spellCheck={false}
                                autoComplete="off"
                                value={form.data.secret}
                                onChange={(event) => form.setData('secret', event.target.value)}
                            />
                        </Field>

                        {usesClientCredentials && (
                            <>
                                <Field
                                    label={tc('console.token_url_431e0036')}
                                    hint={tc(
                                        'console.where_we_exchange_the_client_credentials_for_2afa7c75',
                                    )}
                                    error={form.errors.tokenUrl}
                                >
                                    <Input
                                        name="tokenUrl"
                                        type="url"
                                        className="mono"
                                        placeholder="https://app.example.com/oauth/token"
                                        value={form.data.tokenUrl}
                                        onChange={(event) =>
                                            form.setData('tokenUrl', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.client_id_8726db01')}
                                    error={form.errors.clientId}
                                >
                                    <Input
                                        name="clientId"
                                        className="mono"
                                        value={form.data.clientId}
                                        onChange={(event) =>
                                            form.setData('clientId', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.scope_b073f6c6')}
                                    optional
                                    error={form.errors.scope}
                                >
                                    <Input
                                        name="scope"
                                        className="mono"
                                        placeholder={tc('console.scim_write_117daa0d')}
                                        value={form.data.scope}
                                        onChange={(event) =>
                                            form.setData('scope', event.target.value)
                                        }
                                    />
                                </Field>
                            </>
                        )}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.register_connection_4cedd83e')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateOutboundSync.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
